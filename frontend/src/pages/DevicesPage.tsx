import { useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchBranches } from '../api/branches'
import { approveDevice, fetchDevices, revokeDevice } from '../api/devices'
import { ApiClientError } from '../api/client'
import { useCan } from '../features/auth/useCan'
import type { Device } from '../types/auth'
import { useState } from 'react'

export function DevicesPage() {
  const queryClient = useQueryClient()
  const canApprove = useCan('devices.approve')
  const canRevoke = useCan('devices.revoke')
  const devicesQuery = useQuery({ queryKey: ['devices'], queryFn: fetchDevices })
  const branchesQuery = useQuery({ queryKey: ['branches'], queryFn: fetchBranches })
  const [error, setError] = useState<string | null>(null)

  async function onApprove(device: Device) {
    setError(null)
    try {
      await approveDevice(device.ulid, { branch_ulid: branchesQuery.data?.[0]?.ulid })
      await queryClient.invalidateQueries({ queryKey: ['devices'] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to approve device.')
    }
  }

  async function onRevoke(device: Device) {
    setError(null)
    try {
      await revokeDevice(device.ulid)
      await queryClient.invalidateQueries({ queryKey: ['devices'] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to revoke device.')
    }
  }

  return (
    <section className="space-y-4">
      <h2 className="text-base font-semibold">Devices</h2>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      <table className="w-full border border-slate-300 bg-white text-[12px]">
        <thead className="bg-slate-100">
          <tr>
            <th className="p-2 text-left">Name</th>
            <th className="p-2 text-left">ULID</th>
            <th className="p-2 text-left">Branch</th>
            <th className="p-2 text-left">Status</th>
            <th className="p-2 text-left">Last seen</th>
            <th className="p-2"></th>
          </tr>
        </thead>
        <tbody>
          {(devicesQuery.data ?? []).map((device) => (
            <tr key={device.ulid} className="border-t border-slate-200">
              <td className="p-2">{device.name}</td>
              <td className="p-2 font-mono text-[11px]">{device.ulid}</td>
              <td className="p-2">{device.branch?.code ?? '—'}</td>
              <td className="p-2">{device.status}</td>
              <td className="p-2">{device.last_seen_at ?? '—'}</td>
              <td className="p-2 text-right space-x-1">
                {canApprove && device.status === 'pending' ? (
                  <button type="button" className="rounded border px-2 py-1" onClick={() => void onApprove(device)}>Approve</button>
                ) : null}
                {canRevoke && device.status !== 'revoked' ? (
                  <button type="button" className="rounded border px-2 py-1" onClick={() => void onRevoke(device)}>Revoke</button>
                ) : null}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
