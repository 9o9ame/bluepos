<?php

namespace App\Actions\Purchases;

use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseReturnStatus;
use App\Exceptions\ApiException;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertPurchaseReturnLineAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecalculatePurchaseReturnTotalsAction $recalculate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     purchase_line_ulid: string,
     *     quantity: string,
     *     discount_amount?: string|null,
     *     tax_amount?: string|null,
     *     batch_number?: string|null,
     *     expiry_date?: string|null,
     *     reason?: string|null,
     *     notes?: string|null
     * }  $data
     */
    public function execute(
        PurchaseReturn $document,
        array $data,
        ?PurchaseReturnLine $line = null,
    ): PurchaseReturnLine {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted purchase returns cannot be edited.', 422);
        }

        return DB::transaction(function () use ($document, $data, $line): PurchaseReturnLine {
            $document = PurchaseReturn::query()
                ->with('purchaseInvoice')
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== PurchaseReturnStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted purchase returns cannot be edited.', 422);
            }

            $invoice = $document->purchaseInvoice;
            if (! $invoice || $invoice->status !== PurchaseInvoiceStatus::Posted) {
                throw ValidationException::withMessages([
                    'purchase_line_ulid' => 'Original purchase invoice is not available for returns.',
                ]);
            }

            $purchaseLine = PurchaseInvoiceLine::query()
                ->with('product')
                ->where('purchase_invoice_id', $invoice->id)
                ->where('ulid', $data['purchase_line_ulid'])
                ->lockForUpdate()
                ->first();

            if (! $purchaseLine) {
                throw ValidationException::withMessages([
                    'purchase_line_ulid' => 'Purchase line was not found on the original invoice.',
                ]);
            }

            if ($line === null) {
                $exists = PurchaseReturnLine::query()
                    ->where('purchase_return_id', $document->id)
                    ->where('purchase_invoice_line_id', $purchaseLine->id)
                    ->exists();
                if ($exists) {
                    throw ValidationException::withMessages([
                        'purchase_line_ulid' => 'This purchase line is already on the return.',
                    ]);
                }
            }

            $quantity = (string) $data['quantity'];
            $conversion = (string) $purchaseLine->conversion_factor;
            $unitCost = (string) $purchaseLine->unit_cost;

            $proportional = $this->recalculate->proportionalCharges($purchaseLine, $quantity);
            $discount = array_key_exists('discount_amount', $data) && $data['discount_amount'] !== null
                ? (string) $data['discount_amount']
                : $proportional['discount_amount'];
            $tax = array_key_exists('tax_amount', $data) && $data['tax_amount'] !== null
                ? (string) $data['tax_amount']
                : $proportional['tax_amount'];

            if (bccomp($discount, (string) $purchaseLine->discount_amount, 4) === 1) {
                throw ValidationException::withMessages([
                    'discount_amount' => 'Return discount cannot exceed the original purchase line discount.',
                ]);
            }
            if (bccomp($tax, (string) $purchaseLine->tax_amount, 4) === 1) {
                throw ValidationException::withMessages([
                    'tax_amount' => 'Return tax cannot exceed the original purchase line tax.',
                ]);
            }

            $amounts = $this->recalculate->lineAmounts($quantity, $conversion, $unitCost, $discount, $tax);
            $this->recalculate->assertReturnable($purchaseLine, $amounts['base_quantity']);

            $product = $purchaseLine->product;
            $batch = $data['batch_number'] ?? $purchaseLine->batch_number;
            $expiry = $data['expiry_date'] ?? ($purchaseLine->expiry_date?->toDateString());

            if ($product?->track_batch && (! is_string($batch) || trim($batch) === '')) {
                throw ValidationException::withMessages([
                    'batch_number' => 'Batch number is required for this product.',
                ]);
            }
            if ($product?->track_expiry && (! is_string($expiry) || trim($expiry) === '')) {
                throw ValidationException::withMessages([
                    'expiry_date' => 'Expiry date is required for this product.',
                ]);
            }

            $payload = [
                'purchase_invoice_line_id' => $purchaseLine->id,
                'product_id' => $purchaseLine->product_id,
                'unit_id' => $purchaseLine->unit_id,
                'quantity' => bcadd($quantity, '0', 6),
                'conversion_factor' => bcadd($conversion, '0', 8),
                'base_quantity' => $amounts['base_quantity'],
                'unit_cost' => bcadd($unitCost, '0', 4),
                'discount_amount' => $amounts['discount_amount'],
                'tax_amount' => $amounts['tax_amount'],
                'line_total' => $amounts['line_total'],
                'batch_number' => is_string($batch) ? trim($batch) : null,
                'expiry_date' => $expiry,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($line) {
                $line = PurchaseReturnLine::query()
                    ->whereKey($line->id)
                    ->where('purchase_return_id', $document->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $line->fill($payload);
                $line->save();
                $event = 'PURCHASE_RETURN_LINE_UPDATED';
            } else {
                $line = PurchaseReturnLine::query()->create([
                    'tenant_id' => $this->tenantContext->tenantId(),
                    'purchase_return_id' => $document->id,
                    ...$payload,
                ]);
                $event = 'PURCHASE_RETURN_LINE_ADDED';
            }

            $document->updated_by = $this->tenantContext->userId();
            $document->save();
            $this->recalculate->execute($document);

            $this->audit->record($event, [
                'resource_type' => 'purchase_return',
                'resource_ulid' => $document->ulid,
                'line_ulid' => $line->ulid,
            ]);

            return $line->fresh(['product', 'unit', 'purchaseInvoiceLine']) ?? $line;
        });
    }
}
