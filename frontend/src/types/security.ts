export type SecuritySession = {
  ulid: string
  ip_address: string | null
  user_agent: string | null
  last_seen_at: string | null
  revoked_at: string | null
  is_current: boolean
  membership: {
    ulid: string
    username: string
    user: {
      ulid: string
      name: string
    } | null
  } | null
  device: {
    ulid: string
    name: string
    device_type: string
    status: string
    registered_at: string | null
    approved_at: string | null
    last_seen_at: string | null
    last_sync_at: string | null
    app_version: string | null
  } | null
}
