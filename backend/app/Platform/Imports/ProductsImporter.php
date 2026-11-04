<?php

namespace App\Platform\Imports;

use App\Actions\Inventory\CreateOpeningBalanceAction;
use App\Actions\Inventory\PostOpeningBalanceAction;
use App\Actions\Inventory\PostStockMovementAction;
use App\Actions\Inventory\StockLedgerLock;
use App\Actions\Inventory\UpsertOpeningBalanceLineAction;
use App\Actions\Products\CreateProductAction;
use App\Actions\Products\SyncProductBarcodesAction;
use App\Actions\Products\SyncProductPricesAction;
use App\Actions\Products\SyncProductPrimarySupplierAction;
use App\Catalog\TenantCatalog;
use App\Models\BusinessSetting;
use App\Models\Brand;
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

    private array $brands;

    private array $units;

    private array $movementProducts;

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
        $this->post = new PostOpeningBalanceAction($ctx, new PostStockMovementAction($ctx, app(StockLedgerLock::class)), $audit);
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
        $units = Unit::query()->forTenant($id)->where('is_active', true)->get();
        $this->units = $units->groupBy(fn ($u) => AccountsImporter::normalize($u->code))->all();
        $this->brands = Brand::query()->forTenant($id)->get()
            ->groupBy(fn ($b) => AccountsImporter::normalize($b->name))->all();
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
        // Existing identities are never modified on subsequent imports, including
        // modified exports and source files in different order.
        if ($product) {
            return ['outcome' => 'reused', 'entity_ulid' => $product->ulid,
                'warnings' => [], 'stock' => 'skipped'];
        }
        $unitName = AccountsImporter::normalize((string) ($row['unit'] ?? ''));
        $unitKey = in_array($unitName, ['', 'EACH', 'PIECE', 'PIECES', 'UNIT'], true) ? 'PCS' : $unitName;
        $unitMatches = $this->units[$unitKey] ?? collect();
        if ($unitMatches->count() !== 1) {
            return ['outcome' => 'skipped', 'warnings' => ['No unique active unit matches source U.O.M; product was not created.']];
        }
        $baseUnit = $unitMatches->first();
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
        $brand = null;
        if (trim((string) ($row['brand'] ?? '')) !== '') {
            $brandName = trim((string) $row['brand']);
            $brandKey = AccountsImporter::normalize($brandName);
            $brandMatches = $this->brands[$brandKey] ?? collect();
            if ($brandMatches->count() > 1 || ($brandMatches->count() === 1 && ! $brandMatches->first()->is_active)) {
                return ['outcome' => 'skipped', 'warnings' => ['Brand is ambiguous or inactive.']];
            }
            $brand = $brandMatches->first();
            if (! $brand) {
                $brand = Brand::query()->create([
                    'tenant_id' => $this->tenant->id,
                    'code' => 'LEG-'.substr(hash('sha256', $brandKey), 0, 24),
                    'name' => mb_substr($brandName, 0, 180), 'is_active' => true,
                ]);
                $this->brands[$brandKey] = collect([$brand]);
            }
        }
        $created = ! $product;
        if ($created) {
            $product = $this->create->execute(['name' => $row['name'], 'sku' => strlen($code) <= 64 ? $code : null,
                'category_ulid' => $category?->ulid, 'brand_ulid' => $brand?->ulid,
                'base_unit_ulid' => $baseUnit->ulid,
                'purchase_rate' => $this->decimal($row['cost'], 4) ? $row['cost'] : null,
                'barcodes' => [['barcode' => $code, 'unit_ulid' => $baseUnit->ulid,
                    'conversion_factor' => '1.00000000', 'is_primary' => true, 'is_active' => true]]]);
            $this->barcodes[$code] = $product->barcodes->first();
            if ($product->sku) {
                $this->skus[$code] = collect([$product]);
            }
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
