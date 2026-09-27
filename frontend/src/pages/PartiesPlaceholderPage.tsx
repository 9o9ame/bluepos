import { useMemo, useState } from 'react'
import {
  BookOpen,
  ChevronFirst,
  ChevronLast,
  ChevronLeft,
  ChevronRight,
  FileSpreadsheet,
  RefreshCw,
  Save,
  Table2,
  UsersRound,
  XCircle,
} from 'lucide-react'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './PartiesPlaceholderPage.theme.css'

const DEMO_ROWS = [
  { id: '1', no: '-1', name: 'JV', address: '', type: 'ACCOUNTS' },
  { id: '2', no: '1', name: 'CASH IN HAND', address: '', type: 'ALL' },
  { id: '3', no: '2', name: 'OPENING ACCOUNT', address: 'opening', type: 'ALL' },
  { id: '4', no: '3', name: 'ADJUSTMENT EXPENSES', address: '', type: 'ACCOUNTS' },
  { id: '5', no: '4', name: 'DISCOUNT ON SALES', address: '', type: 'ACCOUNTS' },
  { id: '6', no: '5', name: 'JV ENTRY', address: '', type: 'ACCOUNTS' },
  { id: '7', no: '6', name: 'SALE RETURN', address: '', type: 'ACCOUNTS' },
  { id: '8', no: '7', name: 'PURCHASES', address: '', type: 'ACCOUNTS' },
  { id: '9', no: '8', name: 'SURPLUS/LOSS ADJUSTMENT', address: '', type: 'ACCOUNTS' },
  { id: '10', no: '9', name: 'SALES TAX', address: '', type: 'ACCOUNTS' },
]

type DetailTab = 'contact' | 'bank' | 'others' | 'formulas' | 'opening'
type ViewTab = 'entry' | 'ledger' | 'bulk' | 'coa'

