import type { AuthSession } from '../../types/auth'
import type { SaleQuotation } from '../../types/sales'

type QuotationPrintContext = Pick<
  AuthSession,
  'tenant' | 'branch' | 'warehouse'
>

function escapeHtml(value: string | null | undefined): string {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

function priceTypeLabel(value: SaleQuotation['price_type']): string {
  if (value === 'wholesale') return 'Wholesale'
  if (value === 'retail') return 'Retail'
  return 'Default / Retail'
}

function quotationHtml(
  quotation: SaleQuotation,
  context: QuotationPrintContext,
): string {
  const itemRows = quotation.items
    .map((item, index) => {
      const product = item.product
        ? `${item.product.product_number} — ${item.product.name}`
        : item.line_kind
      const unit = item.unit?.symbol ?? item.unit?.code ?? '—'
      const freeLabel = item.line_kind === 'sale' ? '' : ' <span class="free">FREE</span>'

      return `
        <tr>
          <td class="center">${index + 1}</td>
          <td>${escapeHtml(product)}${freeLabel}</td>
          <td>${escapeHtml(unit)}</td>
          <td class="num">${escapeHtml(item.quantity)}</td>
          <td class="num">${escapeHtml(item.unit_price)}</td>
          <td class="num">${escapeHtml(item.discount_percent)}</td>
          <td class="num">${escapeHtml(item.discount_amount)}</td>
          <td class="num">${escapeHtml(item.tax_percent)}</td>
          <td class="num">${escapeHtml(item.line_total)}</td>
        </tr>
      `
    })
    .join('')

  const notes = quotation.notes?.trim()
    ? `<section class="notes"><strong>Notes / Remarks</strong><div>${escapeHtml(quotation.notes)}</div></section>`
    : ''

  const customerHtml = quotation.customer
    ? `<strong>${escapeHtml(quotation.customer.name)}</strong><small>${escapeHtml(quotation.customer.code)}</small>`
    : '<strong>Walk-in / No customer</strong>'

  const salesmanHtml = quotation.salesman
    ? `<strong>${escapeHtml(quotation.salesman.name)}</strong><small>${escapeHtml(quotation.salesman.code ?? '')}</small>`
    : '<strong>No salesman</strong>'

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<title>${escapeHtml(quotation.document_number)} - Quotation</title>
<style>
  @page { size: A4 portrait; margin: 11mm 12mm; }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    color: #111827;
    background: #fff;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    line-height: 1.35;
  }
  .document {
    width: 100%;
    max-width: 190mm;
    margin: 0 auto;
  }
  .header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 230px;
    gap: 24px;
    align-items: center;
    padding: 0 0 12px;
    border-bottom: 2px solid #334155;
  }
  .business h1 {
    margin: 0;
    font-size: 23px;
    line-height: 1.05;
    letter-spacing: -.01em;
  }
  .business .sub {
    margin-top: 4px;
    color: #475569;
    font-size: 10.5px;
  }
  .doc-title {
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    text-align: right;
  }
  .doc-title h2 {
    margin: 0;
    font-size: 21px;
    line-height: 1.05;
    letter-spacing: .06em;
  }
  .doc-title strong {
    display: block;
    margin-top: 6px;
    font-size: 13px;
    letter-spacing: .02em;
  }
  .meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 7px 26px;
    margin: 12px 0 10px;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
  }
  .meta-row {
    display: grid;
    grid-template-columns: 92px minmax(0, 1fr);
    gap: 9px;
    align-items: start;
  }
  .meta-row span {
    color: #64748b;
    font-size: 10px;
  }
  .meta-row strong {
    display: block;
    overflow-wrap: anywhere;
    font-size: 10.5px;
  }
  .meta-row small {
    display: block;
    margin-top: 1px;
    color: #64748b;
    font-size: 9px;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
  }
  th, td {
    padding: 5px 6px;
    border: 1px solid #cbd5e1;
    vertical-align: top;
  }
  th {
    background: #e2e8f0;
    color: #1f2937;
    font-size: 9.5px;
    font-weight: 700;
    text-align: left;
  }
  .items tbody tr:nth-child(even) { background: #f8fafc; }
  .center { text-align: center; }
  .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }
  .free {
    margin-left: 4px;
    font-size: 9px;
    font-weight: 700;
  }
  .items th:nth-child(1) { width: 34px; }
  .items th:nth-child(2) { width: auto; }
  .items th:nth-child(3) { width: 58px; }
  .items th:nth-child(4) { width: 66px; }
  .items th:nth-child(5) { width: 72px; }
  .items th:nth-child(6) { width: 58px; }
  .items th:nth-child(7) { width: 70px; }
  .items th:nth-child(8) { width: 56px; }
  .items th:nth-child(9) { width: 82px; }
  .bottom {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 285px;
    gap: 16px;
    align-items: start;
    margin-top: 10px;
  }
  .notes {
    min-height: 64px;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    background: #fff;
  }
  .notes strong { display: block; margin-bottom: 5px; }
  .notes div { white-space: pre-wrap; }
  .totals {
    border: 1px solid #94a3b8;
    border-collapse: collapse;
    background: #fff;
  }
  .totals td {
    border: 0;
    border-bottom: 1px solid #e2e8f0;
    padding: 5px 8px;
  }
  .totals tr:last-child td {
    border-top: 2px solid #475569;
    border-bottom: 0;
    font-size: 13px;
    font-weight: 700;
  }
  .footnote {
    margin-top: 12px;
    padding-top: 8px;
    border-top: 1px solid #cbd5e1;
    color: #475569;
    font-size: 9.5px;
    text-align: center;
  }
  @media print {
    body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
  }
