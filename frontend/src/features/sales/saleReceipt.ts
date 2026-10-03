import type { Sale } from '../../types/sales'

/**
 * 80mm receipt preview/print. Opens a standalone window like barcode
 * printing does, so the POS screen keeps its state.
 */
function receiptHtml(sale: Sale): string {
  const items = sale.items
    .map(
      (item) => `
      <tr>
        <td>${escapeHtml(item.product ? `${item.product.product_number} ${item.product.name}` : item.line_kind)}</td>
      </tr>
      <tr class="sub">
        <td>${escapeHtml(item.quantity)} x ${escapeHtml(item.unit_price)}${item.line_kind !== 'sale' ? ' (FREE)' : ''}</td>
        <td class="r">${escapeHtml(item.line_total)}</td>
      </tr>`,
    )
    .join('')

  const payments = (sale.payments ?? [])
    .map(
      (p) => `<tr><td>${escapeHtml(p.method.toUpperCase())}${p.reference ? ` (${escapeHtml(p.reference)})` : ''}</td><td class="r">${escapeHtml(p.amount)}</td></tr>`,
    )
    .join('')

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<title>${escapeHtml(sale.document_number)}</title>
<style>
  @page { size: 80mm auto; margin: 4mm; }
  body { font-family: "Courier New", monospace; font-size: 11px; margin: 0; }
  .center { text-align: center; }
  .r { text-align: right; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 1px 0; vertical-align: top; }
  tr.sub td { color: #333; }
  .line { border-top: 1px dashed #000; margin: 6px 0; }
  h2 { font-size: 13px; margin: 4px 0; }
</style>
</head>
<body>
  <div class="center"><h2>RETAIL INVOICE</h2></div>
  <div>Invoice #: ${escapeHtml(sale.document_number)}</div>
  <div>Date: ${escapeHtml(sale.sale_date)}</div>
  <div>Customer: ${escapeHtml(sale.customer?.name ?? 'CASH IN HAND')}</div>
  <div>Branch: ${escapeHtml(sale.branch?.name ?? '')}</div>
  <div class="line"></div>
  <table>
    ${items || '<tr><td>(no items)</td></tr>'}
  </table>
  <div class="line"></div>
  <table>
    <tr><td>Subtotal</td><td class="r">${escapeHtml(sale.subtotal)}</td></tr>
    <tr><td>Discount</td><td class="r">${escapeHtml(sale.discount_amount)}</td></tr>
    <tr><td>Tax</td><td class="r">${escapeHtml(sale.tax_amount)}</td></tr>
    <tr><td><strong>TOTAL</strong></td><td class="r"><strong>${escapeHtml(sale.grand_total)}</strong></td></tr>
  </table>
  ${payments ? `<div class="line"></div><table>${payments}</table>` : ''}
  <div class="line"></div>
  <div class="center">Thank you</div>
  <script>
    window.addEventListener('load', function () {
      window.setTimeout(function () {
        window.focus();
        __AUTOPRINT__;
      }, 150);
    });
  </script>
</body>
</html>`
}

function escapeHtml(value: string | null | undefined): string {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function openReceipt(sale: Sale, autoPrint: boolean) {
  const printWindow = window.open('', '_blank', 'width=420,height=640')
  if (!printWindow) return
  printWindow.document.open()
  printWindow.document.write(receiptHtml(sale).replace('__AUTOPRINT__', autoPrint ? 'window.print()' : ''))
  printWindow.document.close()
}

/** Preview: opens the receipt window without triggering the print dialog. */
export function previewSaleReceipt(sale: Sale): void {
  openReceipt(sale, false)
}

/** Print: opens the receipt window and triggers the print dialog. */
export function printSaleReceipt(sale: Sale): void {
  openReceipt(sale, true)
}
