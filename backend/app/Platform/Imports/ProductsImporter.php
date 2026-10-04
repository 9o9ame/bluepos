<?php

namespace App\Platform\Imports;

use App\Actions\Inventory\CreateOpeningBalanceAction;
use App\Actions\Inventory\PostOpeningBalanceAction;
use App\Actions\Inventory\PostStockMovementAction;
use App\Actions\Inventory\UpsertOpeningBalanceLineAction;
use App\Actions\Products\CreateProductAction;
use App\Actions\Products\SyncProductBarcodesAction;
use App\Actions\Products\SyncProductPricesAction;
use App\Actions\Products\SyncProductPrimarySupplierAction;
use App\Catalog\TenantCatalog;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Platform\PlatformUser;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Platform\PlatformAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductsImporter
{
    private array $barcodes;

    private array $skus;

    private array $categories;

    private array $movementProducts;

    private ?Unit $pcs;

    private bool $settingsReady;

    private CreateProductAction $create;

    private SyncProductPricesAction $prices;

    private CreateOpeningBalanceAction $opening;

    private UpsertOpeningBalanceLineAction $line;

    private PostOpeningBalanceAction $post;

    public function __construct(private readonly Tenant $tenant, private readonly ?Warehouse $warehouse, private readonly array $sourceRows, PlatformUser $actor)
    {
        $ctx = new ImportTenantContext($tenant);
        $catalog = new TenantCatalog($ctx);
        $audit = new ImportAuditLogger($tenant, $actor, app(PlatformAuditLogger::class));
        $this->prices = new SyncProductPricesAction($ctx);
        $this->create = new CreateProductAction($ctx, $catalog, new SyncProductBarcodesAction($ctx, $catalog),
            $this->prices, new SyncProductPrimarySupplierAction($catalog, $ctx));
        $this->opening = new CreateOpeningBalanceAction($ctx, $catalog, $audit);
        $this->line = new UpsertOpeningBalanceLineAction($ctx, $catalog, $audit);
        $this->post = new PostOpeningBalanceAction($ctx, new PostStockMovementAction($ctx), $audit);
        $this->reload();
    }

    public function reload(): void
    {
        $id = $this->tenant->id;
        $this->settingsReady = BusinessSetting::query()->forTenant($id)->exists();
        $codes = array_unique(array_column($this->sourceRows, 'code'));
        $this->barcodes = ProductBarcode::query()->forTenant($id)->whereIn('barcode', $codes)->get()->keyBy('barcode')->all();
        $this->skus = Product::query()->forTenant($id)->whereIn('sku', $codes)->get()->groupBy('sku')->all();
        // Category masters are small; preload once per bounded request.
        $this->categories = Category::query()->forTenant($id)->get()->groupBy(fn ($c) => AccountsImporter::normalize($c->name))->all();
        $units = Unit::query()->forTenant($id)->where('is_active', true)->get()->filter(fn ($u) => AccountsImporter::normalize($u->code) === 'PCS' || AccountsImporter::normalize($u->name) === 'PCS');
        $this->pcs = $units->count() === 1 ? $units->first() : null;
        $productIds = array_merge(array_map(fn ($b) => $b->product_id, $this->barcodes),
            collect($this->skus)->flatten()->pluck('id')->all());
        $this->movementProducts = $this->warehouse ? array_fill_keys(StockMovement::query()->forTenant($id)
            ->where('warehouse_id', $this->warehouse->id)->whereIn('product_id', $productIds)->distinct()->pluck('product_id')->all(), true) : [];
    }

    public function import(array $row): array
    {
        $warnings = [];
        $code = $row['code'];
        if ($code === '' || strlen($code) > 100 || $row['name'] === '' || mb_strlen($row['name']) > 180) {
            return ['outcome' => 'skipped', 'warnings' => ['A valid CODE and product name are required.']];
        }
        // Refresh misses while holding the caller's tenant lock: another import
        // may have created this identity after our prefetch.
        $barcode = $this->barcodes[$code] ?? ProductBarcode::query()->forTenant($this->tenant->id)->where('barcode', $code)->first();
        $skus = $this->skus[$code] ?? Product::query()->forTenant($this->tenant->id)->where('sku', $code)->get();
        if ($skus->count() > 1 || ($barcode && $skus->count() === 1 && (int) $barcode->product_id !== (int) $skus->first()->id)) {
            return ['outcome' => 'skipped', 'warnings' => ['CODE matches conflicting products; review is required.']];
        }
        $product = $barcode ? Product::query()->forTenant($this->tenant->id)->whereKey($barcode->product_id)->firstOrFail() : $skus->first();
        if (! $product && ! $this->pcs) {
            return ['outcome' => 'skipped', 'warnings' => ['Tenant has no unique active PCS unit; new product was not created.']];
        }
        if (! $product && ! $this->settingsReady) {
            return ['outcome' => 'skipped', 'warnings' => ['Tenant catalog settings are missing; initialize the tenant catalog before creating products.']];
        }
        $category = null;
        if ($row['category'] !== '') {
            $key = AccountsImporter::normalize($row['category']);
            $matches = $this->categories[$key] ?? Category::query()->forTenant($this->tenant->id)
                ->whereRaw('upper(trim(name)) = ?', [$key])->get();
            if ($matches->count() > 1 || ($matches->count() === 1 && ! $matches->first()->is_active)) {
                return ['outcome' => 'skipped', 'warnings' => ['Category is ambiguous or inactive.']];
            }
            $category = $matches->first();
            if (! $category) {
                $category = Category::query()->create(['tenant_id' => $this->tenant->id,
                    'code' => 'LEG-'.substr(hash('sha256', $key), 0, 24), 'name' => $row['category'], 'sort_order' => 0, 'is_active' => true]);
                $this->categories[$key] = collect([$category]);
            }
        }
        $created = ! $product;
        if ($created) {
            $product = $this->create->execute(['name' => $row['name'], 'sku' => strlen($code) <= 64 ? $code : null,
                'category_ulid' => $category?->ulid, 'base_unit_ulid' => $this->pcs->ulid,
                'barcodes' => [['barcode' => $code, 'unit_ulid' => $this->pcs->ulid,
                    'conversion_factor' => '1.00000000', 'is_primary' => true, 'is_active' => true]]]);
            $this->barcodes[$code] = $product->barcodes->first();
            if ($product->sku) {
                $this->skus[$code] = collect([$product]);
            }
        } elseif ($category) {
            $product->category_id = $category->id;
            $product->save();
        }
        if ($this->decimal($row['retail'], 4)) {
            $this->prices->execute($product, [['price_type' => 'retail', 'amount' => $row['retail']]]);
        } else {
            $warnings[] = 'Retail is blank/invalid; existing price/default behavior was preserved.';
        }
        $result = ['outcome' => $created ? 'created' : 'updated', 'entity_ulid' => $product->ulid,
            'warnings' => $warnings, 'stock' => 'skipped'];
        $qty = $row['stock'];
        if (! $this->decimal($qty, 6, true)) {
            $result['warnings'][] = 'Invalid stock quantity; original value '.$qty.' was not posted.';
        } elseif (bccomp($qty, '0', 6) < 0) {
            $result['warnings'][] = 'Negative source quantity '.$qty.' was not posted: opening stock requires positive quantity.';
        } elseif (bccomp($qty, '0', 6) === 0) {
            $result['stock'] = 'zero';
        } elseif (! $this->warehouse) {
            $result['warnings'][] = 'Stock '.$qty.' not imported because no target warehouse was selected.';
        } elseif (! $this->decimal($row['cost'], 4)) {
            $result['warnings'][] = 'Stock not posted because Pur.Rate is blank/invalid; no opening cost was invented.';
        } elseif (isset($this->movementProducts[$product->id]) || StockMovement::query()->forTenant($this->tenant->id)
            ->where('warehouse_id', $this->warehouse->id)->where('product_id', $product->id)->exists()) {
            $result['warnings'][] = 'Stock not posted: product already has movement history in the selected warehouse.';
        } elseif ((int) $product->baseUnit->tenant_id !== (int) $this->tenant->id
            || (AccountsImporter::normalize($product->baseUnit->code) !== 'PCS'
            && AccountsImporter::normalize($product->baseUnit->name) !== 'PCS')
            || ($barcode && ((int) $barcode->unit_id !== (int) $product->base_unit_id
                || bccomp((string) $barcode->conversion_factor, '1', 8) !== 0))) {
            $result['warnings'][] = 'Stock not posted: existing unit/barcode conversion cannot be inferred from this unitless workbook. Existing units were preserved.';
        } else {
            try {
                $document = DB::transaction(function () use ($row, $product) {
                    $document = $this->opening->execute(['warehouse_ulid' => $this->warehouse->ulid,
                        'notes' => 'Platform legacy import: source row '.$row['row']]);
                    $this->line->execute($document, ['product_ulid' => $product->ulid, 'quantity' => $row['stock'], 'unit_cost' => $row['cost']]);

                    return $this->post->execute($document);
                });
                $result['opening_balance_id'] = $document->id;
                $result['stock'] = 'posted';
                $this->movementProducts[$product->id] = true;
            } catch (ValidationException $e) {
                $result['warnings'][] = 'Stock not posted: '.implode(' ', $e->validator->errors()->all());
            }
        }
        if ($result['stock'] !== 'posted' && $row['cost'] !== '') {
            $result['warnings'][] = 'Pur.Rate was not applied: cost changes require a legitimate opening-stock posting.';
        }

        return $result;
    }

    private function decimal(string $value, int $scale, bool $signed = false): bool
    {
        return (bool) preg_match('/^'.($signed ? '-?' : '').'(?:0|[1-9]\d{0,'.(19 - $scale).'})(?:\.\d{1,'.$scale.'})?$/', $value);
    }
}
