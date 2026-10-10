import { FileSearch, Plus, Printer, RefreshCw, Save, X, XCircle } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ApiClientError } from '../api/client'
import { fetchProducts } from '../api/catalog'
import { fetchParties } from '../api/parties'
import {
  createSaleQuotation,
  fetchSaleQuotation,
  fetchSaleQuotations,
  fetchSalesmen,
} from '../api/sales'
import type { Product } from '../types/catalog'
import type { SaleQuotation } from '../types/sales'
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { useSaleCart } from '../features/sales/useSaleCart'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import './QuotationEstimatePage.css'

function newQuotationKey(): string {
  return `quotation-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function errorMessage(error: unknown): string {
  if (error instanceof ApiClientError || error instanceof Error) return error.message
  return 'Unable to complete the quotation request.'
}

export function QuotationEstimatePage() {
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const cart = useSaleCart()
  const idempotencyKeyRef = useRef(newQuotationKey())

  const [tab, setTab] = useState<'entry' | 'search'>('entry')
  const [quotationDate, setQuotationDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [salesmanUlid, setSalesmanUlid] = useState<string | null>(null)
  const [productUlid, setProductUlid] = useState('')
  const [searchText, setSearchText] = useState('')
  const [savedQuotation, setSavedQuotation] = useState<SaleQuotation | null>(null)
  const [openedQuotation, setOpenedQuotation] = useState<SaleQuotation | null>(null)

  const activeQuotation = openedQuotation ?? savedQuotation
  const readOnly = Boolean(activeQuotation)

  const customersQuery = useQuery({
    queryKey: ['quotation-estimate', 'customers'],
    queryFn: () => fetchParties('customer'),
    retry: false,
  })

  const salesmenQuery = useQuery({
    queryKey: ['quotation-estimate', 'salesmen'],
    queryFn: fetchSalesmen,
    retry: false,
  })

  const productsQuery = useQuery({
    queryKey: ['quotation-estimate', 'products'],
    queryFn: () => fetchProducts(
      { per_page: 100, page: 1, active_only: true, sales_lookup: true },
      { busy: 'none' },
    ),
    retry: false,
  })

  const quotationsQuery = useQuery({
    queryKey: ['quotation-estimate', 'search', searchText],
    queryFn: () => fetchSaleQuotations({
      q: searchText.trim() || undefined,
      per_page: 50,
      page: 1,
    }),
    enabled: tab === 'search',
    retry: false,
  })

  const saveMutation = useMutation({
    mutationFn: () => {
      if (!cart.hasPaidLines) {
        throw new Error('Add at least one product before saving the estimate.')
      }

      const payload = cart.buildPayload()
      return createSaleQuotation(
        {
          price_type: cart.priceType,
          items: payload.items,
          free_lines: payload.free_lines,
          applied_schemes: payload.applied_schemes,
          customer_ulid: cart.customerUlid,
          salesman_ulid: salesmanUlid,
          notes: cart.notes || null,
          quotation_date: quotationDate,
        },
        idempotencyKeyRef.current,
      )
    },
    onSuccess: (quotation) => {
      setSavedQuotation(quotation)
      feedback.success(
        `Estimate ${quotation.document_number} saved. No stock, payment or accounting entry was posted.`,
        'Quotation / Estimate',
      )
    },
    onError: (error) => feedback.error(errorMessage(error), 'Quotation / Estimate'),
  })

  const openMutation = useMutation({
    mutationFn: (ulid: string) => fetchSaleQuotation(ulid),
    onSuccess: (quotation) => {
      setOpenedQuotation(quotation)
      setSavedQuotation(null)
      setQuotationDate(quotation.quotation_date)
      setSalesmanUlid(quotation.salesman?.ulid ?? null)
      setTab('entry')
    },
    onError: (error) => feedback.error(errorMessage(error), 'Quotation / Estimate'),
  })

  const productOptions = useMemo(
    () => (productsQuery.data?.data ?? []).map((product) => ({
      value: product.ulid,
      label: `${product.product_number} — ${product.name}`,
    })),
    [productsQuery.data],
  )

  const customerOptions = useMemo(
    () => [
      { value: '', label: 'Walk-in / No customer' },
      ...(customersQuery.data ?? []).map((customer) => ({
        value: customer.ulid,
        label: `${customer.code} — ${customer.name}`,
      })),
    ],
    [customersQuery.data],
  )

  const salesmanOptions = useMemo(
    () => [
      { value: '', label: 'No salesman' },
      ...(salesmenQuery.data ?? []).map((salesman) => ({
        value: salesman.ulid,
        label: `${salesman.code} — ${salesman.name}`,
      })),
    ],
    [salesmenQuery.data],
  )

  const entryRows = readOnly
    ? activeQuotation?.items ?? []
    : cart.paidLines

  function startNew() {
    cart.clear()
    cart.setPriceType('default')
    setQuotationDate(new Date().toISOString().slice(0, 10))
    setSalesmanUlid(null)
    setProductUlid('')
    setSavedQuotation(null)
    setOpenedQuotation(null)
    idempotencyKeyRef.current = newQuotationKey()
    setTab('entry')
  }

  function addSelectedProduct(value: string) {
    setProductUlid(value)
    if (!value || readOnly) return

    const product = (productsQuery.data?.data ?? []).find((row) => row.ulid === value)
    if (!product) return

    cart.addProduct(product as Product)
    setProductUlid('')
  }

  return (
    <DesktopPanel
      title="Quotation / Estimate"
      className="quotation-estimate-page"
      toolbar={
        <>
          <DesktopButton icon={<Plus size={15} />} label="New" onClick={startNew} />
          <DesktopButton
            icon={<Save size={15} />}
            label="Save Estimate"
            variant="success"
            disabled={readOnly || saveMutation.isPending || !cart.hasPaidLines}
            onClick={() => saveMutation.mutate()}
          />
          <DesktopButton
            icon={<FileSearch size={15} />}
            label="Search"
            variant="info"
            onClick={() => setTab('search')}
          />
          <DesktopButton
            icon={<Printer size={15} />}
            label="Print"
            variant="info"
            disabled={!activeQuotation}
            onClick={() => window.print()}
          />
          <DesktopButton
            icon={<RefreshCw size={15} />}
            label="Refresh"
            variant="info"
            onClick={() => {
              if (tab === 'search') void quotationsQuery.refetch()
              else startNew()
            }}
          />
          <DesktopButton icon={<X size={15} />} label="Close" variant="info" onClick={closeActiveTab} />
        </>
      }
    >
      <div className="quotation-tabs" role="tablist" aria-label="Quotation workspace">
        <button
          type="button"
          className={tab === 'entry' ? 'is-active' : ''}
          onClick={() => setTab('entry')}
        >
          Estimate
        </button>
        <button
          type="button"
          className={tab === 'search' ? 'is-active' : ''}
          onClick={() => setTab('search')}
        >
          Search
        </button>
      </div>

      {tab === 'entry' ? (
        <div className="quotation-entry">
          <div className="quotation-header-grid">
            <label>
              <span>Quotation #</span>
              <input
                className="desktop-input"
                value={activeQuotation?.document_number ?? 'Auto'}
                readOnly
              />
            </label>
            <label>
              <span>Date</span>
              <input
                className="desktop-input"
                type="date"
                value={quotationDate}
                disabled={readOnly}
                onChange={(event) => setQuotationDate(event.target.value)}
              />
            </label>
            <label>
              <span>Customer</span>
              <UiSelect
                value={activeQuotation?.customer?.ulid ?? cart.customerUlid ?? ''}
                disabled={readOnly}
                options={customerOptions}
                onChange={(value) => cart.setCustomerUlid(value || null)}
              />
            </label>
            <label>
              <span>Salesman</span>
              <UiSelect
                value={activeQuotation?.salesman?.ulid ?? salesmanUlid ?? ''}
                disabled={readOnly}
                options={salesmanOptions}
                onChange={(value) => setSalesmanUlid(value || null)}
              />
            </label>
            <label>
              <span>Price Type</span>
              <UiSelect
                value={activeQuotation?.price_type ?? cart.priceType}
                disabled={readOnly}
                options={[
                  { value: 'default', label: 'Default / Retail' },
                  { value: 'retail', label: 'Retail' },
                  { value: 'wholesale', label: 'Wholesale' },
                ]}
                onChange={(value) => cart.setPriceType(value as 'default' | 'retail' | 'wholesale')}
              />
            </label>
          </div>

          <div className="quotation-lines-wrap">
            <table className="quotation-lines">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Unit</th>
                  <th className="is-num">Qty</th>
                  <th className="is-num">Rate</th>
                  <th className="is-num">Disc %</th>
                  <th className="is-num">Disc Rs</th>
                  <th className="is-num">Tax %</th>
                  <th className="is-num">Amount</th>
                  {!readOnly ? <th aria-label="Remove" /> : null}
                </tr>
              </thead>
              <tbody>
                {entryRows.map((row) => {
                  if (readOnly) {
                    const item = row as SaleQuotation['items'][number]
                    return (
                      <tr key={item.ulid}>
                        <td>{item.product ? `${item.product.product_number} — ${item.product.name}` : '—'}</td>
                        <td>{item.unit?.symbol ?? item.unit?.code ?? '—'}</td>
                        <td className="is-num">{item.quantity}</td>
                        <td className="is-num">{item.unit_price}</td>
                        <td className="is-num">{item.discount_percent}</td>
                        <td className="is-num">{item.discount_amount}</td>
                        <td className="is-num">{item.tax_percent}</td>
                        <td className="is-num">{item.line_total}</td>
                      </tr>
                    )
                  }

                  const line = row as typeof cart.paidLines[number]
                  const preview = cart.linePreview(line)
                  return (
                    <tr key={line.line_key}>
                      <td>{line.product_number} — {line.product_name}</td>
                      <td>{line.unit_symbol ?? line.unit_code ?? '—'}</td>
                      <td className="is-num">
                        <input
                          aria-label={`Quantity for ${line.product_name}`}
                          value={line.quantity}
                          onChange={(event) => cart.setQuantity(line.line_key, event.target.value)}
                        />
                      </td>
                      <td className="is-num">{line.unit_price ?? '0.0000'}</td>
                      <td className="is-num">
                        <input
                          aria-label={`Discount percent for ${line.product_name}`}
                          value={line.discount_percent ?? '0'}
                          onChange={(event) => cart.setDiscountPercent(line.line_key, event.target.value)}
                        />
                      </td>
                      <td className="is-num">
                        <input
                          aria-label={`Discount amount for ${line.product_name}`}
                          value={line.discount_amount ?? '0'}
                          onChange={(event) => cart.setDiscountAmount(line.line_key, event.target.value)}
                        />
                      </td>
                      <td className="is-num">{line.tax_percent ?? '0'}</td>
                      <td className="is-num">{preview.netAmount}</td>
                      <td>
                        <UiButton
                          variant="danger"
                          aria-label={`Remove ${line.product_name}`}
                          onClick={() => cart.removeLine(line.line_key)}
                        >
                          <XCircle size={14} />
                        </UiButton>
                      </td>
                    </tr>
                  )
                })}
                {!readOnly ? (
                  <tr className="quotation-entry-row">
                    <td>
                      <UiSelect
                        value={productUlid}
                        options={[{ value: '', label: 'Search / select product' }, ...productOptions]}
                        onChange={addSelectedProduct}
                      />
                    </td>
                    <td>—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td />
                  </tr>
                ) : null}
                {readOnly && entryRows.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="quotation-empty">
                      No quotation lines found.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>

          <div className="quotation-footer-grid">
            <label>
              <span>Notes / Remarks</span>
              <textarea
                className="desktop-input"
                rows={3}
                value={activeQuotation?.notes ?? cart.notes}
                disabled={readOnly}
                onChange={(event) => cart.setNotes(event.target.value)}
              />
            </label>

            <div className="quotation-totals">
              <div><span>Subtotal</span><strong>{activeQuotation?.subtotal ?? cart.preview.subtotal}</strong></div>
              <div><span>Discount</span><strong>{activeQuotation?.discount_amount ?? cart.preview.discount}</strong></div>
              <div><span>Tax</span><strong>{activeQuotation?.tax_amount ?? cart.preview.tax}</strong></div>
              <div className="is-grand"><span>Estimate Total</span><strong>{activeQuotation?.grand_total ?? cart.preview.grandTotal}</strong></div>
            </div>
          </div>

          <div className="quotation-note">
            Quotation / Estimate is non-posting: it does not create stock movement, payment, receivable or journal entries.
          </div>
        </div>
      ) : (
        <div className="quotation-search">
          <div className="quotation-search-bar">
            <input
              className="desktop-input"
              placeholder="Quotation #, customer or salesman"
              value={searchText}
              onChange={(event) => setSearchText(event.target.value)}
            />
            <UiButton variant="info" onClick={() => void quotationsQuery.refetch()}>
              Search
            </UiButton>
          </div>

          <PosDataGrid
            columns={[
              { key: 'number', header: 'Quotation #', width: 150, render: (row) => row.document_number },
              { key: 'date', header: 'Date', width: 110, render: (row) => row.quotation_date },
              { key: 'customer', header: 'Customer', render: (row) => row.customer?.name ?? 'Walk-in' },
              { key: 'salesman', header: 'Salesman', render: (row) => row.salesman?.name ?? '—' },
              { key: 'total', header: 'Total', width: 140, align: 'right', render: (row) => row.grand_total },
            ]}
            rows={quotationsQuery.data?.data ?? []}
            rowKey={(row) => row.ulid}
            onActivate={(row) => openMutation.mutate(row.ulid)}
            emptyMessage="No quotations found."
          />
        </div>
      )}
    </DesktopPanel>
  )
}
