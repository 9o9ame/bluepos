import {
  CircleHelp,
  FileText,
  FolderOpen,
  Grid3X3,
  Printer,
  ReceiptText,
  RefreshCw,
  Save,
  StickyNote,
  XCircle,
} from 'lucide-react'
import { useState } from 'react'
import { useCan } from '../features/auth/useCan'
import { ColumnCustomizationPanel } from '../features/gridLayout/ColumnCustomizationPanel'
import {
  SALES_INVOICE_COLUMNS,
  SALES_INVOICE_SCREEN,
  type ResolvedGridColumn,
} from '../features/gridLayout/columnCatalog'
import { useColumnLayout } from '../features/gridLayout/useColumnLayout'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './SalesInvoicePlaceholderPage.theme.css'

/** Map logical keys → original sales CSS column classes (keeps reference geometry). */
const SALES_COL_CLASS: Record<string, string> = {
  selector: 'col-selector',
  product: 'col-product',
  in_stock: 'col-stock',
  sales_qty: 'col-qty',
  price: 'col-price',
  amt: 'col-amt',
  disc_pct: 'col-disc',
  disc_rs: 'col-discrs',
  net_amt: 'col-net',
  delete: 'col-delete',
}

function salesColClass(col: ResolvedGridColumn): string {
  return SALES_COL_CLASS[col.key] ?? `col-${col.key}`
}

