<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreSubcategoryRequest;
use App\Http\Requests\Catalog\UpdateSubcategoryRequest;
use App\Http\Resources\SubcategoryResource;
use App\Models\Subcategory;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SubcategoryController extends Controller
{
    public function __construct(private readonly TenantCatalog $catalog) {}

    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Subcategory::class);

        $query = Subcategory::query()
            ->forTenant($tenantContext->tenantId())
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('category_ulid')) {
            $category = $this->catalog->category((string) $request->string('category_ulid'));
            $query->where('category_id', $category->id);
        }

        return SubcategoryResource::collection($query->get());
    }

    public function store(StoreSubcategoryRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', Subcategory::class);
        $category = $this->catalog->category($request->validated('category_ulid'));

        $exists = Subcategory::query()
            ->forTenant($tenantContext->tenantId())
            ->where('category_id', $category->id)
            ->where('code', $request->validated('code'))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => 'This subcategory code already exists for the category.',
            ]);
        }

        $subcategory = Subcategory::query()->create([
            'tenant_id' => $tenantContext->tenantId(),
            'category_id' => $category->id,
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => $request->integer('sort_order'),
        ]);

        return (new SubcategoryResource($subcategory->load('category')))->response()->setStatusCode(201);
    }

    public function show(string $subcategoryUlid): SubcategoryResource
    {
        $subcategory = $this->catalog->subcategory($subcategoryUlid);
        $this->authorize('view', $subcategory);

        return new SubcategoryResource($subcategory->load('category'));
    }

    public function update(UpdateSubcategoryRequest $request, string $subcategoryUlid): SubcategoryResource
    {
        $subcategory = $this->catalog->subcategory($subcategoryUlid);
        $this->authorize('update', $subcategory);
        $data = $request->validated();

        if (isset($data['category_ulid'])) {
            $category = $this->catalog->category($data['category_ulid']);
            $subcategory->category_id = $category->id;
            unset($data['category_ulid']);
        }

        $subcategory->fill($data);
        $subcategory->save();

        return new SubcategoryResource($subcategory->load('category'));
    }

    public function destroy(string $subcategoryUlid): JsonResponse
    {
        $subcategory = $this->catalog->subcategory($subcategoryUlid);
        $this->authorize('delete', $subcategory);

        if ($subcategory->products()->exists()) {
            $subcategory->is_active = false;
            $subcategory->save();

            return response()->json(['ok' => true, 'archived' => true]);
        }

        $subcategory->delete();

        return response()->json(['ok' => true]);
    }
}
