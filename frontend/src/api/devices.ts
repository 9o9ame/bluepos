import type { Device } from '../types/auth'
import { apiFetch } from './client'

export function fetchDevices(): Promise<Device[]> {
  return apiFetch<Device[]>('/api/devices')
}

export function enrollDevice(name: string): Promise<Device> {
  return apiFetch<Device>('/api/devices/enrollment', {
    method: 'POST',
    body: JSON.stringify({ name }),
  })
}

export function approveDevice(ulid: string, input: { branch_ulid?: string; warehouse_ulid?: string; name?: string }): Promise<Device> {
  return apiFetch<Device>(`/api/devices/${ulid}/approve`, {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function revokeDevice(ulid: string): Promise<Device> {
  return apiFetch<Device>(`/api/devices/${ulid}/revoke`, { method: 'POST' })
}

export function assignDevice(ulid: string, input: { branch_ulid?: string | null; warehouse_ulid?: string | null }): Promise<Device> {
  return apiFetch<Device>(`/api/devices/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}
