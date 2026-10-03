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
import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ApiClientError } from '../api/client'
import { fetchProducts } from '../api/catalog'
import { evaluateSaleOffers } from '../api/saleSchemes'
import { createSale } from '../api/sales'
import { PackagingPicker } from '../features/sales/PackagingPicker'
import { SchemeOfferPrompt } from '../features/sales/SchemeOfferPrompt'
import { useSaleCart } from '../features/sales/useSaleCart'
import { useCan } from '../features/auth/useCan'
import type { SaleOfferEvaluation } from '../types/saleSchemes'
import type { Sale } from '../types/sales'
import { ColumnCustomizationPanel } from '../features/gridLayout/ColumnCustomizationPanel'
import {
  SALES_INVOICE_COLUMNS,
  SALES_INVOICE_SCREEN,
  type ResolvedGridColumn,
} from '../features/gridLayout/columnCatalog'
import { useColumnLayout } from '../features/gridLayout/useColumnLayout'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './SalesInvoicePlaceholderPage.theme.css'
import './SalesInvoicePlaceholderPage.cart.css'

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
  const canAddPackaging = useCan('sales.give_free_packaging')
  const canApplyScheme = useCan('sales.apply_scheme')
  const canCreateSale = useCan('sales.create')
  const columnLayout = useColumnLayout({
    screenKey: SALES_INVOICE_SCREEN,
    catalog: SALES_INVOICE_COLUMNS,
  })

  const cart = useSaleCart()
  const [productQuery, setProductQuery] = useState('')
  const [saveError, setSaveError] = useState<string | null>(null)
  const [savedSale, setSavedSale] = useState<Sale | null>(null)

  // Product lookup for the entry row.
  const productsQuery = useQuery({
    queryKey: ['products', 'pos-entry', productQuery],
    queryFn: () => fetchProducts({ q: productQuery || undefined, per_page: 20, page: 1 }),
    enabled: productQuery.trim().length > 0,
  })

  /**
   * Eligibility is advisory only. The server revalidates the threshold on save,
   * so this just drives which options we show.
   */
  const offersQuery = useQuery({
    queryKey: ['sale-offers', cart.paidLines.length, cart.appliedSchemeUlids.length],
    queryFn: () =>
      evaluateSaleOffers({
        subtotal: '0.0000',
        document_date: new Date().toISOString().slice(0, 10),
      }),
    enabled: cart.hasPaidLines,
    retry: false,
  })

  const offers: SaleOfferEvaluation | undefined = offersQuery.data

  const packagingQtyInCart = useMemo(() => {
    const map: Record<string, string> = {}
    for (const line of cart.packagingLines) {
      map[line.product_ulid] = line.quantity
    }
    return map
  }, [cart.packagingLines])

  // A fresh key per attempt; a retry of the same attempt reuses it so the
  // server replays instead of creating a second sale.
  const idempotencyKeyRef = useRef(newSaleKey())

  const saveMutation = useMutation({
    mutationFn: async () => createSale(cart.buildPayload(), idempotencyKeyRef.current),
    onSuccess: (sale) => {
      setSavedSale(sale)
      setSaveError(null)
      cart.clear()
      setProductQuery('')
      idempotencyKeyRef.current = newSaleKey()
    },
    onError: (err) => {
      setSavedSale(null)
      setSaveError(
        err instanceof ApiClientError ? err.message : 'Unable to save the sale.',
      )
    },
  })

  function newSaleKey(): string {
    return `pos-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
  }

  function addProductFromEntry(productUlid: string) {
    const product = productsQuery.data?.data?.find((row) => row.ulid === productUlid)
    if (!product) return
    cart.addProduct(product, '1.000000')
    setProductQuery('')
  }

  const visibleSchemes = useMemo(() => {
    const list = offers?.schemes ?? []
    return canApplyScheme ? list : []
  }, [offers, canApplyScheme])

  const visiblePackaging = useMemo(() => {
    const list = offers?.packaging ?? []
    return canAddPackaging ? list : []
  }, [offers, canAddPackaging])

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

        <div className="sales-pos-status">
          {saveError ? (
            <span className="sales-pos-status-error">{saveError}</span>
          ) : savedSale ? (
            <span className="sales-pos-status-ok">
              Saved {savedSale.document_number} — total {savedSale.grand_total}
            </span>
          ) : cart.hasPaidLines ? (
            <span>{cart.paidLines.length} line(s) ready to save</span>
          ) : (
            <span>Add a product to start a sale</span>
          )}
        </div>

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
                        <input
                          className="sales-pos-product-entry"
                          value={productQuery}
                          placeholder="Type product name or number, then Enter"
                          onChange={(e) => setProductQuery(e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key !== 'Enter') return
                            const first = productsQuery.data?.data?.[0]
                            if (first) addProductFromEntry(first.ulid)
                          }}
                        />
                        {productQuery.trim().length > 0 && productsQuery.data?.data?.length ? (
                          <ul className="sales-pos-product-results">
                            {productsQuery.data.data.slice(0, 8).map((row) => (
                              <li key={row.ulid}>
                                <button
                                  type="button"
                                  onClick={() => addProductFromEntry(row.ulid)}
                                >
                                  <span>{row.product_number}</span> {row.name}
                                </button>
                              </li>
                            ))}
                          </ul>
                        ) : null}
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

              {cart.lines.map((line) => (
                <tr
                  key={`${line.line_kind}-${line.product_ulid}`}
                  className={
                    line.line_kind === 'sale'
                      ? 'sales-pos-line'
                      : 'sales-pos-line is-free'
                  }
                >
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
                        <td key={col.key}>
                          <span className="sales-pos-line-product">
                            {line.product_number} — {line.product_name}
                          </span>
                          {line.line_kind !== 'sale' ? (
                            <span className="sales-pos-line-badge">
                              {line.line_kind === 'free_scheme' ? 'Free scheme' : 'Free packaging'}
                            </span>
                          ) : null}
                        </td>
                      )
                    }
                    if (col.key === 'sales_qty') {
                      return (
                        <td key={col.key}>
                          <input
                            className="sales-pos-line-qty"
                            value={line.quantity}
                            disabled={line.line_kind !== 'sale'}
                            onChange={(e) =>
                              cart.setQuantity(line.product_ulid, e.target.value)
                            }
                          />
                        </td>
                      )
                    }
                    if (col.key === 'delete') {
                      return (
                        <td key={col.key} className="sales-reference-delete-cell">
                          <button
                            type="button"
                            aria-label="Delete row"
                            onClick={() => cart.removeLine(line.product_ulid, line.line_kind)}
                          >
                            <XCircle size={16} />
                          </button>
                        </td>
                      )
                    }
                    // Prices and totals are decided by the server on save.
                    if (col.key === 'amt' || col.key === 'net_amt') {
                      return (
                        <td key={col.key} className="sales-pos-line-pending">
                          {line.line_kind === 'sale' ? '—' : '0.00'}
                        </td>
                      )
                    }
                    return <td key={col.key} />
                  })}
                </tr>
              ))}
            </tbody>
          </table>

          <div className="sales-pos-offers">
            <SchemeOfferPrompt
              schemes={visibleSchemes}
              appliedUlids={cart.appliedSchemeUlids}
              onAdd={cart.addSchemeReward}
              onSkip={cart.skipScheme}
            />

            <PackagingPicker
              packaging={visiblePackaging}
              currentQty={packagingQtyInCart}
              onAdd={cart.addPackaging}
            />
          </div>

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
            <button
              type="button"
              disabled={!canCreateSale || !cart.hasPaidLines || saveMutation.isPending}
              onClick={() => saveMutation.mutate()}
            >
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