</style>
</head>
<body>
  <main class="document">
    <header class="header">
      <div class="business">
        <h1>${escapeHtml(context.tenant.name)}</h1>
        <div class="sub">
          ${escapeHtml(context.branch.name)} · ${escapeHtml(context.warehouse.name)}
        </div>
        <div class="sub">Currency: ${escapeHtml(context.tenant.currency_code)}</div>
      </div>
      <div class="doc-title">
        <h2>QUOTATION</h2>
        <strong>${escapeHtml(quotation.document_number)}</strong>
      </div>
    </header>

    <section class="meta">
      <div class="meta-row"><span>Quotation Date</span><strong>${escapeHtml(quotation.quotation_date)}</strong></div>
      <div class="meta-row"><span>Price Type</span><strong>${escapeHtml(priceTypeLabel(quotation.price_type))}</strong></div>
      <div class="meta-row"><span>Customer</span><div>${customerHtml}</div></div>
      <div class="meta-row"><span>Salesman</span><div>${salesmanHtml}</div></div>
      <div class="meta-row"><span>Branch</span><strong>${escapeHtml(quotation.branch.name)}</strong></div>
      <div class="meta-row"><span>Warehouse</span><strong>${escapeHtml(quotation.warehouse.name)}</strong></div>
    </section>

    <table class="items">
      <thead>
        <tr>
          <th>#</th>
          <th>Product</th>
          <th>Unit</th>
          <th class="num">Qty</th>
          <th class="num">Rate</th>
          <th class="num">Disc %</th>
          <th class="num">Disc Rs</th>
          <th class="num">Tax %</th>
          <th class="num">Amount</th>
        </tr>
      </thead>
      <tbody>
        ${itemRows || '<tr><td colspan="9" class="center">No quotation items.</td></tr>'}
      </tbody>
    </table>

    <section class="bottom">
      <div>${notes}</div>
      <table class="totals">
        <tr><td>Subtotal</td><td class="num">${escapeHtml(quotation.subtotal)}</td></tr>
        <tr><td>Discount</td><td class="num">${escapeHtml(quotation.discount_amount)}</td></tr>
        <tr><td>Tax</td><td class="num">${escapeHtml(quotation.tax_amount)}</td></tr>
        <tr><td>Estimate Total</td><td class="num">${escapeHtml(quotation.grand_total)}</td></tr>
      </table>
    </section>

    <div class="footnote">
      This quotation / estimate is non-posting and does not create stock movement,
      payment, receivable or journal entries.
    </div>
  </main>

  <script>
    window.addEventListener('load', function () {
      window.setTimeout(function () {
        window.focus();
        window.print();
      }, 150);
    });
  </script>
</body>
</html>`
}

export function printSaleQuotation(
  quotation: SaleQuotation,
  context: QuotationPrintContext,
): boolean {
  const printWindow = window.open('', '_blank', 'width=980,height=760')
  if (!printWindow) return false

  printWindow.document.open()
  printWindow.document.write(quotationHtml(quotation, context))
  printWindow.document.close()

  return true
}
