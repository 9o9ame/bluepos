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

export function restoreDevice(ulid: string): Promise<Device> {
  return apiFetch<Device>(`/api/devices/${ulid}/restore`, { method: 'POST' })
}

export function assignDevice(ulid: string, input: { branch_ulid?: string | null; warehouse_ulid?: string | null }): Promise<Device> {
  return apiFetch<Device>(`/api/devices/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export type DeviceAccessRequest = {
  ulid: string
  membership_ulid: string
  username: string
  device_ulid: string
  device_name: string
  role_names: string[]
  request_ip: string | null
  request_user_agent: string | null
  request_latitude_approx: string | null
  request_longitude_approx: string | null
  created_at: string
  status: 'pending' | 'approved' | 'rejected'
  reviewed_at: string | null
  reviewer_name: string | null
}

export function fetchDeviceAccessRequests(status: 'pending' | 'approved' | 'rejected' | 'all' = 'pending'): Promise<{ requests: DeviceAccessRequest[]; pending_count: number }> {
  return apiFetch(`/api/devices/access-requests?status=${status}`)
}

export function reviewDeviceAccessRequest(ulid: string, decision: 'approved' | 'rejected'): Promise<{ ulid: string; status: string }> {
  return apiFetch(`/api/devices/access-requests/${ulid}/review`, {
    method: 'POST',
    body: JSON.stringify({ decision }),
  })
}

export function revokeDeviceAccessRequest(ulid: string): Promise<{ ulid: string; status: 'rejected' }> {
  return apiFetch(`/api/devices/access-requests/${ulid}/revoke`, { method: 'POST' })
}

export type DeviceAccessNotification = {
  ulid: string
  kind: 'new_device' | 'new_network'
  request_ulid: string
  status: 'pending' | 'approved' | 'rejected'
  employee_ulid: string
  username: string
  role_names: string[]
  device_ulid: string
  device_name: string
  request_ip: string | null
  request_user_agent: string | null
  request_latitude_approx: string | null
  request_longitude_approx: string | null
  created_at: string
  read_at: string | null
}

export type DeviceAccessInbox = {
  notifications: DeviceAccessNotification[]
  unread_count: number
  pagination: { page: number; last_page: number; total: number }
}

export function fetchDeviceAccessNotifications(page = 1): Promise<DeviceAccessInbox> {
  return apiFetch<DeviceAccessInbox>(`/api/devices/access-notifications?page=${page}`)
}

export function markDeviceAccessNotificationRead(ulid: string): Promise<{ ulid: string; read: true }> {
  return apiFetch(`/api/devices/access-notifications/${ulid}/read`, { method: 'POST' })
}
