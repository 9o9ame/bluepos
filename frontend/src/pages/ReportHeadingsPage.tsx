import { FormEvent, useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { RefreshCw, Save, X } from 'lucide-react'
import { fetchBusinessSettings, saveBusinessSettings } from '../api/catalog'
import { DesktopPanel } from '../components/desktop/DesktopPanel'
import { UiButton } from '../components/ui/UiButton'
import { useFeedback } from '../feedback/FeedbackProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './ReportHeadingsPage.css'

type HeadingForm = {
  business_name: string
  legal_name: string
  address: string
  city: string
  phone: string
  email: string
  tax_registration_number: string
}

const EMPTY_FORM: HeadingForm = {
  business_name: '',
  legal_name: '',
  address: '',
  city: '',
  phone: '',
  email: '',
  tax_registration_number: '',
}

export function ReportHeadingsPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const canManage = useCan('settings.manage')
  const query = useQuery({
    queryKey: ['business-settings'],
    queryFn: fetchBusinessSettings,
  })
  const [form, setForm] = useState<HeadingForm>(EMPTY_FORM)

  useEffect(() => {
    if (!query.data) return
    setForm({
      business_name: query.data.business_name ?? '',
      legal_name: query.data.legal_name ?? '',
      address: query.data.address ?? '',
      city: query.data.city ?? '',
      phone: query.data.phone ?? '',
      email: query.data.email ?? '',
      tax_registration_number: query.data.tax_registration_number ?? '',
    })
  }, [query.data])

  const mutation = useMutation({
    mutationFn: () =>
      saveBusinessSettings({
        business_name: form.business_name.trim(),
        legal_name: form.legal_name.trim() || null,
        address: form.address.trim() || null,
        city: form.city.trim() || null,
        phone: form.phone.trim() || null,
        email: form.email.trim() || null,
        tax_registration_number: form.tax_registration_number.trim() || null,
      }),
    onSuccess: async () => {
      feedback.success('Report heading details saved.')
      await queryClient.invalidateQueries({ queryKey: ['business-settings'] })
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to save report headings.'),
  })

  function update<K extends keyof HeadingForm>(key: K, value: HeadingForm[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    if (!form.business_name.trim() || mutation.isPending || !canManage) return
    mutation.mutate()
  }

  const locationLine = [form.address.trim(), form.city.trim()].filter(Boolean).join(', ')
  const contactLine = [form.phone.trim(), form.email.trim()].filter(Boolean).join(' · ')

  return (
    <DesktopPanel
      title="Report Headings"
      toolbar={
        <>
          <UiButton
            variant="success"
            icon={<Save size={14} />}
            label={mutation.isPending ? 'Saving…' : 'Save'}
            disabled={!canManage || !form.business_name.trim() || mutation.isPending}
            onClick={() => {
              const formNode = window.document.getElementById('report-headings-form') as HTMLFormElement | null
              formNode?.requestSubmit()
            }}
          />
          <UiButton
            variant="info"
            icon={<RefreshCw size={14} />}
            label={query.isFetching ? 'Refreshing…' : 'Refresh'}
            disabled={query.isFetching}
            onClick={() => void query.refetch()}
          />
          <UiButton variant="info" icon={<X size={14} />} label="Close" onClick={closeActiveTab} />
        </>
      }
    >
      <div className="report-headings-layout">
        <form id="report-headings-form" className="report-headings-form" onSubmit={submit}>
          <div className="report-headings-section-title">Report identity</div>

          <label>
            <span>Business name</span>
            <input
              className="desktop-input"
              value={form.business_name}
              maxLength={160}
              required
              disabled={!canManage}
              onChange={(event) => update('business_name', event.target.value)}
            />
          </label>

          <label>
            <span>Legal name</span>
            <input
              className="desktop-input"
              value={form.legal_name}
              maxLength={160}
              disabled={!canManage}
              onChange={(event) => update('legal_name', event.target.value)}
            />
          </label>

          <label className="is-wide">
            <span>Address</span>
            <input
              className="desktop-input"
              value={form.address}
              maxLength={255}
              disabled={!canManage}
              onChange={(event) => update('address', event.target.value)}
            />
          </label>

          <label>
            <span>City</span>
            <input
              className="desktop-input"
              value={form.city}
              maxLength={120}
              disabled={!canManage}
              onChange={(event) => update('city', event.target.value)}
            />
          </label>

          <label>
            <span>Phone</span>
            <input
              className="desktop-input"
              value={form.phone}
              maxLength={32}
              disabled={!canManage}
              onChange={(event) => update('phone', event.target.value)}
            />
          </label>

          <label>
            <span>Email</span>
            <input
              className="desktop-input"
              type="email"
              value={form.email}
              maxLength={255}
              disabled={!canManage}
              onChange={(event) => update('email', event.target.value)}
            />
          </label>

          <label>
            <span>Tax registration</span>
            <input
              className="desktop-input"
              value={form.tax_registration_number}
              maxLength={64}
              disabled={!canManage}
              onChange={(event) => update('tax_registration_number', event.target.value)}
            />
          </label>

          {!canManage ? (
            <p className="report-headings-readonly">You have read-only access to report heading details.</p>
          ) : null}
        </form>

        <aside className="report-headings-preview" aria-label="Report heading preview">
          <div className="report-headings-preview-caption">Preview</div>
          <div className="report-headings-paper">
            <strong>{form.business_name.trim() || 'Business Name'}</strong>
            {form.legal_name.trim() ? <span>{form.legal_name.trim()}</span> : null}
            {locationLine ? <span>{locationLine}</span> : null}
            {contactLine ? <span>{contactLine}</span> : null}
            {form.tax_registration_number.trim() ? (
              <span>Tax Reg: {form.tax_registration_number.trim()}</span>
            ) : null}
            <div className="report-headings-preview-rule" />
            <b>Report Title</b>
            <small>Report-specific title, filters and period are supplied by each report.</small>
          </div>
        </aside>
      </div>
    </DesktopPanel>
  )
}
