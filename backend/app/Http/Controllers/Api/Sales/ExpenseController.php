<?php

namespace App\Http\Controllers\Api\Sales;

use App\Actions\Sales\CreateExpenseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreExpenseRequest;
use App\Http\Resources\Sales\ExpenseResource;
use App\Models\Account;
use App\Models\Expense;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): array
    {
        $this->authorize('viewAny', Expense::class);

        $perPage = min(max($request->integer('per_page', 40), 1), 100);

        $query = Expense::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->with(['expenseAccount', 'paymentAccount'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', (string) $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', (string) $request->string('date_to'));
        }

        if ($request->filled('q')) {
            $raw = trim((string) $request->string('q'));
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $raw).'%';

            $query->where(function ($inner) use ($term): void {
                $inner
                    ->where('reference', 'ilike', $term)
                    ->orWhere('description', 'ilike', $term)
                    ->orWhereHas('expenseAccount', fn ($account) => $account
                        ->where('code', 'ilike', $term)
                        ->orWhere('name', 'ilike', $term))
                    ->orWhereHas('paymentAccount', fn ($account) => $account
                        ->where('code', 'ilike', $term)
                        ->orWhere('name', 'ilike', $term));
            });
        }

        $page = $query->paginate($perPage);

        return [
            'data' => ExpenseResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function accounts(TenantContext $tenantContext): array
    {
        $this->authorize('create', Expense::class);

        return Account::query()
            ->forTenant($tenantContext->tenantId())
            ->where('is_active', true)
            ->orderBy('code')
            ->orderBy('name')
            ->get(['ulid', 'code', 'name'])
            ->map(fn (Account $account) => [
                'ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->values()
            ->all();
    }

    public function store(
        StoreExpenseRequest $request,
        CreateExpenseAction $create,
    ): JsonResponse {
        $this->authorize('create', Expense::class);

        $expense = $create->execute(
            $request->validated(),
            (string) $request->header('Idempotency-Key', ''),
        );

        return (new ExpenseResource($expense))->response()->setStatusCode(201);
    }
}
