<?php

namespace App\Accounting;

use App\Enums\CashierShiftStatus;
use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Models\CashierShift;
use App\Models\JournalLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShiftLifecycle
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CashierTransactionLock $cashierLock,
        private readonly AuditLogger $audit,
    ) {}

    public function current(): ?CashierShift
    {
        return CashierShift::query()
            ->where('tenant_id', $this->tenantContext->tenantId())
            ->where('branch_id', $this->tenantContext->branchId())
            ->where('membership_id', $this->tenantContext->membershipId())
            ->where('status', CashierShiftStatus::Open->value)
            ->with('membership.user')
            ->latest('opened_at')
            ->first();
    }

    public function open(string $openingCash, ?string $notes, string $idempotencyKey): CashierShift
    {
        $openingCash = $this->money($openingCash, 'opening_cash');
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($openingCash, $notes, $idempotencyKey): CashierShift {
            $this->cashierLock->lockCurrent();

            $existingReplay = CashierShift::query()
                ->where('tenant_id', $this->tenantContext->tenantId())
                ->where('open_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingReplay) {
                if (
                    (int) $existingReplay->branch_id !== $this->tenantContext->branchId()
                    || (int) $existingReplay->membership_id !== $this->tenantContext->membershipId()
                    || bccomp((string) $existingReplay->opening_cash, $openingCash, 4) !== 0
                    || $existingReplay->notes !== $this->nullableText($notes)
                ) {
                    throw new ApiException(
                        'IDEMPOTENCY_KEY_CONFLICT',
                        'This Idempotency-Key was already used for a different cash shift request.',
                        409,
                    );
                }

                return $existingReplay->load('membership.user');
            }

            $openShift = CashierShift::query()
                ->where('tenant_id', $this->tenantContext->tenantId())
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('membership_id', $this->tenantContext->membershipId())
                ->where('status', CashierShiftStatus::Open->value)
                ->lockForUpdate()
                ->first();

            if ($openShift) {
                throw new ApiException(
                    'CASH_SHIFT_ALREADY_OPEN',
                    'An open cash shift already exists for this cashier and branch.',
                    409,
                );
            }

            $shift = CashierShift::query()->create([
                'tenant_id' => $this->tenantContext->tenantId(),
                'branch_id' => $this->tenantContext->branchId(),
                'device_id' => $this->tenantContext->hasDevice() ? $this->tenantContext->deviceId() : null,
                'membership_id' => $this->tenantContext->membershipId(),
                'opened_at' => now(),
                'status' => CashierShiftStatus::Open->value,
                'opening_cash' => $openingCash,
                'expected_cash' => $openingCash,
                'actual_cash' => null,
                'difference' => null,
                'notes' => $this->nullableText($notes),
                'open_idempotency_key' => $idempotencyKey,
                'close_idempotency_key' => null,
            ]);

            $this->audit->record('CASH_SHIFT_OPENED', [
                'resource_type' => 'cashier_shift',
                'resource_ulid' => $shift->ulid,
                'opening_cash' => $openingCash,
            ]);

            return $shift->load('membership.user');
        });
    }

    public function close(
        string $shiftUlid,
        string $actualCash,
        ?string $notes,
        string $idempotencyKey,
    ): CashierShift {
        $actualCash = $this->money($actualCash, 'actual_cash');
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($shiftUlid, $actualCash, $notes, $idempotencyKey): CashierShift {
            $this->cashierLock->lockCurrent();

            $existingReplay = CashierShift::query()
                ->where('tenant_id', $this->tenantContext->tenantId())
                ->where('close_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingReplay) {
                if (
                    $existingReplay->ulid !== $shiftUlid
                    || bccomp((string) ($existingReplay->actual_cash ?? '0'), $actualCash, 4) !== 0
                ) {
                    throw new ApiException(
                        'IDEMPOTENCY_KEY_CONFLICT',
                        'This Idempotency-Key was already used for a different cash shift close request.',
                        409,
                    );
                }

                return $existingReplay->load('membership.user');
            }

            $shift = CashierShift::query()
                ->where('tenant_id', $this->tenantContext->tenantId())
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('membership_id', $this->tenantContext->membershipId())
                ->where('ulid', $shiftUlid)
                ->with('membership.user')
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                throw new ApiException('NOT_FOUND', 'The requested cash shift was not found.', 404);
            }

            if (! $shift->isOpen()) {
                throw new ApiException(
                    'CASH_SHIFT_ALREADY_CLOSED',
                    'This cash shift is already closed and cannot be changed.',
                    409,
                );
            }

            $closedAt = now();
            $expectedCash = $this->expectedCash($shift, $closedAt);
            $difference = bcsub($actualCash, $expectedCash, 4);

            $shift->closed_at = $closedAt;
            $shift->status = CashierShiftStatus::Closed;
            $shift->expected_cash = $expectedCash;
            $shift->actual_cash = $actualCash;
            $shift->difference = $difference;
            $shift->close_idempotency_key = $idempotencyKey;
            if ($notes !== null) {
                $shift->notes = $this->nullableText($notes);
            }
            $shift->save();

            $this->audit->record('CASH_SHIFT_CLOSED', [
                'resource_type' => 'cashier_shift',
                'resource_ulid' => $shift->ulid,
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'difference' => $difference,
            ]);

            return $shift->refresh()->load('membership.user');
        });
    }

    public function expectedCash(CashierShift $shift, ?CarbonInterface $cutoff = null): string
    {
        $shift->loadMissing('membership');

        $cutoff ??= now();
        $cashAccountLines = JournalLine::query()
            ->select([
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.description',
                'journal_entries.document_type',
            ])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->where('journal_lines.tenant_id', $shift->tenant_id)
            ->where('journal_entries.tenant_id', $shift->tenant_id)
            ->where('journal_entries.branch_id', $shift->branch_id)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->where('journal_entries.posted_by', $shift->membership?->user_id)
            ->where('journal_entries.posted_at', '>=', $shift->opened_at)
            ->where('journal_entries.posted_at', '<=', $cutoff)
            ->where(function ($query): void {
                $query->where('account_types.is_cash', true)
                    ->orWhere('account_types.code', '0010');
            })
            ->orderBy('journal_entries.posted_at')
            ->orderBy('journal_entries.id')
            ->get();

        $movement = '0.0000';
        foreach ($cashAccountLines as $line) {
            if ($this->isNonDrawerPaymentLine((string) $line->document_type, $line->description)) {
                continue;
            }

            $movement = bcadd(
                $movement,
                bcsub((string) $line->debit, (string) $line->credit, 4),
                4,
            );
        }

        return bcadd((string) $shift->opening_cash, $movement, 4);
    }

    private function isNonDrawerPaymentLine(string $documentType, ?string $description): bool
    {
        if (! in_array($documentType, ['sale_payment', 'purchase_payment', 'sale_return_refund'], true)) {
            return false;
        }

        return in_array(strtolower(trim((string) $description)), ['card', 'bank', 'credit'], true);
    }

    private function money(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Amount must be a valid non-negative decimal with up to 4 places.',
            ]);
        }

        return bcadd($value, '0', 4);
    }

    private function idempotencyKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required for cash shift actions.',
                422,
            );
        }

        return $key;
    }

    private function nullableText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
