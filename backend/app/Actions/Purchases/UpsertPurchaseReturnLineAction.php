<?php

namespace App\Actions\Purchases;

use App\Enums\ProductStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseReturnStatus;
use App\Exceptions\ApiException;
use App\Models\Product;
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
     * @param  array<string,mixed>  $data
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

            $payload = $document->purchase_invoice_id !== null
                ? $this->invoicePayload($document, $data, $line)
                : $this->generalPayload($document, $data, $line);

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
                'return_mode' => $document->purchase_invoice_id === null ? 'general' : 'invoice',
            ]);

            return $line->fresh(['product', 'unit', 'purchaseInvoiceLine']) ?? $line;
        });
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function invoicePayload(
        PurchaseReturn $document,
        array $data,
        ?PurchaseReturnLine $line,
    ): array {
        $invoice = $document->purchaseInvoice;
        if (! $invoice || $invoice->status !== PurchaseInvoiceStatus::Posted) {
            throw ValidationException::withMessages([
                'purchase_line_ulid' => 'Original purchase invoice is not available for returns.',
            ]);
        }

        $purchaseLineUlid = trim((string) ($data['purchase_line_ulid'] ?? $line?->purchaseInvoiceLine?->ulid ?? ''));
        $purchaseLine = PurchaseInvoiceLine::query()
            ->with('product')
            ->where('purchase_invoice_id', $invoice->id)
            ->where('ulid', $purchaseLineUlid)
            ->lockForUpdate()
            ->first();

        if (! $purchaseLine) {
            throw ValidationException::withMessages([
                'purchase_line_ulid' => 'Purchase line was not found on the original invoice.',
            ]);
        }

        $duplicate = PurchaseReturnLine::query()
            ->where('purchase_return_id', $document->id)
            ->where('purchase_invoice_line_id', $purchaseLine->id)
            ->when($line !== null, fn ($query) => $query->where('id', '!=', $line->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'purchase_line_ulid' => 'This purchase line is already on the return.',
            ]);
        }

        $quantity = $this->normalizeQuantity((string) ($data['quantity'] ?? '0'));
        $conversion = (string) $purchaseLine->conversion_factor;
        $unitCost = (string) $purchaseLine->unit_cost;

        $proportional = $this->recalculate->proportionalCharges($purchaseLine, $quantity);
        $discount = array_key_exists('discount_amount', $data) && $data['discount_amount'] !== null
            ? $this->normalizeMoney((string) $data['discount_amount'], 'discount_amount')
            : $proportional['discount_amount'];
        $tax = array_key_exists('tax_amount', $data) && $data['tax_amount'] !== null
            ? $this->normalizeMoney((string) $data['tax_amount'], 'tax_amount')
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

        return [
            'purchase_invoice_line_id' => $purchaseLine->id,
            'product_id' => $purchaseLine->product_id,
            'unit_id' => $purchaseLine->unit_id,
            'quantity' => $quantity,
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
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function generalPayload(
        PurchaseReturn $document,
        array $data,
        ?PurchaseReturnLine $line,
    ): array {
        $productUlid = trim((string) ($data['product_ulid'] ?? $line?->product?->ulid ?? ''));

        $product = Product::query()
            ->forTenant($this->tenantContext->tenantId())
            ->with('baseUnit')
            ->where('ulid', $productUlid)
            ->where('is_active', true)
            ->where('status', ProductStatus::Active->value)
            ->first();

        if (! $product || ! $product->baseUnit || ! $product->baseUnit->is_active) {
            throw ValidationException::withMessages([
                'product_ulid' => 'The selected product or its base unit is unavailable.',
            ]);
        }

        if ($product->track_batch || $product->track_expiry) {
            throw ValidationException::withMessages([
                'product_ulid' => 'Batch or expiry tracked products must be returned against a posted purchase invoice.',
            ]);
        }

        $duplicate = PurchaseReturnLine::query()
            ->where('purchase_return_id', $document->id)
            ->whereNull('purchase_invoice_line_id')
            ->where('product_id', $product->id)
            ->when($line !== null, fn ($query) => $query->where('id', '!=', $line->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'product_ulid' => 'This product is already on the general purchase return.',
            ]);
        }

        $quantity = $this->normalizeQuantity((string) ($data['quantity'] ?? '0'));
        if (! $product->baseUnit->allows_decimal
            && str_contains($quantity, '.')
            && rtrim(substr(strstr($quantity, '.') ?: '', 1), '0') !== '') {
            throw ValidationException::withMessages([
                'quantity' => 'The selected product unit does not allow decimal quantities.',
            ]);
        }

        $unitCost = $this->normalizeMoney((string) ($data['unit_cost'] ?? ''), 'unit_cost');
        $discount = $this->normalizeMoney((string) ($data['discount_amount'] ?? '0'), 'discount_amount');
        $tax = $this->normalizeMoney((string) ($data['tax_amount'] ?? '0'), 'tax_amount');
        $amounts = $this->recalculate->lineAmounts($quantity, '1.00000000', $unitCost, $discount, $tax);

        return [
            'purchase_invoice_line_id' => null,
            'product_id' => $product->id,
            'unit_id' => $product->base_unit_id,
            'quantity' => $quantity,
            'conversion_factor' => '1.00000000',
            'base_quantity' => $amounts['base_quantity'],
            'unit_cost' => $unitCost,
            'discount_amount' => $amounts['discount_amount'],
            'tax_amount' => $amounts['tax_amount'],
            'line_total' => $amounts['line_total'],
            'batch_number' => null,
            'expiry_date' => null,
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function normalizeQuantity(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be a valid non-negative decimal with up to 6 places.',
            ]);
        }

        $normalized = bcadd($value, '0', 6);
        if (bccomp($normalized, '0', 6) !== 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Return quantity must be greater than zero.',
            ]);
        }

        return $normalized;
    }

    private function normalizeMoney(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Amount must be a valid non-negative decimal with up to 4 places.',
            ]);
        }

        return bcadd($value, '0', 4);
    }
}
