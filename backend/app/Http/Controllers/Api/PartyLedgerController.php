<?php

namespace App\Http\Controllers\Api;

use App\Accounting\AccountLedgerBuilder;
use App\Accounting\PartyLeafAccountResolver;
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

class PartyLedgerController extends Controller
{
    public function __construct(
        private readonly PartyLeafAccountResolver $resolver,
        private readonly AccountLedgerBuilder $ledger,
        private readonly PartyLeafAccountSync $leafSync,
        private readonly TenantCatalog $catalog,
    ) {}

    public function show(Request $request, string $partyUlid, TenantContext $tenantContext): JsonResponse
    {
        [$party, $partyType, $leaf] = $this->ownedParty($request, $partyUlid);
        $this->authorize('view', $party);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 100)));

        $payload = $this->ledger->build($leaf, $page, $perPage);

        if ($party instanceof Supplier || $party instanceof Customer) {
            $payload['account']['name'] = $party->name;
            $payload['account']['address'] = $party->address;
        }

        return response()->json($payload);
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
