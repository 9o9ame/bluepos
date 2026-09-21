import { useQuery } from '@tanstack/react-query'
import { fetchPlatformSettings } from '../../api/platform'

export function PlatformSettingsPage() {
  const query = useQuery({ queryKey: ['platform', 'settings'], queryFn: fetchPlatformSettings })
  const data = query.data

  return (
    <section className="space-y-4">
      <h1 className="text-lg font-semibold">Platform Settings</h1>
      <p className="text-[12px] text-slate-600">
        Global BluePOS configuration. Personal password, MFA, and sessions are under My Account.
      </p>
      <div className="grid gap-3 md:grid-cols-2">
        <article className="rounded border border-slate-300 bg-white p-3 text-[12px]">
          <h2 className="font-semibold">General</h2>
          <p>{data?.general.product ?? 'BluePOS Platform'}</p>
        </article>
        <article className="rounded border border-slate-300 bg-white p-3 text-[12px]">
          <h2 className="font-semibold">MFA Policy</h2>
          <p>Required: {data?.security.mfa.required ? 'Yes' : 'No'}</p>
          <p>Current method: Email OTP</p>
          <p>TOTP: {data?.security.mfa.totp_enabled ? 'Enabled' : 'Not enabled'}</p>
          <p>Passkey: {data?.security.mfa.passkey_enabled ? 'Enabled' : 'Not enabled'}</p>
        </article>
        <article className="rounded border border-slate-300 bg-white p-3 text-[12px]">
          <h2 className="font-semibold">Session Policy</h2>
          <p>Recent MFA window: {data?.security.session.recent_mfa_minutes ?? 30} minutes</p>
        </article>
        <article className="rounded border border-slate-300 bg-white p-3 text-[12px]">
          <h2 className="font-semibold">Coming later</h2>
          <p>Tenant defaults, subscription defaults, and email configuration are not editable here. SMTP secrets are never shown in the browser.</p>
        </article>
      </div>
    </section>
  )
}