export function PartiesPlaceholderPage() {
  const { closeActiveTab } = useWorkspace()
  const [selectedKey, setSelectedKey] = useState('1')
  const [subTab, setSubTab] = useState<DetailTab>('contact')
  const [viewTab, setViewTab] = useState<ViewTab>('entry')

  const selectedIndex = Math.max(0, DEMO_ROWS.findIndex((row) => row.id === selectedKey))
  const accountGroups = useMemo(
    () => ['ASSETS', 'LIABILITIES', 'EXPENSES', 'REVENUES', 'CAPITAL', 'INVENTORY'],
    [],
  )

  const moveSelection = (index: number) => {
    const bounded = Math.min(Math.max(index, 0), DEMO_ROWS.length - 1)
    setSelectedKey(DEMO_ROWS[bounded].id)
  }

  return (
    <div className="parties-reference-screen">
      <header className="parties-reference-header">
        <h1>Vendor / Customers / Accounts</h1>

        <div className="parties-reference-header-icon" aria-hidden>
          <span className="parties-reference-header-icon-badge is-orange" />
          <span className="parties-reference-header-icon-badge is-blue" />
          <UsersRound size={48} />
        </div>

        <div className="parties-reference-header-actions">
          <button type="button" disabled><span>Save</span><Save size={26} /></button>
          <button type="button" disabled><span>Refresh</span><RefreshCw size={27} /></button>
          <button type="button" onClick={closeActiveTab}><span>Close</span><XCircle size={28} /></button>
        </div>
      </header>

      <nav className="parties-reference-view-tabs" aria-label="Account views">
        <button type="button" className={viewTab === 'entry' ? 'is-active' : undefined} onClick={() => setViewTab('entry')}>
          <UsersRound size={15} /><span>Data Entry Details</span>
        </button>
        <button type="button" className={viewTab === 'ledger' ? 'is-active' : undefined} onClick={() => setViewTab('ledger')}>
          <BookOpen size={15} /><span>Ledger</span>
        </button>
        <button type="button" className={viewTab === 'bulk' ? 'is-active' : undefined} onClick={() => setViewTab('bulk')}>
          <FileSpreadsheet size={15} /><span>Bulk Updation</span>
        </button>
        <button type="button" className={viewTab === 'coa' ? 'is-active' : undefined} onClick={() => setViewTab('coa')}>
          <Table2 size={15} /><span>Chart Of Account View</span>
        </button>
      </nav>

      {viewTab === 'entry' ? (
        <div className="parties-reference-main">
          <section className="parties-reference-left">
            <fieldset className="parties-reference-personal">
              <legend>Personal Information</legend>

              <div className="parties-reference-personal-grid">
                <div className="parties-reference-personal-fields">
                  <div className="parties-reference-row parties-reference-id-row">
                    <label>ID:</label><input value="208" disabled />
                    <label>CODE:</label><input disabled />
                    <label>Type:</label>
                    <select value="CUSTOMERS" disabled>
                      <option>CUSTOMERS</option><option>VENDORS</option><option>ACCOUNTS</option>
                    </select>
                  </div>

                  <div className="parties-reference-row"><label>Name:</label><input disabled /></div>

                  <div className="parties-reference-row parties-reference-deals-row">
                    <label>Deals In:</label><input disabled />
                  </div>
                </div>

                <div className="parties-reference-arrow-box" aria-hidden>
                  <button type="button" disabled>←</button>
                  <button type="button" disabled>→</button>
                </div>
              </div>

              <div className="parties-reference-subtabs">
                {[
                  ['contact', 'Contact Info.'],
                  ['bank', 'Bank A/Cs'],
                  ['others', 'Others'],
                  ['formulas', 'Formulas'],
                  ['opening', 'Opening'],
                ].map(([key, label]) => (
                  <button
                    key={key}
                    type="button"
                    className={subTab === key ? 'is-active' : undefined}
                    onClick={() => setSubTab(key as DetailTab)}
                  >
                    {label}
                  </button>
                ))}
              </div>

              <div className="parties-reference-subtab-panel">
                {subTab === 'contact' ? (
                  <div className="parties-reference-contact-layout">
                    <div className="parties-reference-contact-fields">
                      <div className="parties-reference-row"><label>Address:</label><textarea disabled /></div>
                      <div className="parties-reference-row"><label>Bill Address:</label><textarea disabled /></div>
                      <div className="parties-reference-row"><label>Cont. Person :</label><input disabled /></div>
                      <div className="parties-reference-row parties-reference-two-value-row">
                        <label>Mobile(s):</label><input disabled /><input disabled />
                      </div>
                      <div className="parties-reference-row parties-reference-two-value-row">
                        <label>Phone(s):</label><input disabled /><input disabled />
                      </div>
                      <div className="parties-reference-row"><label>Email:</label><input disabled /></div>
                    </div>

                    <button type="button" className="parties-reference-photo" disabled>Right Click to Add</button>
                  </div>
                ) : null}

                {subTab === 'bank' ? <div className="parties-reference-placeholder">Bank accounts — later accounting phase.</div> : null}
                {subTab === 'others' ? <div className="parties-reference-placeholder">Additional party details — later phase.</div> : null}
                {subTab === 'formulas' ? <div className="parties-reference-placeholder">Formulas are visual-only for now.</div> : null}
                {subTab === 'opening' ? <div className="parties-reference-placeholder">Opening accounting balances are not posted yet.</div> : null}
              </div>
            </fieldset>

            <fieldset className="parties-reference-account-info">
              <legend>Account Related Information</legend>

              <div className="parties-reference-account-checks">
                <label><input type="checkbox" disabled /><span>Discontinued</span></label>
                <label><input type="checkbox" disabled /><span>Invoice Related / Restricted</span></label>
              </div>

              <div className="parties-reference-account-grid">
                <label>Add %:</label><input value="0" disabled />
                <label>Cr Limit:</label><input value="0" disabled />
                <label>Days:</label><input value="0" disabled />

                <label>CNIC:</label><input disabled />
                <label>NTN:</label><input disabled />
                <label className="parties-reference-inline-check"><input type="checkbox" disabled /><span>STN:</span></label>
                <input disabled />
              </div>

              <div className="parties-reference-account-type-row">
                <label>Account Type:</label>
                <select value="LIABILITIES" disabled>
                  <option>LIABILITIES</option><option>ASSETS</option><option>EXPENSES</option>
                  <option>REVENUES</option><option>CAPITAL</option><option>INVENTORY</option>
                </select>
                <button type="button" disabled>▼</button>
                <button type="button" disabled className="parties-reference-account-type-btn">
                  <span>+ Account Type</span><span className="parties-reference-round-plus">+</span>
                </button>
              </div>
            </fieldset>
          </section>

          <section className="parties-reference-right">
            <div className="parties-reference-grid-wrap">
              <table className="parties-reference-grid">
                <colgroup>
                  <col className="col-marker" /><col className="col-row" /><col className="col-no" />
                  <col className="col-name" /><col className="col-address" /><col className="col-type" />
                </colgroup>
                <thead>
                  <tr><th /><th /><th>No</th><th>Name</th><th>Address</th><th>Type</th></tr>
                </thead>
                <tbody>
                  {DEMO_ROWS.map((row, index) => (
                    <tr
                      key={row.id}
                      className={row.id === selectedKey ? 'is-selected' : undefined}
                      onClick={() => setSelectedKey(row.id)}
                    >
                      <td className="parties-reference-filter-cell">{index === 0 ? '⌕' : ''}</td>
                      <td className="parties-reference-row-marker">{row.id === selectedKey ? '›' : ''}</td>
                      <td>{row.no}</td><td>{row.name}</td><td>{row.address}</td><td>{row.type}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="parties-reference-grid-footer">
              <div className="parties-reference-record-nav">
                <button type="button" onClick={() => moveSelection(0)} disabled={selectedIndex === 0}><ChevronFirst size={13} /></button>
                <button type="button" onClick={() => moveSelection(selectedIndex - 1)} disabled={selectedIndex === 0}><ChevronLeft size={13} /></button>
                <span>Record {selectedIndex + 1} of {DEMO_ROWS.length}</span>
                <button type="button" onClick={() => moveSelection(selectedIndex + 1)} disabled={selectedIndex === DEMO_ROWS.length - 1}><ChevronRight size={13} /></button>
                <button type="button" onClick={() => moveSelection(DEMO_ROWS.length - 1)} disabled={selectedIndex === DEMO_ROWS.length - 1}><ChevronLast size={13} /></button>
                <button type="button" disabled>+</button><button type="button" disabled>−</button>
                <button type="button" disabled>✓</button><button type="button" disabled>×</button>
              </div>

              <div className="parties-reference-drcr">
                <span>Dr:</span><strong>0</strong><span>Cr:</span><strong>0</strong>
              </div>
            </div>

            <div className="parties-reference-account-tree">
              <div className="parties-reference-tree-title">Account classification</div>
              {accountGroups.map((group, index) => (
                <details key={group} open={index === 0}>
                  <summary>
                    <span className="parties-reference-tree-icon">+</span>
                    <span>{group}</span>
                    <span className="parties-reference-tree-number">{index + 1}</span>
                  </summary>
                  <div className="parties-reference-tree-child">↳ Sub-accounts appear after accounting phase</div>
                </details>
              ))}
            </div>
          </section>
        </div>
      ) : (
        <div className="parties-reference-disabled-view">
          <strong>{viewTab === 'ledger' ? 'Ledger' : viewTab === 'bulk' ? 'Bulk Updation' : 'Chart Of Account View'}</strong>
          <span>Visual shell only for now — accounting behavior is intentionally not invented.</span>
        </div>
      )}
    </div>
  )
}
