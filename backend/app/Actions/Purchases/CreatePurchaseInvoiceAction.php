<?php

namespace App\Actions\Purchases;

use App\Catalog\TenantCatalog;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePurchaseInvoiceAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data): PurchaseInvoice {
            $tenantId = $this->tenantContext->tenantId();
            $supplier = $this->catalog->supplier($data['supplier_ulid']);
            $requestedWarehouse = $this->catalog->warehouse($data['warehouse_ulid']);
            $warehouse = $this->tenantContext->warehouse();

            if ((int) $requestedWarehouse->id !== (int) $warehouse->id) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Purchase warehouse must match the active warehouse context.',
                ]);
            }

            if (! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_ulid' => 'Purchases cannot use an inactive supplier.',
                ]);
            }
            if ($warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Purchases cannot use an inactive warehouse.',
                ]);
            }

            $purchaseOrder = null;
            if (! empty($data['purchase_order_ulid'])) {
                $purchaseOrder = PurchaseOrder::query()
                    ->forTenant($tenantId)
                    ->where('branch_id', $warehouse->branch_id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('ulid', (string) $data['purchase_order_ulid'])
                    ->lockForUpdate()
                    ->first();

                if (! $purchaseOrder) {
                    throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
                }

                if ((int) $purchaseOrder->supplier_id !== (int) $supplier->id) {
                    throw ValidationException::withMessages([
                        'supplier_ulid' => 'Purchase Invoice supplier must match the linked Purchase Order.',
                    ]);
                }
            }

            $invoice = PurchaseInvoice::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $purchaseOrder?->id,
                'document_number' => $this->nextDocumentNumber($tenantId),
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'po_number' => $purchaseOrder?->document_number ?? ($data['po_number'] ?? null),
                'invoice_type' => $data['invoice_type'] ?? 'tax_gst',
                'currency_code' => $data['currency_code'] ?? 'PKR',
                'calculation_method' => $data['calculation_method'] ?? 'gst_on_trade',
                'default_sales_tax_pct' => $this->pct($data['default_sales_tax_pct'] ?? '0'),
                'default_further_tax_pct' => $this->pct($data['default_further_tax_pct'] ?? '0'),
                'default_advance_tax_pct' => $this->pct($data['default_advance_tax_pct'] ?? '0'),
                'default_price_type' => $data['default_price_type'] ?? 'trade',
                'brand_label' => $data['brand_label'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'status' => PurchaseInvoiceStatus::Draft,
                'subtotal' => '0.0000',
                'discount_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'further_tax_amount' => '0.0000',
                'freight_amount' => $this->money($data['freight_amount'] ?? '0'),
                'loading_amount' => $this->money($data['loading_amount'] ?? '0'),
                'other_charges' => $this->money($data['other_charges'] ?? '0'),
                'other_discount' => $this->money($data['other_discount'] ?? '0'),
                'trade_offer' => $this->money($data['trade_offer'] ?? '0'),
                'advance_tax_amount' => $this->money($data['advance_tax_amount'] ?? '0'),
                'round_off' => $this->signedMoney($data['round_off'] ?? '0'),
                'grand_total' => '0.0000',
                'notes' => $data['notes'] ?? null,
                'tax_type' => $data['tax_type'] ?? 'standard',
                'payment_terms' => $data['payment_terms'] ?? 'credit',
                'created_by' => $this->tenantContext->userId(),
            ]);

            $invoice->grand_total = bcadd(
                bcadd(
                    bcadd((string) $invoice->freight_amount, (string) $invoice->loading_amount, 4),
                    (string) $invoice->other_charges,
                    4,
                ),
                bcsub(
                    bcadd((string) $invoice->advance_tax_amount, (string) $invoice->round_off, 4),
                    bcadd((string) $invoice->other_discount, (string) $invoice->trade_offer, 4),
                    4,
                ),
                4,
            );
            $invoice->save();

            $this->audit->record('PURCHASE_CREATED', [
                'resource_type' => 'purchase_invoice',
                'resource_ulid' => $invoice->ulid,
            ]);

            return $invoice->fresh(static::with()) ?? $invoice;
        });
    }

    /**
     * @return list<string>
     */
    public static function with(): array
    {
        return [
            'supplier',
            'branch',
            'warehouse',
            'purchaseOrder',
            'lines.product',
            'lines.unit',
            'lines.purchaseOrderLine',
        ];
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        $latest = PurchaseInvoice::query()
            ->forTenant($tenantId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('document_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/PUR-(\d+)$/', $latest, $matches) === 1) {
            $seq = (int) $matches[1] + 1;
        }

        return 'PUR-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function money(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be a valid non-negative decimal.',
            ]);
        }

        return bcadd($value, '0', 4);
    }

    private function signedMoney(string $value): string
    {
        if (! preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'round_off' => 'Round off must be a valid decimal.',
            ]);
        }

        return bcadd($value, '0', 4);
    }

    private function pct(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', $value)) {
            throw ValidationException::withMessages([
                'percent' => 'Percentage must be a valid non-negative decimal.',
            ]);
        }
        if (bccomp($value, '100', 8) === 1) {
            throw ValidationException::withMessages([
                'percent' => 'Percentage cannot exceed 100.',
            ]);
        }

        return bcadd($value, '0', 8);
    }
}
