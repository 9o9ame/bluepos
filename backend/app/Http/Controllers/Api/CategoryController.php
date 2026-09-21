<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(private readonly TenantCatalog $catalog) {}

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Category::class);

        $categories = Category::query()
            ->forTenant($tenantContext->tenantId())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', Category::class);

        $category = Category::query()->create([
            'tenant_id' => $tenantContext->tenantId(),
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => $request->integer('sort_order'),
        ]);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(string $categoryUlid): CategoryResource
    {
        $category = $this->catalog->category($categoryUlid);
        $this->authorize('view', $category);

        return new CategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, string $categoryUlid): CategoryResource
    {
        $category = $this->catalog->category($categoryUlid);
        $this->authorize('update', $category);
        $category->fill($request->validated());
        $category->save();

        return new CategoryResource($category);
    }

    public function destroy(string $categoryUlid): JsonResponse
    {
        $category = $this->catalog->category($categoryUlid);
        $this->authorize('delete', $category);

        if ($category->products()->exists() || $category->subcategories()->exists()) {
            $category->is_active = false;
            $category->save();

            return response()->json(['ok' => true, 'archived' => true]);
        }

        $category->delete();

        return response()->json(['ok' => true]);
    }
}
