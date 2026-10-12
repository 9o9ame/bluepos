import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Save } from 'lucide-react'
import { fetchBusinessSettings, saveBusinessSettings } from '../../api/catalog'
import { useFeedback } from '../../feedback/FeedbackProvider'
import { useCan } from '../../features/auth/useCan'
import { UiButton } from '../ui/UiButton'
import { UiModal } from '../ui/UiModal'
import { UiSelect } from '../ui/UiSelect'
import './SoftwareOptionsDialog.css'

type SoftwareOptionsTab = 'sale' | 'purchase' | 'general'

function DisabledOption({ label, checked = false }: { label: string; checked?: boolean }) {
  return (
    <label className="software-options-check is-disabled">
      <input type="checkbox" checked={checked} disabled readOnly />
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

  const settingsQuery = useQuery({
    queryKey: ['business-settings'],
    queryFn: fetchBusinessSettings,
    enabled: open,
  })

  useEffect(() => {
    if (!settingsQuery.data) return
    setStockIsMust(!settingsQuery.data.negative_stock_allowed)
  }, [settingsQuery.data])

  const saveMutation = useMutation({
    mutationFn: () =>
      saveBusinessSettings({
        negative_stock_allowed: !stockIsMust,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['business-settings'] })
      feedback.success('Software options saved.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to save software options.'),
  })

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
            disabled={!canManage || settingsQuery.isLoading || saveMutation.isPending}
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
              <label className="software-options-inline-number is-disabled">
                <span>Inform Me about Expiry Before</span>
                <input value="60" disabled readOnly />
                <span>Days Ago…</span>
              </label>
              <DisabledOption label="Show Receiving Window on Saving Sales Invoice" />
              <DisabledOption label="Manual Change of Date in Sale Invoice and Sale Return" />
              <DisabledOption label="Don't Update Invoice Date To Current Date for Pending Invoices" />
              <DisabledOption label="Print Dotted Line between Items on Sales Invoice" checked />
              <DisabledOption label="Don't Round Off Amounts" />

              <label className="software-options-field is-disabled">
                <span>Message on Sales Invoice</span>
                <textarea disabled />
              </label>

              <label className="software-options-field is-disabled">
                <span>Print Heading On Barcode / Sale Invoice Print</span>
                <input disabled />
              </label>
            </section>

            <section>
              <DisabledOption label="Show Remarks" />
              <DisabledOption label="Show Dis %" checked />
              <DisabledOption label="Show Dis Rs" checked />
              <DisabledOption label="Show Pre Rates in Sales" checked />
              <DisabledOption label="Amount Editable" checked />
              <DisabledOption label="On Amount Change, Change Qty" checked />
              <DisabledOption label="Disable txtID In Sales Invoice" />

              <label className="software-options-field is-disabled">
                <span>FBR Message On Invoice</span>
                <textarea disabled />
              </label>

              <DisabledOption label="Show Qty Option In Products For Discount On" />
              <DisabledOption label="Invoice ON" />
              <DisabledOption label="Default Yes" />
              <DisabledOption label="Duplication Of Products Not ALLOWED (POS + RESTEURANT)" />

              <label className="software-options-check software-options-stock-must">
                <input
                  type="checkbox"
                  checked={stockIsMust}
                  disabled={!canManage || settingsQuery.isLoading || saveMutation.isPending}
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
              <DisabledOption label="Show Expiry and Batch Options" />
              <DisabledOption label="Show bonus Option" />
              <DisabledOption label="Show Sales Tax Option" />
              <DisabledOption label="Show Rate Change Windows When Rate is Changed" />
            </section>
            <section>
              <DisabledOption label="Show Vehicle,Bilty In Purchase" />
              <DisabledOption label="Amount is Editable in Purchase" />

              <div className="software-options-purchase-bottom">
                <DisabledOption label="Show Stock Taking Option" checked />
                <label className="software-options-field is-disabled">
                  <span>Stock Taking Method</span>
                  <UiSelect
                    value="full"
                    disabled
                    options={[
                      { value: 'full', label: '01: Stock Taking In Products Full' },
                      { value: 'simple', label: '02: Stock Taking in Products Simple' },
                      { value: 'individual', label: '03: Stock Taking Individual Transaction' },
                    ]}
                  />
                </label>
                <label className="software-options-field is-disabled">
                  <span>Default Vendor</span>
                  <input disabled />
                </label>
                <label className="software-options-field is-disabled">
                  <span>Default Customer</span>
                  <input value="CASH IN HAND" disabled readOnly />
                </label>
                <label className="software-options-field is-disabled">
                  <span>Heading on P.O.</span>
                  <input disabled />
                </label>
              </div>
            </section>
          </div>
        ) : null}

        {tab === 'general' ? (
          <div className="software-options-grid">
            <section>
              <label className="software-options-inline-number is-disabled">
                <span>View Invoices only for</span>
                <input value="2" disabled readOnly />
                <span>Days</span>
              </label>
              <label className="software-options-inline-number is-disabled">
                <span>View History only for</span>
                <input value="8" disabled readOnly />
                <span>Days (When F5 & F6 Key is Pressed)</span>
              </label>
              <DisabledOption label="Show Purchase Qty in Products" />
              <DisabledOption label="Weight in Invoices" />
              <DisabledOption label="Single User Mode for Product IDs (Network Disable)" />

              <fieldset className="software-options-font is-disabled">
                <legend>Custom Font</legend>
                <DisabledOption label="Use This Font In All" checked />
                <div className="software-options-font-row">
                  <UiSelect
                    value="hp-simplified"
                    disabled
                    options={[{ value: 'hp-simplified', label: 'HP Simplified' }]}
                  />
                  <span>Size:</span>
                  <input value="11" disabled readOnly />
                </div>
                <DisabledOption label="Apply Custom Font Style" />
                <div className="software-options-font-row">
                  <UiSelect value="" disabled options={[]} placeholder="" />
                  <span>Size:</span>
                  <input disabled />
                </div>
              </fieldset>

              <DisabledOption label="Use Last Purchase Rate to Calculate Profit in Invoice" checked />
              <DisabledOption label="Keep Item name , Selling Price ,Purchase Price in Product Definition" />
              <DisabledOption label="Auto Generate Barcodes" />
              <DisabledOption label="Recalc Sheet" />
              <DisabledOption label="Show Previous Balance for Regular Customers" checked />
              <DisabledOption label="Show Saleman in VCs" />

              <label className="software-options-field is-disabled">
                <span>Select Heading Option</span>
                <UiSelect value="" disabled options={[]} placeholder="" />
              </label>
              <div className="software-options-inline-options">
                <DisabledOption label="Direct Print" />
                <DisabledOption label="Book Set" />
              </div>
            </section>

            <section>
              <DisabledOption label="Sale as MODAL" />
              <DisabledOption label="Purchase As MODAL" />
              <label className="software-options-field is-disabled">
                <span>Freight</span>
                <input value="0" disabled readOnly />
              </label>
              <label className="software-options-field is-disabled">
                <span>Default Format</span>
                <input value="#,##0.###;(#,##0.###);" disabled readOnly />
              </label>
              <label className="software-options-field is-disabled">
                <span>Description 1</span>
                <input value="Desc" disabled readOnly />
              </label>
              <label className="software-options-field is-disabled">
                <span>Description 2</span>
                <input disabled />
              </label>
              <DisabledOption label="Disable Search Dates" />
              <DisabledOption label="Bill To Bill" />
              <DisabledOption label="Products Def With Group Option" />
            </section>
          </div>
        ) : null}

        <p className="software-options-note">
          Greyed options are reference-matched placeholders only. They will be enabled only when their real BluePOS behavior is implemented.
        </p>
      </div>
    </UiModal>
  )
}
