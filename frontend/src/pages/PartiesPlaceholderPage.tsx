import { useState } from 'react'
import { RefreshCw, Save, X } from 'lucide-react'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'

const DEMO_ROWS = [
  { id: '1', code: 'CASH', name: 'CASH IN HAND', contacts: '', accountType: 'ASSETS', type: 'ACCOUNT' },
  { id: '2', code: 'SALE', name: 'SALES', contacts: '', accountType: 'REVENUES', type: 'ACCOUNT' },
]

export function PartiesPlaceholderPage() {
  const { closeActiveTab } = useWorkspace()
  const [selectedKey, setSelectedKey] = useState<string | null>('1')
  const [subTab, setSubTab] = useState<'contact' | 'bank' | 'others' | 'opening'>('contact')

  return (
    <div className="classic-workspace is-parties">
      <section className="dense-form">
        <div className="form-group" style={{ margin: 4 }}>
          <div className="form-group-title">Personal Information</div>
          <div className="dense-form-body">
            <div className="flex gap-2">
              <div className="flex-1 grid gap-1">
                <div className="dense-row">
                  <label>ID / Code</label>
                  <input className="desktop-input" disabled placeholder="Auto" />
                </div>
                <div className="dense-row">
                  <label>Name</label>
                  <input className="desktop-input" disabled />
                </div>
                <div className="dense-row">
                  <label>Account Type</label>
                  <select className="desktop-select" disabled defaultValue="CUSTOMERS" style={{ background: 'var(--entry-yellow)' }}>
                    <option>CUSTOMERS</option>
                    <option>VENDORS</option>
                    <option>ACCOUNTS</option>
                  </select>
                </div>
              </div>
              <div className="photo-slot">Right Click to Add</div>
            </div>
            <div className="inner-tabs" style={{ marginTop: 4 }}>
              <button type="button" className={`inner-tab${subTab === 'contact' ? ' is-active' : ''}`} onClick={() => setSubTab('contact')}>
                Contact Info.
              </button>
              <button type="button" className={`inner-tab${subTab === 'bank' ? ' is-active' : ''}`} onClick={() => setSubTab('bank')}>
                Bank A/Cs
              </button>
              <button type="button" className={`inner-tab${subTab === 'others' ? ' is-active' : ''}`} onClick={() => setSubTab('others')}>
                Others
              </button>
              <button type="button" className={`inner-tab${subTab === 'opening' ? ' is-active' : ''}`} onClick={() => setSubTab('opening')}>
                Opening
              </button>
            </div>
            {subTab === 'contact' ? (
              <>
                <div className="dense-row">
                  <label>Address</label>
                  <input className="desktop-input" disabled />
                </div>
                <div className="dense-row is-2">
                  <label>Phone</label>
                  <input className="desktop-input" disabled />
                  <label>Email</label>
                  <input className="desktop-input" disabled />
                </div>
                <div className="dense-row is-2">
                  <label>CNIC</label>
                  <input className="desktop-input" disabled />
                  <label>NTN</label>
                  <input className="desktop-input" disabled />
                </div>
              </>
            ) : null}
            {subTab === 'bank' ? (
              <p className="later-banner" style={{ margin: 0 }}>Bank accounts — later phase.</p>
            ) : null}
            {subTab === 'others' ? (
              <div className="dense-row is-2">
                <label>Cr Limit</label>
                <input className="desktop-input" disabled />
                <label>
                  <input type="checkbox" disabled /> Discontinued
                </label>
                <span />
              </div>
            ) : null}
            {subTab === 'opening' ? (
              <p className="later-banner" style={{ margin: 0 }}>Opening balances — later phase.</p>
            ) : null}
          </div>
        </div>
        <div className="form-group" style={{ margin: '0 4px 4px' }}>
          <div className="form-group-title">Account Related Information</div>
          <div className="dense-form-body">
            <label className="flex items-center gap-2 text-[11px]">
              <input type="checkbox" disabled /> Invoice Related
            </label>
            <p className="later-banner" style={{ margin: 0 }}>
              Party / accounting domain is visual shell only. No postings yet.
            </p>
          </div>
        </div>
      </section>

      <section className="grid-pane">
        <div className="grid-pane-toolbar">
          <button type="button" className="desktop-btn" disabled title="Available in a later phase">
            <Save size={13} /> Save
          </button>
          <button type="button" className="desktop-btn" disabled>
            <RefreshCw size={13} /> Refresh
          </button>
          <button type="button" className="desktop-btn is-danger" onClick={closeActiveTab}>
            <X size={13} /> Close
          </button>
        </div>
        <PosDataGrid
          columns={[
            { key: 'code', header: 'CODE', width: 80, render: (row) => row.code },
            { key: 'name', header: 'Vendor / Customer / Accounts', render: (row) => row.name },
            { key: 'contacts', header: 'Contacts', width: 100, render: (row) => row.contacts },
            { key: 'atype', header: 'Account Type', width: 110, render: (row) => row.accountType },
            { key: 'type', header: 'TYPE', width: 90, render: (row) => row.type },
          ]}
          rows={DEMO_ROWS}
          rowKey={(row) => row.id}
          selectedKey={selectedKey}
          onSelect={(row) => setSelectedKey(row.id)}
          emptyMessage="No parties yet."
        />
        <div className="grid-pane-footer">
          <span>
            Record {selectedKey ?? 0} of {DEMO_ROWS.length}
            <span className="drcr-box">
              Dr <span>0</span>
              Cr <span>0</span>
            </span>
          </span>
          <span className="text-[10px] text-[var(--text-muted)]">Sample rows for layout only</span>
        </div>
      </section>

      <aside className="account-tree" aria-label="Chart of accounts">
        <strong>Account classification</strong>
        {['ASSETS', 'LIABILITIES', 'EXPENSES', 'REVENUES', 'CAPITAL', 'INVENTORY'].map((group) => (
          <details key={group} open={group === 'ASSETS'}>
            <summary>{group}</summary>
            <ul>
              <li>Sub-accounts appear after accounting phase</li>
            </ul>
          </details>
        ))}
      </aside>
    </div>
  )
}
