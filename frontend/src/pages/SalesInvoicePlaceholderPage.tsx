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
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './SalesInvoicePlaceholderPage.theme.css'

export function SalesInvoicePlaceholderPage() {
  const { closeActiveTab } = useWorkspace()

  return (
    <div className="sales-reference-screen">
      <main className="sales-reference-main">
        <nav className="sales-reference-subtabs" aria-label="Sales invoice views">
          <button type="button" className="sales-reference-subtab is-active">
            <span className="sales-reference-tab-icon is-blue"><Grid3X3 /></span>
            <span>Sales Invoice</span>
          </button>

          <button type="button" className="sales-reference-subtab" disabled>
            <span className="sales-reference-tab-icon is-yellow"><StickyNote /></span>
            <span>(0,Due:2) Pending Invoices</span>
          </button>

          <button type="button" className="sales-reference-subtab" disabled>
            <span className="sales-reference-tab-icon is-multi"><ReceiptText /></span>
            <span>Expenses</span>
          </button>

        </nav>

        <section className="sales-reference-meta">
          <fieldset className="sales-reference-options">
            <legend>Invoice Options</legend>

            <div className="sales-reference-options-head">
              <label><input type="radio" name="sale-type" defaultChecked disabled /> Default</label>
              <label><input type="radio" name="sale-type" disabled /> Whole Sale</label>
              <label><input type="radio" name="sale-type" disabled /> Retail</label>

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
                <button type="button" disabled>▼</button>
              </div>

              <label>S.Man:</label>
              <div className="sales-reference-input-button">
                <input defaultValue="Default" disabled />
                <button type="button" disabled>▼</button>
              </div>

              <label>To:</label>
              <div className="sales-reference-input-button sales-reference-to">
                <input defaultValue="CASH IN HAND" disabled />
                <button type="button" disabled>+</button>
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
              <label><input type="checkbox" disabled /> Payment Due</label>
              <label><input type="checkbox" disabled /> On Hold</label>
            </div>

            <div className="sales-reference-amounts-grid">
              <span />
              <strong className="is-green">-</strong>

              <button type="button" disabled>Get</button>
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
          <button type="button" className="sales-reference-sale-btn" disabled>Sale</button>
          <div className="sales-reference-entry-spacer" />
        </div>

        <div className="sales-reference-grid-wrap">
          <table className="sales-reference-grid">
            <colgroup>
              <col className="col-selector" />
              <col className="col-product" />
              <col className="col-stock" />
              <col className="col-qty" />
              <col className="col-price" />
              <col className="col-amt" />
              <col className="col-disc" />
              <col className="col-discrs" />
              <col className="col-net" />
              <col className="col-delete" />
            </colgroup>

            <thead>
              <tr>
                <th />
                <th>ITEM / PRODUCT DESCRIPTION</th>
                <th>In Stock</th>
                <th>Sales Qty</th>
                <th>Price</th>
                <th>AMT</th>
                <th>Disc%</th>
                <th>Disc-Rs</th>
                <th>Net Amt</th>
                <th>-</th>
              </tr>
            </thead>

            <tbody>
              <tr className="is-entry-row">
                <td className="sales-reference-row-arrow">›</td>
                <td className="sales-reference-yellow">
                  <span className="sales-reference-dots">....</span>
                  <button type="button" className="sales-reference-product-drop" disabled>▼</button>
                </td>
                <td />
                <td />
                <td />
                <td />
                <td />
                <td />
                <td />
                <td className="sales-reference-delete-cell">
                  <button type="button" disabled aria-label="Delete row"><XCircle size={16} /></button>
                </td>
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
              <button type="button" disabled>⏮</button>
              <button type="button" disabled>◀</button>
              <span>Record 1 of 1</span>
              <button type="button" disabled>▶</button>
              <button type="button" disabled>⏭</button>
              <button type="button" disabled>+</button>
              <button type="button" disabled>−</button>
              <button type="button" disabled>⌃</button>
              <button type="button" disabled>⌄</button>
              <button type="button" disabled>✓</button>
              <button type="button" disabled>×</button>
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
              <span className="sales-reference-action-icon is-save"><Save /></span>
            </button>

            <button type="button" disabled>
              <span>Refresh [F8]</span>
              <span className="sales-reference-action-icon is-refresh"><RefreshCw /></span>
            </button>

            <button type="button" disabled>
              <span>Preview [F3]</span>
              <span className="sales-reference-action-icon is-preview"><FileText /></span>
            </button>

            <button type="button" disabled>
              <span>Print [F11]</span>
              <span className="sales-reference-action-icon is-print"><Printer /></span>
            </button>

            <button type="button" onClick={closeActiveTab}>
              <span>Close</span>
              <span className="sales-reference-action-icon is-close"><XCircle /></span>
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
              <button type="button" disabled title="Open"><FolderOpen size={13} /></button>
              <button type="button" disabled title="Help"><CircleHelp size={13} /></button>
            </span>
          </legend>

          <div className="sales-reference-pay-head-values">
            <strong>0</strong>
            <strong>0</strong>
          </div>

          <div className="sales-reference-disc-line">
            <span>Disc (Rs):</span>
            <label><input type="checkbox" disabled /> Cost</label>
          </div>

          <div className="sales-reference-disc-values">
            <strong>0</strong>
            <strong>0 %</strong>
          </div>

          <label className="sales-reference-payment-label">Payment Method:</label>
          <div className="sales-reference-payment-method">
            <strong>CASH IN HAND</strong>
            <button type="button" disabled>▼</button>
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
    </div>
  )
}
