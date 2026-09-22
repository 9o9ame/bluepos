import { FormEvent, useState } from 'react'
import { RefreshCw, Save, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchBusinessSettings, saveBusinessSettings } from '../api/catalog'
import { ApiClientError } from '../api/client'
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'

export function BusinessSettingsPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const canManage = useCan('settings.manage')
  const query = useQuery({ queryKey: ['business-settings'], queryFn: fetchBusinessSettings })
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: saveBusinessSettings,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['business-settings'] })
    },
  })

  useWorkspaceHandlers({
    save: () => {
      (document.getElementById('settings-form') as HTMLFormElement | null)?.requestSubmit()
    },
    refresh: () => {
      void query.refetch()
    },
  })

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!query.data) {
      return
    }
    setError(null)
    const form = new FormData(event.currentTarget)
    try {
      await mutation.mutateAsync({
        business_name: String(form.get('business_name') ?? ''),
        legal_name: String(form.get('legal_name') || '') || null,
        phone: String(form.get('phone') || '') || null,
        email: String(form.get('email') || '') || null,
        address: String(form.get('address') || '') || null,
        city: String(form.get('city') || '') || null,
        country_code: String(form.get('country_code') ?? 'PK'),
        currency_code: String(form.get('currency_code') ?? 'PKR'),
        timezone: String(form.get('timezone') ?? ''),
        date_format: String(form.get('date_format') ?? ''),
        number_format: String(form.get('number_format') ?? ''),
        tax_registration_number: String(form.get('tax_registration_number') || '') || null,
        invoice_prefix: String(form.get('invoice_prefix') || '') || null,
        receipt_footer: String(form.get('receipt_footer') || '') || null,
        default_tax_percent: String(form.get('default_tax_percent') ?? '0'),
        negative_stock_allowed: form.get('negative_stock_allowed') === 'on',
        expiry_tracking_enabled: form.get('expiry_tracking_enabled') === 'on',
        batch_tracking_enabled: form.get('batch_tracking_enabled') === 'on',
        default_price_level: String(form.get('default_price_level') ?? 'retail'),
      })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to save settings.')
    }
  }

  if (!query.data) {
    return (
      <DesktopPanel title="Business settings">
        <p className="text-[12px]">Loading settings…</p>
      </DesktopPanel>
    )
  }

  const settings = query.data

  return (
    <DesktopPanel
      title="Business settings"
      toolbar={
        <>
          <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" disabled={!canManage || mutation.isPending} onClick={() => (document.getElementById('settings-form') as HTMLFormElement | null)?.requestSubmit()} />
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => void query.refetch()} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      <form id="settings-form" onSubmit={onSubmit}>
        <FormGroup title="Business identity">
          <Field label="Business name">
            <input className="desktop-input" name="business_name" defaultValue={settings.business_name} required />
          </Field>
          <Field label="Legal name">
            <input className="desktop-input" name="legal_name" defaultValue={settings.legal_name ?? ''} />
          </Field>
          <Field label="Phone">
            <input className="desktop-input" name="phone" defaultValue={settings.phone ?? ''} />
          </Field>
          <Field label="Email">
            <input className="desktop-input" name="email" defaultValue={settings.email ?? ''} />
          </Field>
          <Field label="Address" span2>
            <input className="desktop-input" name="address" defaultValue={settings.address ?? ''} />
          </Field>
          <Field label="City">
            <input className="desktop-input" name="city" defaultValue={settings.city ?? ''} />
          </Field>
          <Field label="Country">
            <input className="desktop-input" name="country_code" maxLength={2} defaultValue={settings.country_code} />
          </Field>
        </FormGroup>
        <FormGroup title="Regional">
          <Field label="Currency">
            <input className="desktop-input" name="currency_code" maxLength={3} defaultValue={settings.currency_code} />
          </Field>
          <Field label="Timezone">
            <input className="desktop-input" name="timezone" defaultValue={settings.timezone} />
          </Field>
          <Field label="Date format">
            <input className="desktop-input" name="date_format" defaultValue={settings.date_format} />
          </Field>
          <Field label="Number format">
            <input className="desktop-input" name="number_format" defaultValue={settings.number_format} />
          </Field>
        </FormGroup>
        <FormGroup title="Tax / invoice">
          <Field label="NTN / STRN">
            <input className="desktop-input" name="tax_registration_number" defaultValue={settings.tax_registration_number ?? ''} />
          </Field>
          <Field label="Invoice prefix">
            <input className="desktop-input" name="invoice_prefix" defaultValue={settings.invoice_prefix ?? ''} />
          </Field>
          <Field label="Receipt footer" span2>
            <input className="desktop-input" name="receipt_footer" defaultValue={settings.receipt_footer ?? ''} />
          </Field>
          <Field label="Default tax %">
            <input className="desktop-input" name="default_tax_percent" defaultValue={settings.default_tax_percent} />
          </Field>
          <Field label="Default price level">
            <select className="desktop-select" name="default_price_level" defaultValue={settings.default_price_level}>
              <option value="retail">Retail</option>
              <option value="wholesale">Wholesale</option>
              <option value="minimum_sale">Minimum sale</option>
            </select>
          </Field>
        </FormGroup>
        <FormGroup title="Inventory configuration">
          <label className="desktop-field"><span>Allow negative stock (config only)</span><input type="checkbox" name="negative_stock_allowed" defaultChecked={settings.negative_stock_allowed} /></label>
          <label className="desktop-field"><span>Expiry tracking</span><input type="checkbox" name="expiry_tracking_enabled" defaultChecked={settings.expiry_tracking_enabled} /></label>
          <label className="desktop-field"><span>Batch tracking</span><input type="checkbox" name="batch_tracking_enabled" defaultChecked={settings.batch_tracking_enabled} /></label>
        </FormGroup>
      </form>
    </DesktopPanel>
  )
}
