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
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly TenantCatalog $catalog) {}

    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Product::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = Product::query()
            ->forTenant($tenantContext->tenantId())
            ->with(['category', 'brand', 'barcodeGroup', 'baseUnit', 'barcodes.unit', 'prices'])
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

        if ($request->filled('category_ulid')) {
            $category = $this->catalog->category((string) $request->string('category_ulid'));
            $query->where('category_id', $category->id);
        }

        if ($request->filled('brand_ulid')) {
            $brand = $this->catalog->brand((string) $request->string('brand_ulid'));
            $query->where('brand_id', $brand->id);
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
