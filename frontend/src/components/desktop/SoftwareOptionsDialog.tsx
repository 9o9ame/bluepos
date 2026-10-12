import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Save } from 'lucide-react'
import { fetchBusinessSettings, fetchSuppliers, saveBusinessSettings } from '../../api/catalog'
import { fetchParties } from '../../api/parties'
import { fetchReportHeadings } from '../../api/reportHeadings'
import { useFeedback } from '../../feedback/FeedbackProvider'
import { useCan } from '../../features/auth/useCan'
import type { SoftwareOptions } from '../../types/catalog'
import { UiButton } from '../ui/UiButton'
import { UiModal } from '../ui/UiModal'
import { UiSelect } from '../ui/UiSelect'
import './SoftwareOptionsDialog.css'

type SoftwareOptionsTab = 'sale' | 'purchase' | 'general'

const DEFAULT_OPTIONS: SoftwareOptions = {
  sale: {
    expiry_warning_days: 60,
    show_receiving_window: false,
    manual_date_change: false,
    keep_pending_invoice_date: false,
    print_dotted_line: true,
    dont_round_off: false,
    message: null,
    show_remarks: true,
    show_discount_percent: true,
    show_discount_amount: true,
    show_previous_rates: false,
    amount_editable: false,
    amount_change_updates_qty: false,
    disable_txt_id: false,
    fbr_message: null,
    show_qty_option_discount: false,
    invoice_on: false,
    default_yes: false,
    disallow_duplicate_products: false,
  },
  purchase: {
    show_expiry_batch: false,
    show_bonus: false,
    show_sales_tax: true,
    show_rate_change_window: false,
    show_vehicle_bilty: false,
    amount_editable: false,
    show_stock_taking: true,
    stock_taking_method: 'full',
    default_vendor_ulid: null,
    default_customer_ulid: null,
    po_heading: null,
  },
  general: {
    view_invoices_days: 2,
    view_history_days: 8,
    show_purchase_qty: false,
    weight_in_invoices: false,
    single_user_product_ids: false,
    use_custom_font_all: false,
    font_family: 'Arial',
    font_size: 11,
    apply_custom_font_style: false,
    custom_font_family: null,
    custom_font_size: null,
    use_last_purchase_rate_profit: true,
    keep_product_prices: false,
    auto_generate_barcodes: false,
    recalc_sheet: false,
    show_previous_balance: true,
    show_salesman_vcs: false,
    heading_ulid: null,
    direct_print: false,
    book_set: false,
    sale_modal: false,
    purchase_modal: false,
    freight: '0',
    default_format: '#,##0.###;(#,##0.###);',
    description1: 'Desc',
    description2: null,
    disable_search_dates: false,
    bill_to_bill: false,
    products_group_option: false,
  },
}

const FONT_OPTIONS = [
  'Arial',
  'Calibri',
  'Courier New',
  'Georgia',
  'Inter',
  'Tahoma',
  'Times New Roman',
  'Verdana',
].map((value) => ({ value, label: value }))

function mergeOptions(source: Partial<SoftwareOptions> | Record<string, never> | undefined): SoftwareOptions {
  const value = source as Partial<SoftwareOptions> | undefined
  return {
    sale: { ...DEFAULT_OPTIONS.sale, ...(value?.sale ?? {}) },
    purchase: { ...DEFAULT_OPTIONS.purchase, ...(value?.purchase ?? {}) },
    general: { ...DEFAULT_OPTIONS.general, ...(value?.general ?? {}) },
  }
}

function OptionCheck({
  label,
  checked,
  disabled,
  onChange,
}: {
  label: string
  checked: boolean
  disabled: boolean
  onChange: (checked: boolean) => void
}) {
  return (
    <label className="software-options-check">
      <input
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(event) => onChange(event.target.checked)}
      />
      <span>{label}</span>
    </label>
  )
}

