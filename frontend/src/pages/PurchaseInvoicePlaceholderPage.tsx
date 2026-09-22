import { FileText, Printer, RefreshCw, Save, X } from 'lucide-react'
import { DesktopButton } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'

export function PurchaseInvoicePlaceholderPage() {
  const { closeActiveTab } = useWorkspace()

  return (
    <div className="pos-invoice" style={{ gridTemplateColumns: '1fr' }}>
      <div className="pos-invoice-main">
        <div className="inner-tabs">
          <button type="button" className="inner-tab is-active">Purchase Invoice</button>
          <button type="button" className="inner-tab" disabled title="Available in a later phase">Pending Purchases</button>
          <button type="button" className="inner-tab" disabled title="Available in a later phase">Other Expenses</button>
          <button type="button" className="inner-tab" disabled title="Available in a later phase">Product Wise</button>
          <span style={{ marginLeft: 'auto', fontWeight: 800, fontSize: 16, padding: '4px 10px' }}>Purchase Invoice</span>
        </div>

        <div className="purchase-header">
          <div className="grid gap-1">
            <div className="flex flex-wrap gap-3 text-[11px]">
              <label className="flex items-center gap-1"><input type="checkbox" disabled /> Payment Due</label>
              <label className="flex items-center gap-1"><input type="checkbox" disabled /> On Hold</label>
            </div>
            <div className="dense-row is-2">
              <label>Inv#</label>
              <input className="desktop-input" disabled placeholder="Auto" />
              <label>Date</label>
              <input className="desktop-input" disabled />
            </div>
            <div className="dense-row is-2">
              <label>Payment</label>
              <select className="desktop-select" disabled>
                <option>CREDIT</option>
                <option>CASH</option>
              </select>
              <label>From</label>
              <input className="desktop-input" disabled placeholder="Vendor" />
            </div>
            <div className="dense-row">
              <label>Remarks</label>
              <textarea className="desktop-textarea" disabled rows={2} />
            </div>
          </div>

          <div className="balance-stack">
            <div className="row prev"><span>Previous</span><span>0</span></div>
            <div className="row this"><span>This Bill</span><span>0</span></div>
            <div className="row total"><span>Total Balance</span><span>0</span></div>
            <div className="dense-row" style={{ gridTemplateColumns: '60px 1fr', marginTop: 4 }}>
              <label>Disc %</label>
              <input className="desktop-input" disabled defaultValue="0" />
            </div>
            <div className="dense-row" style={{ gridTemplateColumns: '60px 1fr' }}>
              <label>Tax %</label>
              <input className="desktop-input" disabled defaultValue="0" />
            </div>
          </div>

          <div className="amt-stack">
            <div className="amt-box is-cyan"><span>Amount (Rs)</span><input disabled value="0" readOnly /></div>
            <div className="amt-box is-green"><span>Others</span><input disabled value="0" readOnly /></div>
            <div className="amt-box is-yellow"><span>Net Payable</span><input disabled value="0" readOnly /></div>
            <div className="amt-box is-red"><span>Disc.</span><input disabled value="0" readOnly /></div>
            <div className="amt-box is-red"><span>Tax</span><input disabled value="0" readOnly /></div>
          </div>
        </div>

        <div className="f1-row">
          <input className="f1-search" disabled placeholder="..." aria-label="Product entry" />
          <div className="f1-hint">F1 to Add New</div>
        </div>

        <div className="invoice-grid">
          <PosDataGrid
            columns={[
              { key: 'item', header: 'ITEM / PRODUCT DESCRIPTION', render: () => <span className="entry-cell" style={{ display: 'block', minHeight: 20 }} /> },
              { key: 'stock', header: 'In Stock', align: 'right', width: 70 },
              { key: 'qty', header: 'Qty', align: 'right', width: 60, render: () => <span className="col-yellow" style={{ display: 'block' }}>&nbsp;</span> },
              { key: 'price', header: 'Price (C)', align: 'right', width: 80, render: () => <span className="col-yellow" style={{ display: 'block' }}>&nbsp;</span> },
              { key: 'disc', header: 'Disc-Rs', align: 'right', width: 70 },
              { key: 'tax', header: 'Tax Amt', align: 'right', width: 70 },
              { key: 'amt', header: 'AMT', align: 'right', width: 80, render: () => <span className="col-cyan" style={{ display: 'block' }}>&nbsp;</span> },
              { key: 'margin', header: 'Margin', align: 'right', width: 70, render: () => <span className="col-green" style={{ display: 'block' }}>&nbsp;</span> },
              { key: 'sale', header: 'Sale Rate', align: 'right', width: 80, render: () => <span className="col-green" style={{ display: 'block' }}>&nbsp;</span> },
            ]}
            rows={[{ id: '1' }]}
            rowKey={(row) => row.id}
            selectedKey="1"
            emptyMessage=""
          />
        </div>

        <div className="invoice-bottom">
          <span className="text-[11px] text-[var(--text-muted)]">Record 0 of 0 · Purchase posting not implemented</span>
          <div className="flex gap-1">
            <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" disabled />
            <DesktopButton icon={<Printer size={13} />} label="Print" shortcut="F11" disabled />
            <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" disabled />
            <DesktopButton icon={<FileText size={13} />} label="Save & Print" disabled />
            <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
          </div>
        </div>
      </div>
    </div>
  )
}
