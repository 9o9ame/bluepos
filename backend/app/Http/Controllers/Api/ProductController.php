<?php

namespace App\Http\Controllers\Api;

use App\Actions\Products\CreateProductAction;
use App\Actions\Products\DeactivateProductAction;
use App\Actions\Products\SyncProductBarcodesAction;
use App\Actions\Products\SyncProductPricesAction;
use App\Actions\Products\UpdateProductAction;
use App\Catalog\TenantCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\SyncProductBarcodesRequest;
use App\Http\Requests\Products\SyncProductPricesRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\StockBalance;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private readonly TenantCatalog $catalog) {}

    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Product::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = Product::query()
            ->forTenant($tenantContext->tenantId())
            ->with(['category', 'brand', 'barcodeGroup', 'baseUnit', 'barcodes.unit', 'prices', 'primaryProductSupplier.supplier'])
            ->when(
                $request->boolean('sales_lookup'),
                fn ($products) => $products->with([
                    'stockBalances' => fn ($balances) => $balances
                        ->where('tenant_id', $tenantContext->tenantId())
                        ->where('branch_id', $tenantContext->branchId())
                        ->where('warehouse_id', $tenantContext->warehouseId()),
                ]),
            )
            ->orderBy('product_number');

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('q')).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('name', 'ilike', $term)
                    ->orWhere('product_number', 'ilike', $term)
                    ->orWhere('sku', 'ilike', $term)
                    ->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('barcode', 'ilike', $term));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->boolean('with_balance')) {
            $query->whereHas('stockBalances', fn ($balances) => $balances
                ->where('tenant_id', $tenantContext->tenantId())
                ->where('branch_id', $tenantContext->branchId())
                ->where('warehouse_id', $tenantContext->warehouseId())
                ->where('quantity', '!=', 0));
        }

        if ($request->boolean('stock_le_reorder')) {
            $query
                ->whereNotNull('reorder_level')
                ->where(function ($stockQuery) use ($tenantContext): void {
                    $stockQuery
                        ->whereDoesntHave('stockBalances', fn ($balances) => $balances
                            ->where('tenant_id', $tenantContext->tenantId())
                            ->where('branch_id', $tenantContext->branchId())
                            ->where('warehouse_id', $tenantContext->warehouseId()))
                        ->orWhereHas('stockBalances', fn ($balances) => $balances
                            ->where('tenant_id', $tenantContext->tenantId())
                            ->where('branch_id', $tenantContext->branchId())
                            ->where('warehouse_id', $tenantContext->warehouseId())
                            ->whereColumn('stock_balances.quantity', '<=', 'products.reorder_level'));
                });
        }

        if ($request->boolean('purchase_rate_ge_sale_rate')) {
            $this->authorize('viewAny', StockBalance::class);

            $query->whereHas('stockBalances', fn ($balances) => $balances
                ->where('tenant_id', $tenantContext->tenantId())
                ->where('branch_id', $tenantContext->branchId())
                ->where('warehouse_id', $tenantContext->warehouseId())
                ->whereNotNull('average_cost')
                ->whereExists(fn ($prices) => $prices
                    ->selectRaw('1')
                    ->from('product_prices')
                    ->whereColumn('product_prices.product_id', 'stock_balances.product_id')
                    ->where('product_prices.price_type', 'retail')
                    ->where('product_prices.is_active', true)
                    ->whereColumn('stock_balances.average_cost', '>=', 'product_prices.amount')));
        }

        if ($request->filled('category_ulid')) {
            $category = $this->catalog->category((string) $request->string('category_ulid'));
            $query->where('category_id', $category->id);
        }

        if ($request->filled('brand_ulid')) {
            $brand = $this->catalog->brand((string) $request->string('brand_ulid'));
            $query->where('brand_id', $brand->id);
        }

        if ($request->boolean('packaging')) {
            $query->where('is_packaging', true);
        }

        $page = $query->paginate($perPage);

        return [
            'data' => ProductResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function store(StoreProductRequest $request, CreateProductAction $createProduct): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = $createProduct->execute($request->validated());

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(string $productUlid): ProductResource
    {
        $product = $this->catalog->product($productUlid);
        $this->authorize('view', $product);

        return new ProductResource($product->load(CreateProductAction::with()));
    }

    public function update(
        UpdateProductRequest $request,
        string $productUlid,
        UpdateProductAction $updateProduct,
    ): ProductResource {
        $product = $this->catalog->product($productUlid);
        $this->authorize('update', $product);

        return new ProductResource($updateProduct->execute($product, $request->validated()));
    }

    public function destroy(string $productUlid, DeactivateProductAction $deactivateProduct): ProductResource
    {
        $product = $this->catalog->product($productUlid);
        $this->authorize('delete', $product);

        return new ProductResource($deactivateProduct->execute($product));
    }


    public function image(string $productUlid): mixed
    {
        $product = $this->catalog->product($productUlid);
        $this->authorize('view', $product);

        if (! $product->image_path || ! Storage::disk('public')->exists($product->image_path)) {
            abort(404);
        }

        return Storage::disk('public')->response(
            $product->image_path,
            null,
            [
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }

    public function uploadImage(
        Request $request,
        string $productUlid,
        TenantContext $tenantContext,
    ): ProductResource {
        $product = $this->catalog->product($productUlid);
        $this->authorize('update', $product);

        $data = $request->validate([
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $file = $data['image'];
        $extension = strtolower($file->extension() ?: 'jpg');
        $directory = 'tenants/'.$tenantContext->tenantId().'/products/'.$product->ulid;
        $newPath = $file->storeAs(
            $directory,
            Str::uuid()->toString().'.'.$extension,
            'public',
        );

        if (! is_string($newPath) || $newPath === '') {
            abort(500, 'Unable to store product image.');
        }

        $oldPath = $product->image_path;
        $product->image_path = $newPath;
        $product->save();

        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return new ProductResource(
            $product->fresh()->load(CreateProductAction::with()),
        );
    }

    public function deleteImage(string $productUlid): ProductResource
    {
        $product = $this->catalog->product($productUlid);
        $this->authorize('update', $product);

        $oldPath = $product->image_path;

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $product->image_path = null;
        $product->save();

        return new ProductResource(
            $product->fresh()->load(CreateProductAction::with()),
        );
    }

    public function syncBarcodes(
        SyncProductBarcodesRequest $request,
        string $productUlid,
        SyncProductBarcodesAction $syncProductBarcodes,
    ): ProductResource {
        $product = $this->catalog->product($productUlid);
        $this->authorize('manageBarcodes', $product);

        return new ProductResource($syncProductBarcodes->execute($product, $request->validated('barcodes')));
    }

    public function syncPrices(
        SyncProductPricesRequest $request,
        string $productUlid,
        SyncProductPricesAction $syncProductPrices,
    ): ProductResource {
        $product = $this->catalog->product($productUlid);
        $this->authorize('managePrices', $product);

        return new ProductResource($syncProductPrices->execute($product, $request->validated('prices')));
    }
}