export function SalesInvoicePlaceholderPage() {
  const { closeActiveTab } = useWorkspace()
  const [customizationOpen, setCustomizationOpen] = useState(false)
  const canSaveRoleDefault = useCan('roles.edit')
  const columnLayout = useColumnLayout({
    screenKey: SALES_INVOICE_SCREEN,
    catalog: SALES_INVOICE_COLUMNS,
  })

  return (
    <div className="sales-reference-screen">
      <main className="sales-reference-main">
        <nav className="sales-reference-subtabs" aria-label="Sales invoice views">
          <button type="button" className="sales-reference-subtab is-active">
            <span className="sales-reference-tab-icon is-blue">
              <Grid3X3 />
            </span>
            <span>Sales Invoice</span>
          </button>

          <button type="button" className="sales-reference-subtab" disabled>
            <span className="sales-reference-tab-icon is-yellow">
              <StickyNote />
            </span>
            <span>(0,Due:2) Pending Invoices</span>
          </button>

          <button type="button" className="sales-reference-subtab" disabled>
            <span className="sales-reference-tab-icon is-multi">
              <ReceiptText />
            </span>
            <span>Expenses</span>
          </button>
        </nav>

        <section className="sales-reference-meta">
          <fieldset className="sales-reference-options">
            <legend>Invoice Options</legend>

            <div className="sales-reference-options-head">
              <label>
                <input type="radio" name="sale-type" defaultChecked disabled /> Default
              </label>
              <label>
                <input type="radio" name="sale-type" disabled /> Whole Sale
              </label>
              <label>
                <input type="radio" name="sale-type" disabled /> Retail
              </label>

              <div className="sales-reference-copy-from">
                <span>Copy From:</span>
                <input defaultValue="0" disabled />
              </div>
            </div>

            <div className="sales-reference-option-grid">
              <label>Inv#:</label>
              <input className="is-short" placeholder="Auto" disabled />

              <label>Date</label>
              <input className="is-date" defaultValue="09/24/2026" disabled />

              <label>Qu #:</label>
              <div className="sales-reference-input-button">
                <input disabled />
                <button type="button" disabled>
                  ▼
                </button>
              </div>

              <label>S.Man:</label>
              <div className="sales-reference-input-button">
                <input defaultValue="Default" disabled />
                <button type="button" disabled>
                  ▼
                </button>
              </div>

              <label>To:</label>
              <div className="sales-reference-input-button sales-reference-to">
                <input defaultValue="CASH IN HAND" disabled />
                <button type="button" disabled>
                  +
                </button>
              </div>

              <label>Name:</label>
              <input disabled />

              <label>CNIC:</label>
              <input disabled />
            </div>
          </fieldset>

          <fieldset className="sales-reference-amounts">
            <legend>Amounts</legend>

            <div className="sales-reference-amounts-checks">
              <label>
                <input type="checkbox" disabled /> Payment Due
              </label>
              <label>
                <input type="checkbox" disabled /> On Hold
              </label>
            </div>

            <div className="sales-reference-amounts-grid">
              <span />
              <strong className="is-green">-</strong>

              <button type="button" disabled>
                Get
              </button>
              <label>Disc (%)</label>
              <label>Sales Tax (%)</label>

              <input defaultValue="0" disabled />
              <input defaultValue="0" disabled />
              <input defaultValue="0" disabled />
            </div>
          </fieldset>

          <div className="sales-reference-meta-spacer" aria-hidden />
        </section>

        <div className="sales-reference-entry-row">
          <input
            className="sales-reference-product-entry"
            disabled
            placeholder="..."
            aria-label="Product entry"
          />
          <div className="sales-reference-f1">F1 to Add New</div>
          <button type="button" className="sales-reference-sale-btn" disabled>
            Sale
          </button>
          <div className="sales-reference-entry-spacer" />
        </div>

        <div className="sales-reference-grid-wrap">
          <table className="sales-reference-grid">
            <colgroup>
              {columnLayout.visibleColumns.map((col) => (
                <col key={col.key} className={salesColClass(col)} />
              ))}
            </colgroup>

            <thead>
              <tr>
                {columnLayout.visibleColumns.map((col) => (
                  <th
                    key={col.key}
                    className={salesColClass(col)}
                    draggable={!col.locked}
                    onDragStart={(event) => {
                      if (col.locked) return
                      event.dataTransfer.setData('text/bp-col', col.key)
                      event.dataTransfer.effectAllowed = 'move'
                    }}
                    onDragOver={(event) => {
                      if (col.locked) return
                      event.preventDefault()
                    }}
                    onDrop={(event) => {
                      event.preventDefault()
                      const from = event.dataTransfer.getData('text/bp-col')
                      if (from) columnLayout.moveColumn(from, col.key)
                    }}
                    onContextMenu={(event) => {
                      event.preventDefault()
                      if (!col.locked) columnLayout.hideColumn(col.key)
                    }}
                    title={
                      col.key === 'delete'
                        ? 'Customize columns'
                        : col.locked
                          ? col.label
                          : `${col.label} — drag to move, right-click to hide`
                    }
                  >
                    {col.key === 'delete' ? (
                      <button
                        type="button"
                        className={`sales-reference-customize-trigger${customizationOpen ? ' is-open' : ''}`}
                        title="Customize columns"
                        aria-label="Customize columns"
                        aria-expanded={customizationOpen}
                        onClick={() => setCustomizationOpen((open) => !open)}
                      >
                        −
                      </button>
                    ) : col.key === 'selector' ? null : (
                      col.label
                    )}
                  </th>
                ))}
              </tr>
            </thead>

            <tbody>
              <tr className="is-entry-row">
                {columnLayout.visibleColumns.map((col) => {
                  if (col.key === 'selector') {
                    return (
                      <td key={col.key} className="sales-reference-row-arrow">
                        ›
                      </td>
                    )
                  }
                  if (col.key === 'product') {
                    return (
                      <td key={col.key} className="sales-reference-yellow">
                        <span className="sales-reference-dots">....</span>
                        <button type="button" className="sales-reference-product-drop" disabled>
                          ▼
                        </button>
                      </td>
                    )
                  }
                  if (col.key === 'delete') {
                    return (
                      <td key={col.key} className="sales-reference-delete-cell">
                        <button type="button" disabled aria-label="Delete row">
                          <XCircle size={16} />
                        </button>
                      </td>
                    )
                  }
                  return <td key={col.key} />
                })}
              </tr>
            </tbody>
          </table>

          <div className="sales-reference-grid-empty" />

          <div className="sales-reference-grid-totals">
            <div />
            <strong>0</strong>
            <strong>0</strong>
            <strong>0</strong>
          </div>

          <div className="sales-reference-record-nav">
            <div className="sales-reference-record-controls">
              <button type="button" disabled>
                ⏮
              </button>
              <button type="button" disabled>
                ◀
              </button>
              <span>Record 1 of 1</span>
              <button type="button" disabled>
                ▶
              </button>
              <button type="button" disabled>
                ⏭
              </button>
              <button type="button" disabled>
                +
              </button>
              <button type="button" disabled>
                −
              </button>
              <button type="button" disabled>
                ⌃
              </button>
              <button type="button" disabled>
                ⌄
              </button>
              <button type="button" disabled>
                ✓
              </button>
              <button type="button" disabled>
                ×
              </button>
            </div>
          </div>
        </div>

        <footer className="sales-reference-actions">
          <div className="sales-reference-shortcuts">
            <span>Ctrl+M = POS</span>
            <span>Ctrl+G = A4</span>
            <span>Ctrl+H = A5</span>
          </div>

          <div className="sales-reference-actions-center">
            <button type="button" disabled>
              <span>Save [F9]</span>
              <span className="sales-reference-action-icon is-save">
                <Save />
              </span>
            </button>

            <button type="button" disabled>
              <span>Refresh [F8]</span>
              <span className="sales-reference-action-icon is-refresh">
                <RefreshCw />
              </span>
            </button>

            <button type="button" disabled>
              <span>Preview [F3]</span>
              <span className="sales-reference-action-icon is-preview">
                <FileText />
              </span>
            </button>

            <button type="button" disabled>
              <span>Print [F11]</span>
              <span className="sales-reference-action-icon is-print">
                <Printer />
              </span>
            </button>

            <button type="button" onClick={closeActiveTab}>
              <span>Close</span>
              <span className="sales-reference-action-icon is-close">
                <XCircle />
              </span>
            </button>
          </div>
        </footer>
      </main>

      <aside className="sales-reference-pay">
        <div className="sales-reference-retail-title">RETAIL INVOICE</div>

        <fieldset className="sales-reference-pay-options">
          <legend>
            <span>Amount Options</span>
            <span className="sales-reference-pay-tools">
              <button type="button" disabled title="Open">
                <FolderOpen size={13} />
              </button>
              <button type="button" disabled title="Help">
                <CircleHelp size={13} />
              </button>
            </span>
          </legend>

          <div className="sales-reference-pay-head-values">
            <strong>0</strong>
            <strong>0</strong>
          </div>

          <div className="sales-reference-disc-line">
            <span>Disc (Rs):</span>
            <label>
              <input type="checkbox" disabled /> Cost
            </label>
          </div>

          <div className="sales-reference-disc-values">
            <strong>0</strong>
            <strong>0 %</strong>
          </div>

          <label className="sales-reference-payment-label">Payment Method:</label>
          <div className="sales-reference-payment-method">
            <strong>CASH IN HAND</strong>
            <button type="button" disabled>
              ▼
            </button>
          </div>

          <label className="sales-reference-remarks-label">Remarks:</label>
          <textarea disabled />
        </fieldset>

        <div className="sales-reference-total-block">
          <span>Total:</span>
          <div>0</div>
        </div>

        <label className="sales-reference-side-label">Received (F12):</label>
        <div className="sales-reference-received">
          <span />
          <strong>-</strong>
        </div>

        <label className="sales-reference-side-label">Credit Card:</label>
        <div className="sales-reference-card">
          <strong>0.</strong>
          <strong>-</strong>
        </div>

        <label className="sales-reference-side-label">Balance:</label>
        <div className="sales-reference-balance">
          <strong>0</strong>
          <strong>-</strong>
        </div>
      </aside>

      <ColumnCustomizationPanel
        open={customizationOpen}
        hiddenColumns={columnLayout.hiddenColumns}
        visibleColumns={columnLayout.visibleColumns}
        onClose={() => setCustomizationOpen(false)}
        onShow={columnLayout.showColumn}
        onHide={columnLayout.hideColumn}
        onToggleLock={columnLayout.toggleLock}
        onMove={columnLayout.moveColumn}
        onReset={columnLayout.resetToDefaults}
        onSaveRoleDefault={columnLayout.saveAsRoleDefault}
        canSaveRoleDefault={canSaveRoleDefault}
      />
    </div>
  )
}