export function SoftwareOptionsDialog({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const feedback = useFeedback()
  const queryClient = useQueryClient()
  const canManage = useCan('settings.manage')
  const [tab, setTab] = useState<SoftwareOptionsTab>('sale')
  const [stockIsMust, setStockIsMust] = useState(true)
  const [options, setOptions] = useState<SoftwareOptions>(DEFAULT_OPTIONS)

  const settingsQuery = useQuery({
    queryKey: ['business-settings'],
    queryFn: fetchBusinessSettings,
    enabled: open,
  })

  const suppliersQuery = useQuery({
    queryKey: ['software-options', 'suppliers'],
    queryFn: fetchSuppliers,
    enabled: open,
  })

  const customersQuery = useQuery({
    queryKey: ['software-options', 'customers'],
    queryFn: () => fetchParties('customer'),
    enabled: open,
  })

  const headingsQuery = useQuery({
    queryKey: ['report-headings'],
    queryFn: fetchReportHeadings,
    enabled: open,
  })

  useEffect(() => {
    if (!settingsQuery.data) return
    setStockIsMust(!settingsQuery.data.negative_stock_allowed)
    setOptions(mergeOptions(settingsQuery.data.software_options))
  }, [settingsQuery.data])

  const saveMutation = useMutation({
    mutationFn: () =>
      saveBusinessSettings({
        negative_stock_allowed: !stockIsMust,
        software_options: options,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['business-settings'] })
      feedback.success('Software options saved.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to save software options.'),
  })

  const disabled = !canManage || settingsQuery.isLoading || saveMutation.isPending

  function patchSale<K extends keyof SoftwareOptions['sale']>(
    key: K,
    value: SoftwareOptions['sale'][K],
  ) {
    setOptions((current) => ({
      ...current,
      sale: { ...current.sale, [key]: value },
    }))
  }

  function patchPurchase<K extends keyof SoftwareOptions['purchase']>(
    key: K,
    value: SoftwareOptions['purchase'][K],
  ) {
    setOptions((current) => ({
      ...current,
      purchase: { ...current.purchase, [key]: value },
    }))
  }

  function patchGeneral<K extends keyof SoftwareOptions['general']>(
    key: K,
    value: SoftwareOptions['general'][K],
  ) {
    setOptions((current) => ({
      ...current,
      general: { ...current.general, [key]: value },
    }))
  }

  const activeSuppliers = (suppliersQuery.data ?? []).filter((row) => row.is_active)
  const activeCustomers = (customersQuery.data ?? []).filter((row) => row.is_active)

  return (
    <UiModal
      open={open}
      title="Software Options"
      ariaLabel="Software Options"
      onClose={onClose}
      size="lg"
      className="software-options-modal"
      bodyClassName="software-options-body"
      footer={
        <div className="software-options-footer">
          <UiButton
            variant="success"
            icon={<Save size={14} />}
            label={saveMutation.isPending ? 'Applying…' : 'Apply'}
            disabled={disabled}
            onClick={() => saveMutation.mutate()}
          />
          <UiButton variant="info" label="Close" onClick={onClose} />
        </div>
      }
    >
      <div className="software-options-tabs" role="tablist" aria-label="Software options sections">
        {([
          ['sale', 'Sale'],
          ['purchase', 'Purchase'],
          ['general', 'General'],
        ] as const).map(([value, label]) => (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={tab === value}
            className={tab === value ? 'is-active' : undefined}
            onClick={() => setTab(value)}
          >
            {label}
          </button>
        ))}
      </div>

      <div className="software-options-panel">
        {tab === 'sale' ? (
          <div className="software-options-grid">
            <section>
              <label className="software-options-inline-number">
                <span>Inform Me about Expiry Before</span>
                <input
                  type="number"
                  min={0}
                  max={3650}
                  value={options.sale.expiry_warning_days}
                  disabled={disabled}
                  onChange={(event) => patchSale('expiry_warning_days', Number(event.target.value) || 0)}
                />
                <span>Days Ago…</span>
              </label>
              <OptionCheck label="Show Receiving Window on Saving Sales Invoice" checked={options.sale.show_receiving_window} disabled={disabled} onChange={(value) => patchSale('show_receiving_window', value)} />
              <OptionCheck label="Manual Change of Date in Sale Invoice and Sale Return" checked={options.sale.manual_date_change} disabled={disabled} onChange={(value) => patchSale('manual_date_change', value)} />
              <OptionCheck label="Don't Update Invoice Date To Current Date for Pending Invoices" checked={options.sale.keep_pending_invoice_date} disabled={disabled} onChange={(value) => patchSale('keep_pending_invoice_date', value)} />
              <OptionCheck label="Print Dotted Line between Items on Sales Invoice" checked={options.sale.print_dotted_line} disabled={disabled} onChange={(value) => patchSale('print_dotted_line', value)} />
              <OptionCheck label="Don't Round Off Amounts" checked={options.sale.dont_round_off} disabled={disabled} onChange={(value) => patchSale('dont_round_off', value)} />

              <label className="software-options-field">
                <span>Message on Sales Invoice</span>
                <textarea value={options.sale.message ?? ''} disabled={disabled} onChange={(event) => patchSale('message', event.target.value || null)} />
              </label>

              <label className="software-options-field">
                <span>Print Heading On Barcode / Sale Invoice Print</span>
                <input value={options.purchase.po_heading ?? ''} disabled={disabled} onChange={(event) => patchPurchase('po_heading', event.target.value || null)} />
              </label>
            </section>

            <section>
              <OptionCheck label="Show Remarks" checked={options.sale.show_remarks} disabled={disabled} onChange={(value) => patchSale('show_remarks', value)} />
              <OptionCheck label="Show Dis %" checked={options.sale.show_discount_percent} disabled={disabled} onChange={(value) => patchSale('show_discount_percent', value)} />
              <OptionCheck label="Show Dis Rs" checked={options.sale.show_discount_amount} disabled={disabled} onChange={(value) => patchSale('show_discount_amount', value)} />
              <OptionCheck label="Show Pre Rates in Sales" checked={options.sale.show_previous_rates} disabled={disabled} onChange={(value) => patchSale('show_previous_rates', value)} />
              <OptionCheck label="Amount Editable" checked={options.sale.amount_editable} disabled={disabled} onChange={(value) => patchSale('amount_editable', value)} />
              <OptionCheck label="On Amount Change, Change Qty" checked={options.sale.amount_change_updates_qty} disabled={disabled} onChange={(value) => patchSale('amount_change_updates_qty', value)} />
              <OptionCheck label="Disable txtID In Sales Invoice" checked={options.sale.disable_txt_id} disabled={disabled} onChange={(value) => patchSale('disable_txt_id', value)} />

              <label className="software-options-field">
                <span>FBR Message On Invoice</span>
                <textarea value={options.sale.fbr_message ?? ''} disabled={disabled} onChange={(event) => patchSale('fbr_message', event.target.value || null)} />
              </label>

              <OptionCheck label="Show Qty Option In Products For Discount On" checked={options.sale.show_qty_option_discount} disabled={disabled} onChange={(value) => patchSale('show_qty_option_discount', value)} />
              <div className="software-options-inline-options">
                <OptionCheck label="Invoice ON" checked={options.sale.invoice_on} disabled={disabled} onChange={(value) => patchSale('invoice_on', value)} />
                <OptionCheck label="Default Yes" checked={options.sale.default_yes} disabled={disabled} onChange={(value) => patchSale('default_yes', value)} />
              </div>
              <OptionCheck label="Duplication Of Products Not ALLOWED (POS + RESTEURANT)" checked={options.sale.disallow_duplicate_products} disabled={disabled} onChange={(value) => patchSale('disallow_duplicate_products', value)} />

              <label className="software-options-check software-options-stock-must">
                <input
                  type="checkbox"
                  checked={stockIsMust}
                  disabled={disabled}
                  onChange={(event) => setStockIsMust(event.target.checked)}
                />
                <span>Stock is Must</span>
              </label>
            </section>
          </div>
        ) : null}

        {tab === 'purchase' ? (
          <div className="software-options-grid">
            <section>
              <OptionCheck label="Show Expiry and Batch Options" checked={options.purchase.show_expiry_batch} disabled={disabled} onChange={(value) => patchPurchase('show_expiry_batch', value)} />
              <OptionCheck label="Show bonus Option" checked={options.purchase.show_bonus} disabled={disabled} onChange={(value) => patchPurchase('show_bonus', value)} />
              <OptionCheck label="Show Sales Tax Option" checked={options.purchase.show_sales_tax} disabled={disabled} onChange={(value) => patchPurchase('show_sales_tax', value)} />
              <OptionCheck label="Show Rate Change Windows When Rate is Changed" checked={options.purchase.show_rate_change_window} disabled={disabled} onChange={(value) => patchPurchase('show_rate_change_window', value)} />
            </section>
            <section>
              <OptionCheck label="Show Vehicle,Bilty In Purchase" checked={options.purchase.show_vehicle_bilty} disabled={disabled} onChange={(value) => patchPurchase('show_vehicle_bilty', value)} />
              <OptionCheck label="Amount is Editable in Purchase" checked={options.purchase.amount_editable} disabled={disabled} onChange={(value) => patchPurchase('amount_editable', value)} />

              <div className="software-options-purchase-bottom">
                <OptionCheck label="Show Stock Taking Option" checked={options.purchase.show_stock_taking} disabled={disabled} onChange={(value) => patchPurchase('show_stock_taking', value)} />

                <label className="software-options-field">
                  <span>Stock Taking Method</span>
                  <UiSelect
                    value={options.purchase.stock_taking_method}
                    disabled={disabled || !options.purchase.show_stock_taking}
                    searchable={false}
                    options={[
                      { value: 'full', label: '01: Stock Taking In Products Full' },
                      { value: 'simple', label: '02: Stock Taking in Products Simple' },
                      { value: 'individual', label: '03: Stock Taking Individual Transaction' },
                    ]}
                    onChange={(value) => patchPurchase('stock_taking_method', value as SoftwareOptions['purchase']['stock_taking_method'])}
                  />
                </label>

                <label className="software-options-field">
                  <span>Default Vendor</span>
                  <UiSelect
                    value={options.purchase.default_vendor_ulid ?? ''}
                    disabled={disabled || suppliersQuery.isLoading}
                    options={[
                      { value: '', label: '— Not configured —' },
                      ...activeSuppliers.map((row) => ({ value: row.ulid, label: `${row.code} — ${row.name}` })),
                    ]}
                    onChange={(value) => patchPurchase('default_vendor_ulid', value || null)}
                  />
                </label>

                <label className="software-options-field">
                  <span>Default Customer</span>
                  <UiSelect
                    value={options.purchase.default_customer_ulid ?? ''}
                    disabled={disabled || customersQuery.isLoading}
                    options={[
                      { value: '', label: '— Not configured —' },
                      ...activeCustomers.map((row) => ({
                        value: row.customer_ulid ?? row.ulid,
                        label: `${row.code} — ${row.name}`,
                      })),
                    ]}
                    onChange={(value) => patchPurchase('default_customer_ulid', value || null)}
                  />
                </label>

                <label className="software-options-field">
                  <span>Heading on P.O.</span>
                  <input value={options.purchase.po_heading ?? ''} disabled={disabled} onChange={(event) => patchPurchase('po_heading', event.target.value || null)} />
                </label>
              </div>
            </section>
          </div>
        ) : null}

        {tab === 'general' ? (
          <div className="software-options-grid">
            <section>
              <label className="software-options-inline-number">
                <span>View Invoices only for</span>
                <input type="number" min={1} max={3650} value={options.general.view_invoices_days} disabled={disabled} onChange={(event) => patchGeneral('view_invoices_days', Math.max(1, Number(event.target.value) || 1))} />
                <span>Days</span>
              </label>
              <label className="software-options-inline-number">
                <span>View History only for</span>
                <input type="number" min={1} max={3650} value={options.general.view_history_days} disabled={disabled} onChange={(event) => patchGeneral('view_history_days', Math.max(1, Number(event.target.value) || 1))} />
                <span>Days (When F5 & F6 Key is Pressed)</span>
              </label>

              <div className="software-options-inline-options">
                <OptionCheck label="Show Purchase Qty in Products" checked={options.general.show_purchase_qty} disabled={disabled} onChange={(value) => patchGeneral('show_purchase_qty', value)} />
                <OptionCheck label="Weight in Invoices" checked={options.general.weight_in_invoices} disabled={disabled} onChange={(value) => patchGeneral('weight_in_invoices', value)} />
              </div>
              <OptionCheck label="Single User Mode for Product IDs (Network Disable)" checked={options.general.single_user_product_ids} disabled={disabled} onChange={(value) => patchGeneral('single_user_product_ids', value)} />

              <fieldset className="software-options-font">
                <legend>Custom Font</legend>
                <OptionCheck label="Use This Font In All" checked={options.general.use_custom_font_all} disabled={disabled} onChange={(value) => patchGeneral('use_custom_font_all', value)} />
                <div className="software-options-font-row">
                  <UiSelect value={options.general.font_family ?? ''} disabled={disabled || !options.general.use_custom_font_all} options={FONT_OPTIONS} onChange={(value) => patchGeneral('font_family', value || null)} />
                  <span>Size:</span>
                  <input type="number" min={8} max={48} value={options.general.font_size} disabled={disabled || !options.general.use_custom_font_all} onChange={(event) => patchGeneral('font_size', Number(event.target.value) || 11)} />
                </div>

                <OptionCheck label="Apply Custom Font Style" checked={options.general.apply_custom_font_style} disabled={disabled} onChange={(value) => patchGeneral('apply_custom_font_style', value)} />
                <div className="software-options-font-row">
                  <UiSelect value={options.general.custom_font_family ?? ''} disabled={disabled || !options.general.apply_custom_font_style} options={[{ value: '', label: '— Select font —' }, ...FONT_OPTIONS]} onChange={(value) => patchGeneral('custom_font_family', value || null)} />
                  <span>Size:</span>
                  <input type="number" min={8} max={48} value={options.general.custom_font_size ?? ''} disabled={disabled || !options.general.apply_custom_font_style} onChange={(event) => patchGeneral('custom_font_size', event.target.value ? Number(event.target.value) : null)} />
                </div>
              </fieldset>

              <OptionCheck label="Use Last Purchase Rate to Calculate Profit in Invoice" checked={options.general.use_last_purchase_rate_profit} disabled={disabled} onChange={(value) => patchGeneral('use_last_purchase_rate_profit', value)} />
              <OptionCheck label="Keep Item name , Selling Price ,Purchase Price in Product Definition" checked={options.general.keep_product_prices} disabled={disabled} onChange={(value) => patchGeneral('keep_product_prices', value)} />
              <div className="software-options-inline-options">
                <OptionCheck label="Auto Generate Barcodes" checked={options.general.auto_generate_barcodes} disabled={disabled} onChange={(value) => patchGeneral('auto_generate_barcodes', value)} />
                <OptionCheck label="Recalc Sheet" checked={options.general.recalc_sheet} disabled={disabled} onChange={(value) => patchGeneral('recalc_sheet', value)} />
              </div>
              <OptionCheck label="Show Previous Balance for Regular Customers" checked={options.general.show_previous_balance} disabled={disabled} onChange={(value) => patchGeneral('show_previous_balance', value)} />
              <OptionCheck label="Show Saleman in VCs" checked={options.general.show_salesman_vcs} disabled={disabled} onChange={(value) => patchGeneral('show_salesman_vcs', value)} />

              <label className="software-options-field">
                <span>Select Heading Option</span>
                <UiSelect
                  value={options.general.heading_ulid ?? ''}
                  disabled={disabled || headingsQuery.isLoading}
                  options={[
                    { value: '', label: '— Default heading —' },
                    ...(headingsQuery.data ?? []).map((row) => ({ value: row.ulid, label: row.used_for })),
                  ]}
                  onChange={(value) => patchGeneral('heading_ulid', value || null)}
                />
              </label>

              <div className="software-options-inline-options">
                <OptionCheck label="Direct Print" checked={options.general.direct_print} disabled={disabled} onChange={(value) => patchGeneral('direct_print', value)} />
                <OptionCheck label="Book Set" checked={options.general.book_set} disabled={disabled} onChange={(value) => patchGeneral('book_set', value)} />
              </div>
            </section>

            <section>
              <OptionCheck label="Sale as MODAL" checked={options.general.sale_modal} disabled={disabled} onChange={(value) => patchGeneral('sale_modal', value)} />
              <OptionCheck label="Purchase As MODAL" checked={options.general.purchase_modal} disabled={disabled} onChange={(value) => patchGeneral('purchase_modal', value)} />

              <label className="software-options-field">
                <span>Freight</span>
                <input inputMode="decimal" value={options.general.freight} disabled={disabled} onChange={(event) => patchGeneral('freight', event.target.value)} />
              </label>
              <label className="software-options-field">
                <span>Default Format</span>
                <input value={options.general.default_format ?? ''} disabled={disabled} onChange={(event) => patchGeneral('default_format', event.target.value || null)} />
              </label>
              <label className="software-options-field">
                <span>Description 1</span>
                <input value={options.general.description1 ?? ''} disabled={disabled} onChange={(event) => patchGeneral('description1', event.target.value || null)} />
              </label>
              <label className="software-options-field">
                <span>Description 2</span>
                <input value={options.general.description2 ?? ''} disabled={disabled} onChange={(event) => patchGeneral('description2', event.target.value || null)} />
              </label>

              <OptionCheck label="Disable Search Dates" checked={options.general.disable_search_dates} disabled={disabled} onChange={(value) => patchGeneral('disable_search_dates', value)} />
              <OptionCheck label="Bill To Bill" checked={options.general.bill_to_bill} disabled={disabled} onChange={(value) => patchGeneral('bill_to_bill', value)} />
              <OptionCheck label="Products Def With Group Option" checked={options.general.products_group_option} disabled={disabled} onChange={(value) => patchGeneral('products_group_option', value)} />
            </section>
          </div>
        ) : null}
      </div>
    </UiModal>
  )
}
