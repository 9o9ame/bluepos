import type { AuthSession } from '../types/auth'

type UserPanelProps = {
  session: AuthSession
  onLogout: () => void
  busy: boolean
}

export function UserPanel({ session, onLogout, busy }: UserPanelProps) {
  return (
    <div className="flex items-center gap-3 text-[12px] text-white">
      <div className="text-right leading-tight">
        <div className="font-semibold">{session.user.name}</div>
        <div className="text-slate-300">{session.tenant.name}</div>
      </div>
      <button
        type="button"
        className="h-7 rounded border border-slate-400 bg-slate-700 px-3 hover:bg-slate-600 disabled:opacity-60"
        onClick={onLogout}
        disabled={busy}
      >
        Logout
      </button>
    </div>
  )
}
