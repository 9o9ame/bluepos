import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiClientError } from '../../api/client'
import {
  fetchPlatformDevices,
  fetchPlatformSessions,
  revokeOtherPlatformSessions,
  revokePlatformDevice,
  revokePlatformSession,
} from '../../api/platform'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'
import { useState } from 'react'

function parseAgent(ua: string | null): { device: string; browser: string } {
  const value = ua ?? ''
  const browser = /Edg\//.test(value)
    ? 'Edge'
    : /Chrome\//.test(value)
      ? 'Chrome'
      : /Firefox\//.test(value)
        ? 'Firefox'
        : /Safari\//.test(value)
          ? 'Safari'
          : value || 'Unknown'
  const device = /Mobile|Android|iPhone/.test(value) ? 'Mobile' : 'Desktop'
  return { device, browser }
}

export function PlatformAccountSecurityPage() {
  const { user } = usePlatformAuth()
  const queryClient = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const sessionsQuery = useQuery({ queryKey: ['platform', 'sessions'], queryFn: fetchPlatformSessions })
  const devicesQuery = useQuery({ queryKey: ['platform', 'devices'], queryFn: fetchPlatformDevices })

  async function run(action: () => Promise<unknown>) {
    setError(null)
    try {
      await action()
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['platform', 'sessions'] }),
        queryClient.invalidateQueries({ queryKey: ['platform', 'devices'] }),
      ])
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Request failed.')
    }
  }

  return (
    <section className="space-y-4">
      <h1 className="text-lg font-semibold">Security</h1>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}

      <div id="mfa" className="rounded border border-slate-300 bg-white p-3 text-[12px]">
        <h2 className="font-semibold">MFA</h2>
        <p>Current MFA: Email OTP</p>
        <p>Status: {user?.mfa?.enabled ? 'Enabled' : 'Not enabled'}</p>
        <p className="text-slate-500">TOTP and Passkey/WebAuthn are not enabled yet.</p>
        <p>Last MFA verification: {user?.last_mfa_verified_at ?? '—'}</p>
        <p>Last password change: {user?.password_changed_at ?? '—'}</p>
      </div>

      <div id="devices" className="rounded border border-slate-300 bg-white p-3 text-[12px]">
        <h2 className="mb-2 font-semibold">Trusted Devices</h2>
        <table className="w-full text-left">
          <thead>
            <tr className="text-slate-500">
              <th>Device</th>
              <th>Status</th>
              <th>Trusted until</th>
              <th>Last seen</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {(devicesQuery.data ?? []).map((device) => (
              <tr key={device.ulid} className="border-t">
                <td>{device.name ?? 'Browser'}</td>
                <td>{device.status}{device.trusted ? ' · trusted' : ''}</td>
                <td>{device.trusted_until ?? '—'}</td>
                <td>{device.last_seen_at ?? '—'}</td>
                <td>
                  {device.status !== 'revoked' ? (
                    <button type="button" className="underline" onClick={() => void run(() => revokePlatformDevice(device.ulid))}>
                      Revoke
                    </button>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div id="sessions" className="rounded border border-slate-300 bg-white p-3 text-[12px]">
        <div className="mb-2 flex items-center justify-between">
          <h2 className="font-semibold">Active Sessions</h2>
          <button type="button" className="rounded border px-2 py-1" onClick={() => void run(() => revokeOtherPlatformSessions())}>
            Logout all other sessions
          </button>
        </div>
        <table className="w-full text-left">
          <thead>
            <tr className="text-slate-500">
              <th>Device</th>
              <th>Browser</th>
              <th>IP</th>
              <th>Created</th>
              <th>Last activity</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {(sessionsQuery.data ?? [])
              .filter((session) => !session.revoked_at)
              .map((session) => {
                const parsed = parseAgent(session.user_agent)
                return (
                  <tr key={session.ulid} className="border-t">
                    <td>
                      {parsed.device}
                      {session.current ? ' · current' : ''}
                    </td>
                    <td>{parsed.browser}</td>
                    <td>{session.ip_address ?? '—'}</td>
                    <td>{session.created_at ?? '—'}</td>
                    <td>{session.last_seen_at ?? '—'}</td>
                    <td>
                      {session.current ? null : (
                        <button type="button" className="underline" onClick={() => void run(() => revokePlatformSession(session.ulid))}>
                          Revoke
                        </button>
                      )}
                    </td>
                  </tr>
                )
              })}
          </tbody>
        </table>
      </div>
    </section>
  )
}
