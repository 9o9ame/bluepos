import { FormEvent, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchBusinessSettings, saveBusinessSettings } from '../api/catalog'
import { ApiClientError } from '../api/client'
import { useCan } from '../features/auth/useCan'

export function BusinessSettingsPage() {
  const queryClient = useQueryClient()
  const canManage = useCan('settings.manage')
  const query = useQuery({ queryKey: ['business-settings'], queryFn: fetchBusinessSettings })
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: saveBusinessSettings,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['business-settings'] })
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
    return <p className="text-[12px]">Loading settings…</p>
  }

  const settings = query.data
  const field = 'h-8 rounded border px-2 text-[12px]'

  return (
    <section className="space-y-3">
      <h2 className="text-base font-semibold">Business settings</h2>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      <form className="grid gap-2 rounded border border-slate-300 bg-white p-3 md:grid-cols-2" onSubmit={onSubmit}>
        <input className={field} name="business_name" defaultValue={settings.business_name} required />
        <input className={field} name="legal_name" placeholder="Legal name" defaultValue={settings.legal_name ?? ''} />
        <input className={field} name="phone" placeholder="Phone" defaultValue={settings.phone ?? ''} />
        <input className={field} name="email" placeholder="Email" defaultValue={settings.email ?? ''} />
        <input className={`${field} md:col-span-2`} name="address" placeholder="Address" defaultValue={settings.address ?? ''} />
        <input className={field} name="city" placeholder="City" defaultValue={settings.city ?? ''} />
        <input className={field} name="country_code" maxLength={2} defaultValue={settings.country_code} />
        <input className={field} name="currency_code" maxLength={3} defaultValue={settings.currency_code} />
        <input className={field} name="timezone" defaultValue={settings.timezone} />
        <input className={field} name="date_format" defaultValue={settings.date_format} />
        <input className={field} name="number_format" defaultValue={settings.number_format} />
        <input className={field} name="tax_registration_number" placeholder="NTN / STRN" defaultValue={settings.tax_registration_number ?? ''} />
        <input className={field} name="invoice_prefix" placeholder="Invoice prefix" defaultValue={settings.invoice_prefix ?? ''} />
        <input className={`${field} md:col-span-2`} name="receipt_footer" placeholder="Receipt footer" defaultValue={settings.receipt_footer ?? ''} />
        <input className={field} name="default_tax_percent" defaultValue={settings.default_tax_percent} />
        <select className={field} name="default_price_level" defaultValue={settings.default_price_level}>
          <option value="retail">Retail</option>
          <option value="wholesale">Wholesale</option>
          <option value="minimum_sale">Minimum sale</option>
        </select>
        <label className="flex items-center gap-2 text-[12px]"><input type="checkbox" name="negative_stock_allowed" defaultChecked={settings.negative_stock_allowed} /> Allow negative stock (config only)</label>
        <label className="flex items-center gap-2 text-[12px]"><input type="checkbox" name="expiry_tracking_enabled" defaultChecked={settings.expiry_tracking_enabled} /> Expiry tracking</label>
        <label className="flex items-center gap-2 text-[12px]"><input type="checkbox" name="batch_tracking_enabled" defaultChecked={settings.batch_tracking_enabled} /> Batch tracking</label>
        <button type="submit" className="h-8 rounded bg-[#1f4e79] px-3 text-[12px] font-semibold text-white disabled:opacity-50" disabled={!canManage || mutation.isPending}>
          Save
        </button>
      </form>
    </section>
  )
}
