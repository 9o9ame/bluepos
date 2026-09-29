<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\StorePartyBankAccountRequest;
use App\Http\Requests\Parties\UpdatePartyBankAccountRequest;
use App\Http\Resources\PartyBankAccountResource;
use App\Models\Account;
use App\Models\Customer;
use App\Models\PartyBankAccount;
use App\Models\Supplier;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartyBankAccountController extends Controller
{
    public function __construct(private readonly TenantCatalog $catalog) {}

    public function index(Request $request, string $partyUlid, TenantContext $tenantContext): mixed
    {
        [$party, $partyType] = $this->resolveOwnedParty($request, $partyUlid);
        $this->authorize('view', $party);

        return PartyBankAccountResource::collection(
            PartyBankAccount::query()
                ->forTenant($tenantContext->tenantId())
                ->where('party_type', $partyType)
                ->where('party_id', $party->id)
                ->orderBy('sort_order')
                ->orderBy('bank_name')
                ->get()
        );
    }

    public function store(
        StorePartyBankAccountRequest $request,
        string $partyUlid,
        TenantContext $tenantContext,
    ): JsonResponse {
        [$party, $partyType] = $this->resolveOwnedParty($request, $partyUlid);
        $this->authorize('update', $party);

        $bank = DB::transaction(function () use ($request, $tenantContext, $party, $partyType): PartyBankAccount {
            $accountNumber = $request->validated('account_number');
            if ($accountNumber !== null) {
                $dup = PartyBankAccount::query()
                    ->forTenant($tenantContext->tenantId())
                    ->where('party_type', $partyType)
                    ->where('party_id', $party->id)
                    ->where('account_number', $accountNumber)
                    ->exists();
                if ($dup) {
                    throw new ApiException('VALIDATION_FAILED', 'Account number already exists for this party.', 422);
                }
            }

            return PartyBankAccount::query()->create([
                'tenant_id' => $tenantContext->tenantId(),
                'party_type' => $partyType,
                'party_id' => $party->id,
                'bank_name' => $request->validated('bank_name'),
                'branch_name' => $request->validated('branch_name'),
                'branch_code' => $request->validated('branch_code'),
                'city' => $request->validated('city'),
                'account_number' => $accountNumber,
                'sort_order' => (int) $request->input('sort_order', 0),
            ]);
        });

        return (new PartyBankAccountResource($bank))->response()->setStatusCode(201);
    }

    public function update(
        UpdatePartyBankAccountRequest $request,
        string $partyUlid,
        string $bankUlid,
    ): PartyBankAccountResource {
        [$party, $partyType] = $this->resolveOwnedParty($request, $partyUlid);
        $this->authorize('update', $party);
        $bank = $this->resolveBank($party, $partyType, $bankUlid);

        DB::transaction(function () use ($request, $party, $partyType, $bank): void {
            $data = $request->validated();
            if (array_key_exists('account_number', $data) && $data['account_number'] !== null) {
                $dup = PartyBankAccount::query()
                    ->forTenant((int) $party->tenant_id)
                    ->where('party_type', $partyType)
                    ->where('party_id', $party->id)
                    ->where('account_number', $data['account_number'])
                    ->where('id', '!=', $bank->id)
                    ->exists();
                if ($dup) {
                    throw new ApiException('VALIDATION_FAILED', 'Account number already exists for this party.', 422);
                }
            }
            $bank->fill($data);
            $bank->save();
        });

        return new PartyBankAccountResource($bank->refresh());
    }

    public function destroy(Request $request, string $partyUlid, string $bankUlid): JsonResponse
    {
        [$party, $partyType] = $this->resolveOwnedParty($request, $partyUlid);
        $this->authorize('update', $party);
        $bank = $this->resolveBank($party, $partyType, $bankUlid);

        DB::transaction(function () use ($bank): void {
            $bank->delete();
        });

        return response()->json(['ok' => true, 'deleted' => true]);
    }

    /**
     * @return array{0: Supplier|Customer|Account, 1: string}
     */
    private function resolveOwnedParty(Request $request, string $partyUlid): array
    {
        $type = strtolower(trim((string) $request->query('type', $request->input('party_type', ''))));
        if (! in_array($type, ['vendor', 'customer', 'account'], true)) {
            throw new ApiException('VALIDATION_FAILED', 'party type (vendor|customer|account) is required.', 422);
        }

        $party = match ($type) {
            'vendor' => $this->catalog->supplier($partyUlid),
            'customer' => $this->catalog->customer($partyUlid),
            default => $this->catalog->account($partyUlid),
        };

        return [$party, $type];
    }

    private function resolveBank(Model $party, string $partyType, string $bankUlid): PartyBankAccount
    {
        $bank = PartyBankAccount::query()
            ->forTenant((int) $party->tenant_id)
            ->where('party_type', $partyType)
            ->where('party_id', $party->id)
            ->where('ulid', $bankUlid)
            ->first();

        if (! $bank) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $bank;
    }
}
