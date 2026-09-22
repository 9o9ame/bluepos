import { FileText, Printer, RefreshCw, Save, X } from 'lucide-react'
import { DesktopButton, Field } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'

export function SalesInvoicePlaceholderPage() {
  const { closeActiveTab } = useWorkspace()

  return (
    <div className="pos-invoice">
      <div className="pos-invoice-main">
        <div className="inner-tabs">
          <button type="button" className="inner-tab is-active">Sales Invoice</button>
          <button type="button" className="inner-tab" disabled title="Available in a later phase">(0) Pending Invoices</button>
          <button type="button" className="inner-tab" disabled title="Available in a later phase">Expenses</button>
        </div>

        <div className="invoice-meta" style={{ gridTemplateColumns: 'repeat(6, minmax(0, 1fr))' }}>
          <div className="flex items-center gap-2 text-[11px]" style={{ gridColumn: '1 / -1' }}>
            <span className="font-semibold">Invoice Options:</span>
            <label className="flex items-center gap-1"><input type="radio" name="sale-type" disabled defaultChecked /> Default</label>
            <label className="flex items-center gap-1"><input type="radio" name="sale-type" disabled /> Whole Sale</label>
            <label className="flex items-center gap-1"><input type="radio" name="sale-type" disabled /> Retail</label>
            <label className="flex items-center gap-1 ml-4"><input type="checkbox" disabled /> Payment Due</label>
            <label className="flex items-center gap-1"><input type="checkbox" disabled /> On Hold</label>
          </div>
          <Field label="Inv#"><input className="desktop-input" disabled placeholder="Auto" /></Field>
          <Field label="Date"><input className="desktop-input" disabled /></Field>
          <Field label="Qu #"><input className="desktop-input" disabled /></Field>
          <Field label="S.Man">
            <select className="desktop-select" disabled>
              <option>Default</option>
            </select>
          </Field>
          <Field label="To"><input className="desktop-input" disabled placeholder="CASH IN HAND" /></Field>
          <Field label="CNIC"><input className="desktop-input" disabled /></Field>
          <Field label="Disc %"><input className="desktop-input" disabled defaultValue="0" /></Field>
          <Field label="Tax %"><input className="desktop-input" disabled defaultValue="0" /></Field>
        </div>

        <div className="f1-row">
          <input className="f1-search" disabled placeholder="..." aria-label="Product entry" />
          <div className="f1-hint">F1 to Add New</div>
        </div>

        <div className="invoice-grid">
          <PosDataGrid
            columns={[
              { key: 'item', header: 'ITEM / PRODUCT DESCRIPTION', render: () => <span className="entry-cell" style={{ display: 'block', minHeight: 20 }} /> },
              { key: 'stock', header: 'In Stock', align: 'right', width: 80 },
              { key: 'qty', header: 'Sales Qty', align: 'right', width: 80 },
              { key: 'price', header: 'Price', align: 'right', width: 80 },
              { key: 'amt', header: 'AMT', align: 'right', width: 80 },
              { key: 'disc', header: 'Disc%', align: 'right', width: 70 },
              { key: 'discrs', header: 'Disc-Rs', align: 'right', width: 80 },
              { key: 'net', header: 'Net Amt', align: 'right', width: 90 },
            ]}
            rows={[{ id: '1' }]}
            rowKey={(row) => row.id}
            selectedKey="1"
            emptyMessage=""
          />
        </div>

        <div className="invoice-bottom">
          <span className="text-[11px]">
            Record 1 of 1
            <span className="ml-3 text-[var(--brand-blue)]">Ctrl+M = POS · Ctrl+G = A4 · Ctrl+H = A5</span>
          </span>
          <div className="flex gap-1">
            <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" disabled />
            <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" disabled />
            <DesktopButton icon={<FileText size={13} />} label="Preview" shortcut="F3" disabled />
            <DesktopButton icon={<Printer size={13} />} label="Print" shortcut="F11" disabled />
            <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
          </div>
        </div>
      </div>

      <aside className="pos-invoice-pay">
        <div className="retail-title">RETAIL INVOICE</div>
        <div className="pay-block">
          <Field label="Amount Options">
            <select className="desktop-select" disabled>
              <option>Default</option>
            </select>
          </Field>
          <Field label="Disc (Rs)">
            <input className="desktop-input pay-disc" disabled value="0" readOnly />
          </Field>
          <Field label="Payment Method">
            <select className="desktop-select" disabled style={{ background: '#cfe0f8' }}>
              <option>CASH IN HAND</option>
            </select>
          </Field>
          <Field label="Remarks">
            <textarea className="desktop-textarea" disabled rows={3} />
          </Field>
        </div>
        <div className="pay-block">
          <span>Total:</span>
          <div className="pay-total">0</div>
        </div>
        <div className="pay-block">
          <span>Received (F12):</span>
          <input className="pay-received" disabled />
        </div>
        <Field label="Credit Card">
          <input className="desktop-input" disabled value="0." readOnly style={{ background: '#d8c8f0' }} />
        </Field>
        <Field label="Balance">
          <input className="desktop-input" disabled value="0" readOnly />
        </Field>
      </aside>
    </div>
  )
}
