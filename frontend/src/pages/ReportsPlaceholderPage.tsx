import { useState } from 'react'
import { Eye, X } from 'lucide-react'
import { Field } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'

const DOCUMENTS = [
  'PURCHASES',
  'SALES INVOICE',
  'SALES RETURN',
  'PURCHASE RETURN',
  'ADJUSTMENTS',
  'VOUCHERS',
  'EXPENSES',
]

export function ReportsPlaceholderPage() {
  const { closeActiveTab } = useWorkspace()
  const [doc, setDoc] = useState(DOCUMENTS[1])

  return (
    <div className="report-workspace">
      <section className="grid-pane">
        <div className="desktop-panel-header">Vendor / Customer Ledger Options</div>
        <PosDataGrid
          columns={[
            { key: 'id', header: 'ID', width: 50, render: () => '' },
            { key: 'code', header: 'CODE', width: 80, render: () => '' },
            { key: 'name', header: 'Vendor / Customer / Accounts', render: () => '' },
            { key: 'contacts', header: 'Contacts', width: 120, render: () => '' },
            { key: 'atype', header: 'Account Type', width: 110, render: () => '' },
            { key: 'type', header: 'TYPE', width: 80, render: () => '' },
          ]}
          rows={[]}
          rowKey={() => 'none'}
          emptyMessage="Select report options below, then Preview. Report engines are a later phase."
        />
        <div className="grid-pane-footer">Count of Vendors / Customer / Account : 0</div>
      </section>

      <section className="report-filters" aria-label="Additional selection and actions">
        <div>
          <div className="text-[11px] font-bold mb-1">Select a Document</div>
          <div className="report-list">
            {DOCUMENTS.map((item) => (
              <button
                key={item}
                type="button"
                className={item === doc ? 'is-active' : undefined}
                onClick={() => setDoc(item)}
              >
                {item}
              </button>
            ))}
          </div>
        </div>

        <div className="grid gap-2 content-start">
          <Field label="Ledger type">
            <select className="desktop-select" disabled>
              <option>Summary Ledger</option>
              <option>Detail Ledger</option>
            </select>
          </Field>
          <Field label="Group by">
            <select className="desktop-select" disabled>
              <option>None</option>
              <option>Account Type</option>
            </select>
          </Field>
          <label className="flex items-center gap-2 text-[11px]">
            <input type="checkbox" disabled /> Ledger for All Dates
          </label>
          <label className="flex items-center gap-2 text-[11px]">
            <input type="checkbox" disabled /> Each Ledger on Separate Page
          </label>
        </div>

        <div className="grid gap-2 content-start">
          <Field label="From">
            <input className="desktop-input" type="date" disabled />
          </Field>
          <Field label="To">
            <input className="desktop-input" type="date" disabled />
          </Field>
          <Field label="Only for">
            <select className="desktop-select" disabled>
              <option>All</option>
              <option>Customers</option>
              <option>Vendors</option>
            </select>
          </Field>
        </div>

        <div className="flex flex-col gap-2 justify-end">
          <button type="button" className="desktop-btn is-primary" disabled title="Available in a later phase">
            <Eye size={14} /> Preview
          </button>
          <button type="button" className="desktop-btn is-danger" onClick={closeActiveTab}>
            <X size={14} /> Close
          </button>
        </div>
      </section>
    </div>
  )
}
