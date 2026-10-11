<?php

namespace App\Http\Controllers\Api;

use App\Accounting\AccountLedgerBuilder;
use App\Accounting\PartyLeafAccountResolver;
use App\Accounting\PartyLedgerBalanceSummary;
use App\Accounting\PartyLeafAccountSync;
use App\Catalog\TenantCatalog;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartyLedgerController extends Controller
{
    public function __construct(
        private readonly PartyLeafAccountResolver $resolver,
        private readonly AccountLedgerBuilder $ledger,
        private readonly PartyLedgerBalanceSummary $balanceSummary,
        private readonly PartyLeafAccountSync $leafSync,
        private readonly TenantCatalog $catalog,
    ) {}

    public function show(Request $request, string $partyUlid, TenantContext $tenantContext): JsonResponse
    {
        [$party, $partyType, $leaf] = $this->ownedParty($request, $partyUlid);
        $this->authorize('view', $party);

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'include_opening_balance' => ['nullable', 'boolean'],
            'document_types' => ['nullable', 'array', 'max:12'],
            'document_types.*' => [
                'string',
                Rule::in([
                    'opening_balance',
                    'purchase_invoice',
                    'purchase_return',
                    'payment_voucher',
                    'receiving_voucher',
                    'journal_voucher',
                    'sale_payment',
                ]),
            ],
            'group_by' => ['nullable', 'string', Rule::in(['document', 'entry_date', 'user_name', 'area_name', 'sales_person'])],
            'filter_by' => ['nullable', 'string', Rule::in(['document', 'entry_date', 'user_name', 'area_name', 'sales_person'])],
            'only_for' => ['nullable', 'string', 'max:255'],
            'sale_mode' => ['nullable', 'string', Rule::in(['wholesale', 'non_wholesale'])],
        ]);

        $page = max(1, (int) ($data['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($data['per_page'] ?? 100)));

        $payload = $this->ledger->build(
            $leaf,
            $page,
            $perPage,
            isset($data['date_from']) ? (string) $data['date_from'] : null,
            isset($data['date_to']) ? (string) $data['date_to'] : null,
            (bool) ($data['include_opening_balance'] ?? true),
            array_values($data['document_types'] ?? []),
            isset($data['group_by']) ? (string) $data['group_by'] : null,
            isset($data['filter_by']) ? (string) $data['filter_by'] : null,
            isset($data['only_for']) ? (string) $data['only_for'] : null,
            isset($data['sale_mode']) ? (string) $data['sale_mode'] : null,
        );

        if ($party instanceof Supplier || $party instanceof Customer) {
            $payload['account']['name'] = $party->name;
            $payload['account']['address'] = $party->address;
        }

        return response()->json($payload);
    }

    public function balances(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:250'],
            'items.*.type' => ['required', 'string', Rule::in(['vendor', 'customer', 'account'])],
            'items.*.ulid' => ['required', 'string', 'size:26'],
        ]);

        $types = collect($data['items'])->pluck('type')->unique();

        if ($types->contains('vendor')) {
            $this->authorize('viewAny', Supplier::class);
        }
        if ($types->contains('customer')) {
            $this->authorize('viewAny', Customer::class);
        }
        if ($types->contains('account')) {
            $this->authorize('viewAny', Account::class);
        }

        return response()->json([
            'balances' => $this->balanceSummary->build(array_values($data['items'])),
        ]);
    }

    public function ensureLeafAccount(Request $request, string $partyUlid): JsonResponse
    {
        $type = strtolower(trim((string) $request->query('type', $request->input('party_type', ''))));
        if (! in_array($type, ['vendor', 'customer', 'account'], true)) {
            throw new ApiException('VALIDATION_FAILED', 'party type (vendor|customer|account) is required.', 422);
        }

        if ($type === 'account') {
            $account = $this->catalog->account($partyUlid);
            $this->authorize('view', $account);

            return response()->json([
                'status' => 'already_linked',
                'leaf_account_ulid' => $account->ulid,
                'message' => 'Manual account is already a leaf account.',
            ]);
        }

        $party = $type === 'vendor'
            ? $this->catalog->supplier($partyUlid)
            : $this->catalog->customer($partyUlid);
        $this->authorize('update', $party);

        if (! $party->account_type_id) {
            throw new ApiException(
                'VALIDATION_FAILED',
                'Account Type is required before creating a leaf account.',
                422,
            );
        }

        $existing = Account::query()
            ->forTenant((int) $party->tenant_id)
            ->when($type === 'vendor', fn ($q) => $q->where('supplier_id', $party->id))
            ->when($type === 'customer', fn ($q) => $q->where('customer_id', $party->id))
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'already_linked',
                'leaf_account_ulid' => $existing->ulid,
                'message' => 'Leaf account already linked.',
            ]);
        }

        $leaf = $type === 'vendor'
            ? $this->leafSync->syncSupplier($party)
            : $this->leafSync->syncCustomer($party);

        return response()->json([
            'status' => 'created',
            'leaf_account_ulid' => $leaf->ulid,
            'message' => 'Leaf account created and linked.',
        ], 201);
    }

    /**
     * @return array{0: Supplier|Customer|Account, 1: string, 2: Account}
     */
    private function ownedParty(Request $request, string $partyUlid): array
    {
        $type = strtolower(trim((string) $request->query('type', $request->input('party_type', ''))));
        if (! in_array($type, ['vendor', 'customer', 'account'], true)) {
            throw new ApiException('VALIDATION_FAILED', 'party type (vendor|customer|account) is required.', 422);
        }

        return $this->resolver->resolve($type, $partyUlid);
    }
}
