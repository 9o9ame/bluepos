<?php

namespace App\Http\Controllers\Api;

use App\Accounting\AccountBalanceCalculator;
use App\Accounting\OpeningBalancePoster;
use App\Accounting\PartyLeafAccountResolver;
use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OpeningBalanceResource;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartyOpeningBalanceController extends Controller
{
    public function __construct(
        private readonly PartyLeafAccountResolver $resolver,
        private readonly OpeningBalancePoster $poster,
        private readonly AuditLogger $audit,
        private readonly AccountBalanceCalculator $balances,
    ) {}

    public function index(Request $request, string $partyUlid, TenantContext $tenantContext): JsonResponse
    {
        [$party, $partyType, $leaf] = $this->ownedParty($request, $partyUlid);
        $this->authorize('view', $party);

        $entries = JournalEntry::query()
            ->forTenant($tenantContext->tenantId())
            ->where('document_type', JournalEntry::DOCUMENT_OPENING_BALANCE)
            ->whereHas('lines', fn ($q) => $q->where('account_id', $leaf->id))
            ->with(['lines' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $rows = $entries->map(function (JournalEntry $entry) use ($leaf) {
            return (new OpeningBalanceResource($entry, $this->rowExtra($entry, $leaf)))->resolve();
        })->values()->all();

        return response()->json(['data' => $rows, 'leaf_account_ulid' => $leaf->ulid]);
    }

    public function store(Request $request, string $partyUlid, TenantContext $tenantContext): JsonResponse
    {
        [$party, $partyType, $leaf] = $this->ownedParty($request, $partyUlid);
        $this->authorize('update', $party);

        $data = $this->validatedOpening($request);
        $this->poster->assertMoneySides($data['debit'], $data['credit']);

        $entry = DB::transaction(function () use ($data, $tenantContext, $leaf, $party, $partyType): JournalEntry {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $tenantContext->tenantId(),
                'branch_id' => $tenantContext->branchId(),
                'document_type' => JournalEntry::DOCUMENT_OPENING_BALANCE,
                'entry_date' => $data['opening_date'],
                'description' => $data['narration'],
                'status' => JournalStatus::Draft,
                'created_by' => $tenantContext->userId(),
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantContext->tenantId(),
                'journal_entry_id' => $entry->id,
                'account_id' => $leaf->id,
                'description' => $data['narration'],
                'debit' => $data['debit'],
                'credit' => $data['credit'],
                'customer_id' => $partyType === 'customer' ? $party->id : null,
                'supplier_id' => $partyType === 'vendor' ? $party->id : null,
                'sort_order' => 0,
            ]);

            $this->audit->record('OPENING_BALANCE_CREATED', [
                'resource_type' => 'journal_entry',
                'resource_ulid' => $entry->ulid,
            ]);

            return $entry->load('lines');
        });

        return (new OpeningBalanceResource($entry, $this->rowExtra($entry, $leaf)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $partyUlid, string $openingUlid, TenantContext $tenantContext): OpeningBalanceResource
    {
        [$party, $partyType, $leaf] = $this->ownedParty($request, $partyUlid);
        $this->authorize('update', $party);
        $entry = $this->resolveOpening($tenantContext->tenantId(), $openingUlid, $leaf);

        if ($entry->isPosted()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted opening balances are immutable.', 422);
        }

        $data = $this->validatedOpening($request, partial: true);
        if (isset($data['debit'], $data['credit'])) {
            $this->poster->assertMoneySides($data['debit'], $data['credit']);
        } elseif (isset($data['debit']) || isset($data['credit'])) {
            $line = $this->partyLine($entry, $leaf);
            $debit = $data['debit'] ?? $this->money((string) $line->debit);
            $credit = $data['credit'] ?? $this->money((string) $line->credit);
            $this->poster->assertMoneySides($debit, $credit);
            $data['debit'] = $debit;
            $data['credit'] = $credit;
        }

        DB::transaction(function () use ($entry, $leaf, $data): void {
            $line = $this->partyLine($entry, $leaf);
            if (! $line) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            if (array_key_exists('opening_date', $data)) {
                $entry->entry_date = $data['opening_date'];
            }
            if (array_key_exists('narration', $data)) {
                $entry->description = $data['narration'];
                $line->description = $data['narration'];
            }
            if (array_key_exists('debit', $data)) {
                $line->debit = $data['debit'];
            }
            if (array_key_exists('credit', $data)) {
                $line->credit = $data['credit'];
            }
            $entry->save();
            $line->save();

            $this->audit->record('OPENING_BALANCE_UPDATED', [
                'resource_type' => 'journal_entry',
                'resource_ulid' => $entry->ulid,
            ]);
        });

        return new OpeningBalanceResource($entry->refresh()->load('lines'), $this->rowExtra($entry->refresh()->load('lines'), $leaf));
    }

    public function destroy(Request $request, string $partyUlid, string $openingUlid, TenantContext $tenantContext): JsonResponse
    {
        [$party, , $leaf] = $this->ownedParty($request, $partyUlid);
        $this->authorize('update', $party);
        $entry = $this->resolveOpening($tenantContext->tenantId(), $openingUlid, $leaf);

        if ($entry->isPosted()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted opening balances are immutable.', 422);
        }

        DB::transaction(function () use ($entry): void {
            $ulid = $entry->ulid;
            $entry->lines()->delete();
            $entry->delete();
            $this->audit->record('OPENING_BALANCE_DELETED', [
                'resource_type' => 'journal_entry',
                'resource_ulid' => $ulid,
            ]);
        });

        return response()->json(['ok' => true, 'deleted' => true]);
    }

    public function post(Request $request, string $partyUlid, string $openingUlid, TenantContext $tenantContext): OpeningBalanceResource
    {
        [$party, , $leaf] = $this->ownedParty($request, $partyUlid);
        $this->authorize('update', $party);
        $entry = $this->resolveOpening($tenantContext->tenantId(), $openingUlid, $leaf);
        $posted = $this->poster->post($entry, $leaf);

        return new OpeningBalanceResource($posted, $this->rowExtra($posted, $leaf));
    }

    /**
     * @return array{0: \Illuminate\Database\Eloquent\Model, 1: string, 2: Account}
     */
    private function ownedParty(Request $request, string $partyUlid): array
    {
        $type = strtolower(trim((string) $request->query('type', $request->input('party_type', ''))));
        if (! in_array($type, ['vendor', 'customer', 'account'], true)) {
            throw new ApiException('VALIDATION_FAILED', 'party type (vendor|customer|account) is required.', 422);
        }

        return $this->resolver->resolve($type, $partyUlid);
    }

    private function resolveOpening(int $tenantId, string $openingUlid, Account $leaf): JournalEntry
    {
        $entry = JournalEntry::query()
            ->forTenant($tenantId)
            ->where('ulid', $openingUlid)
            ->where('document_type', JournalEntry::DOCUMENT_OPENING_BALANCE)
            ->whereHas('lines', fn ($q) => $q->where('account_id', $leaf->id))
            ->with('lines')
            ->first();

        if (! $entry) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedOpening(Request $request, bool $partial = false): array
    {
        $rules = [
            'opening_date' => [$partial ? 'sometimes' : 'required', 'date'],
            'narration' => [$partial ? 'sometimes' : 'nullable', 'string', 'max:500'],
            'debit' => [$partial ? 'sometimes' : 'required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'credit' => [$partial ? 'sometimes' : 'required', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];

        $data = $request->validate($rules);
        if (array_key_exists('debit', $data)) {
            $data['debit'] = $this->money((string) $data['debit']);
        }
        if (array_key_exists('credit', $data)) {
            $data['credit'] = $this->money((string) $data['credit']);
        }
        if (array_key_exists('narration', $data)) {
            $data['narration'] = $data['narration'] !== null ? trim((string) $data['narration']) : null;
            $data['narration'] = $data['narration'] === '' ? null : $data['narration'];
        }

        return $data;
    }

    private function partyLine(JournalEntry $entry, Account $leaf): ?JournalLine
    {
        return $entry->lines->first(fn (JournalLine $line) => (int) $line->account_id === (int) $leaf->id);
    }

    /**
     * @return array{narration: string|null, debit: string, credit: string, balance: string, closing: string, leaf_account_ulid: string}
     */
    private function rowExtra(JournalEntry $entry, Account $leaf): array
    {
        $line = $this->partyLine($entry, $leaf);
        $debit = $this->money((string) ($line?->debit ?? '0'));
        $credit = $this->money((string) ($line?->credit ?? '0'));
        $postedNet = $this->postedNetForAccount($leaf);
        $effect = $this->signedEffect($leaf, $debit, $credit);

        if ($entry->isPosted()) {
            $balance = bcsub($postedNet, $effect, 4);
            $closing = $postedNet;
        } else {
            $balance = $postedNet;
            $closing = bcadd($postedNet, $effect, 4);
        }

        return [
            'narration' => $line?->description ?? $entry->description,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $this->money($balance),
            'closing' => $this->money($closing),
            'leaf_account_ulid' => $leaf->ulid,
        ];
    }

    private function postedNetForAccount(Account $leaf): string
    {
        $lines = JournalLine::query()
            ->where('account_id', $leaf->id)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('status', JournalStatus::Posted)
                ->where('tenant_id', $leaf->tenant_id))
            ->get(['debit', 'credit']);

        $debit = '0.0000';
        $credit = '0.0000';
        foreach ($lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        return $this->balances->signedEffect($leaf, $debit, $credit);
    }

    private function signedEffect(Account $leaf, string $debit, string $credit): string
    {
        return $this->balances->signedEffect($leaf, $debit, $credit);
    }

    private function money(string $value): string
    {
        return $this->balances->money($value);
    }
}
