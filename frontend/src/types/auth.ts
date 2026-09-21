export type User = {
  ulid: string
  name: string
  email: string | null
  must_change_password: boolean
  status: string
  last_login_at: string | null
}

export type Tenant = {
  ulid: string
  name: string
  code: string
  slug: string
  status: string
  timezone: string
  currency_code: string
}

export type Branch = {
  ulid: string
  code: string
  name: string
  status: string
  is_default: boolean
}

export type Warehouse = {
  ulid: string
  code: string
  name: string
  status: string
  is_default: boolean
  branch_ulid?: string | null
}

export type Permission = {
  ulid: string
  key: string
  name: string
  module: string
  description: string | null
}

export type RoleSummary = {
  ulid: string
  name: string
  code: string
  description: string | null
  branch_access: 'all_branches' | 'selected_branches'
  is_system: boolean
  is_active: boolean
  permissions?: Permission[]
}

export type Membership = {
  ulid: string
  username: string
  status: string
  is_owner: boolean
  user?: User
  roles?: RoleSummary[]
  branches?: Branch[]
}

export type Device = {
  ulid: string
  name: string
  device_type: string
  status: string
  registered_at: string | null
  approved_at: string | null
  last_seen_at: string | null
  last_sync_at: string | null
  app_version: string | null
  branch?: Branch | null
  warehouse?: Warehouse | null
}

export type AuthSession = {
  user: User
  tenant: Tenant
  membership: Membership
  branch: Branch
  warehouse: Warehouse
  roles: RoleSummary[]
  permissions: string[]
  device: Device | null
  must_change_password: boolean
  branch_access: 'all_branches' | 'selected_branches'
  entitlements?: {
    plan: { code: string; name: string; status: string } | null
    features: string[]
    limits: Record<string, number | null>
    usage: Record<string, number>
  }
}

export type ApiErrorBody = {
  error: {
    key: string
    message: string
    fields?: Record<string, string[]>
  }
}
