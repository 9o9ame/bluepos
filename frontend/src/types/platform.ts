export type PlatformUser = {
  ulid: string
  name: string
  email: string
  status: string
  must_change_password: boolean
  password_changed_at?: string | null
  last_login_at: string | null
  last_seen_at?: string | null
  last_mfa_verified_at: string | null
  permissions: string[]
  mfa?: { method: string; enabled: boolean }
  roles?: Array<{ ulid: string; code: string; name: string }>
  temporary_password?: string | null
}

export type PlatformRole = {
  ulid: string
  code: string
  name: string
  description: string | null
  is_system: boolean
  is_active: boolean
  type: 'system' | 'custom'
  users_assigned?: number
  updated_at?: string | null
  permissions?: PlatformPermission[]
}

export type PlatformPermission = {
  ulid: string
  key: string
  name: string
  module: string
  description: string | null
}

export type PlatformSession = {
  ulid: string
  ip_address: string | null
  user_agent: string | null
  current: boolean
  last_seen_at: string | null
  revoked_at: string | null
  created_at: string | null
}

export type PlatformDevice = {
  ulid: string
  name: string | null
  status: string
  trusted: boolean
  trusted_until: string | null
  last_seen_at: string | null
  registered_at: string | null
}

export type PlatformPlan = {
  ulid: string
  code: string
  name: string
  description: string | null
  status: string
  billing_interval: string | null
  features?: Record<string, boolean>
  limits?: Record<string, number | null>
}

export type PlatformTenant = {
  ulid: string
  code: string
  name: string
  legal_name: string | null
  status: string
  timezone: string
  currency_code: string
  created_at: string | null
  plan: { ulid: string; code: string; name: string } | null
  subscription: {
    status: string
    trial_starts_at: string | null
    trial_ends_at: string | null
    starts_at: string | null
    ends_at: string | null
    grace_ends_at: string | null
  } | null
  users_count?: number
  branches_count?: number
  devices_count?: number
  warehouses_count?: number
  admin_username?: string | null
  entitlements?: {
    features: string[]
    limits: Record<string, number | null>
    usage: Record<string, number>
  }
  feature_overrides?: Array<{ feature_key: string; enabled: boolean; reason: string }>
  limit_overrides?: Array<{ limit_key: string; value: number | null; reason: string }>
  admins?: Array<{
    ulid: string
    username: string
    status: string
    is_owner: boolean
    user: {
      ulid: string
      name: string
      recovery_email: string | null
      must_change_password: boolean
      last_login_at?: string | null
      status: string
    } | null
    roles: Array<{ ulid: string; code: string; name: string }>
  }>
  initial_admin?: {
    username: string
    must_change_password: boolean
    temporary_password: string | null
  }
}

export type PlatformDashboard = {
  tenants: { total: number; trial: number; active: number; suspended: number }
  plans: Array<{ ulid: string; code: string; name: string; status: string }>
  recent_tenants: PlatformTenant[]
  security_alerts: Array<{
    ulid: string
    event: string
    resource_ulid: string | null
    occurred_at: string | null
    actor_email: string | null
  }>
}

export type PlatformAuditEvent = {
  ulid: string
  event: string
  resource_type: string | null
  resource_ulid: string | null
  metadata: Record<string, unknown> | null
  occurred_at: string | null
  actor: { ulid: string; name: string; email: string } | null
}

export type PlatformSettings = {
  general: { product: string }
  security: {
    mfa: {
      method: string
      required: boolean
      totp_enabled: boolean
      passkey_enabled: boolean
    }
    session: { recent_mfa_minutes: number }
  }
}

export type Paginated<T> = {
  data: T[]
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
