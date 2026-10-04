<?php

namespace App\Platform\Imports;

use App\Accounting\PartyLeafAccountSync;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Tenant;

final class AccountsImporter
{
    private array $types;

    public function __construct(private readonly Tenant $tenant, private readonly PartyLeafAccountSync $leaf)
    {
        $this->types = AccountType::query()->forTenant($tenant->id)->where('is_active', true)->with('subHead.mainHead')->get()
            ->groupBy(fn ($type) => self::normalize($type->name))->all();
    }

    public static function normalize(string $text): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($text)));
    }

    public function import(array $row): array
    {
        if ($row['type'] === 'ALL') {
            return ['outcome' => 'skipped', 'warnings' => ['TYPE ALL is intentionally excluded; no party/account was created.']];
        }
        $class = match ($row['type']) {
            'VENDORS' => Supplier::class,
            'CUSTOMERS' => Customer::class,
            'ACCOUNTS' => Account::class,
            default => null,
        };
        if (! $class || $row['name'] === '' || mb_strlen($row['name']) > 180) {
            return ['outcome' => 'skipped', 'warnings' => ['Unsupported TYPE or invalid source name.']];
        }
        $matches = $this->types[self::normalize($row['account_type'])] ?? collect();
        if ($matches->count() !== 1 || ! $matches->first()->subHead?->is_active
            || ! $matches->first()->subHead?->mainHead?->is_active
            || (int) $matches->first()->subHead->tenant_id !== (int) $this->tenant->id
            || (int) $matches->first()->subHead->mainHead->tenant_id !== (int) $this->tenant->id) {
            return ['outcome' => 'skipped', 'warnings' => ['Account Type has no unique active match in the tenant chart: '.$row['account_type']]];
        }
        $type = $matches->first();
        $code = $row['code'] !== '' ? mb_strtoupper($row['code'])
            : 'LEG-'.substr(hash('sha256', $row['type'].'|'.self::normalize($row['name']).'|'.$row['contact'].'|'.$type->ulid), 0, 32);
        if (strlen($code) > 64 || strlen($row['contact']) > 64) {
            return ['outcome' => 'skipped', 'warnings' => ['Source code/contact exceeds domain field length.']];
        }
        $existing = $class::query()->forTenant($this->tenant->id)->where('code', $code)->first();
        if ($existing) {
            if (self::normalize($existing->name) !== self::normalize($row['name'])
                || (int) $existing->account_type_id !== (int) $type->id
                || ($existing instanceof Account && ($existing->supplier_id || $existing->customer_id))
                || ($row['contact'] !== '' && $class !== Account::class && ($existing->phone ?? '') !== $row['contact'])) {
                return ['outcome' => 'skipped', 'warnings' => ['Code matches a conflicting identity; no merge or overwrite performed.']];
            }

            if ($existing instanceof Supplier) {
                $this->leaf->syncSupplier($existing);
            } elseif ($existing instanceof Customer) {
                $this->leaf->syncCustomer($existing);
            }

            return ['outcome' => 'reused', 'entity_ulid' => $existing->ulid, 'warnings' => []];
        }
        // Names are not unique identifiers. Without a code, a pre-existing name needs review.
        if ($row['code'] === '' && $class::query()->forTenant($this->tenant->id)->whereRaw('upper(trim(name)) = ?', [self::normalize($row['name'])])->exists()) {
            return ['outcome' => 'skipped', 'warnings' => ['Name already exists with a different code; source has no safe matching code.']];
        }
        if (Account::query()->forTenant($this->tenant->id)->where('code', $code)->exists()) {
            return ['outcome' => 'skipped', 'warnings' => ['Code is already assigned to a ledger account.']];
        }
        $attrs = ['tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $row['name'],
            'account_type_id' => $type->id, 'is_active' => true, 'created_by' => null];
        if ($class !== Account::class) {
            $attrs['phone'] = $row['contact'] ?: null;
        }
        $party = $class::query()->create($attrs);
        if ($party instanceof Supplier) {
            $this->leaf->syncSupplier($party);
        } elseif ($party instanceof Customer) {
            $this->leaf->syncCustomer($party);
        }

        return ['outcome' => 'created', 'entity_ulid' => $party->ulid,
            'warnings' => $class === Account::class && $row['contact'] !== '' ? ['Standalone account has no phone field; Contacts was not imported.'] : []];
    }
}
