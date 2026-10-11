<?php

namespace App\Accounting;

use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Tenancy\TenantContext;

class PartyLedgerBalanceSummary
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AccountBalanceCalculator $balances,
    ) {}

    /**
     * @param list<array{type: string, ulid: string}> $items
     * @return array<string, string>
     */
    public function build(array $items): array
    {
        $tenantId = $this->tenantContext->tenantId();

        $requested = collect($items)
            ->map(fn (array $item): array => [
                'type' => (string) $item['type'],
                'ulid' => (string) $item['ulid'],
                'key' => (string) $item['type'].':'.(string) $item['ulid'],
            ])
            ->unique('key')
            ->values();

        $result = $requested
            ->mapWithKeys(fn (array $item): array => [$item['key'] => '0.0000'])
            ->all();

        if ($requested->isEmpty()) {
            return $result;
        }

        $vendorUlids = $requested->where('type', 'vendor')->pluck('ulid')->values();
        $customerUlids = $requested->where('type', 'customer')->pluck('ulid')->values();
        $accountUlids = $requested->where('type', 'account')->pluck('ulid')->values();

        $suppliers = $vendorUlids->isEmpty()
            ? collect()
            : Supplier::query()
                ->forTenant($tenantId)
                ->whereIn('ulid', $vendorUlids)
                ->get(['id', 'ulid']);

        $customers = $customerUlids->isEmpty()
            ? collect()
            : Customer::query()
                ->forTenant($tenantId)
                ->whereIn('ulid', $customerUlids)
                ->get(['id', 'ulid']);

        $supplierUlidById = $suppliers->pluck('ulid', 'id');
        $customerUlidById = $customers->pluck('ulid', 'id');

        $linkedAccountQuery = Account::query()
            ->forTenant($tenantId)
            ->with('accountType')
            ->where(function ($query) use ($supplierUlidById, $customerUlidById): void {
                $supplierIds = $supplierUlidById->keys();
                $customerIds = $customerUlidById->keys();

                if ($supplierIds->isNotEmpty()) {
                    $query->orWhereIn('supplier_id', $supplierIds);
                }
                if ($customerIds->isNotEmpty()) {
                    $query->orWhereIn('customer_id', $customerIds);
                }
            });

        $linkedAccounts = ($supplierUlidById->isEmpty() && $customerUlidById->isEmpty())
            ? collect()
            : $linkedAccountQuery->get();

        $manualAccounts = $accountUlids->isEmpty()
            ? collect()
            : Account::query()
                ->forTenant($tenantId)
                ->with('accountType')
                ->whereIn('ulid', $accountUlids)
                ->whereNull('supplier_id')
                ->whereNull('customer_id')
                ->get();

        $accounts = $linkedAccounts
            ->concat($manualAccounts)
            ->unique('id')
            ->values();

        if ($accounts->isEmpty()) {
            return $result;
        }

        $totals = JournalLine::query()
            ->selectRaw('journal_lines.account_id, COALESCE(SUM(journal_lines.debit), 0) as debit_total, COALESCE(SUM(journal_lines.credit), 0) as credit_total')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->whereIn('journal_lines.account_id', $accounts->pluck('id'))
            ->groupBy('journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        foreach ($accounts as $account) {
            $row = $totals->get($account->id);
            $debit = $this->balances->money((string) ($row?->debit_total ?? '0'));
            $credit = $this->balances->money((string) ($row?->credit_total ?? '0'));
            $balance = $this->balances->money($this->balances->signedEffect($account, $debit, $credit));

            if ($account->supplier_id && $supplierUlidById->has($account->supplier_id)) {
                $result['vendor:'.$supplierUlidById->get($account->supplier_id)] = $balance;
            }
            if ($account->customer_id && $customerUlidById->has($account->customer_id)) {
                $result['customer:'.$customerUlidById->get($account->customer_id)] = $balance;
            }
            if (! $account->supplier_id && ! $account->customer_id) {
                $result['account:'.$account->ulid] = $balance;
            }
        }

        return $result;
    }
}
