import type { ReactNode } from 'react'

export function AuthLayout({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="auth-layout flex min-h-screen items-center justify-center bg-[var(--ui-accent)] p-4">
      <div className="auth-card w-full max-w-md rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] shadow-xl">
        <div className="auth-card-header border-b border-[var(--ui-border)] bg-[var(--ui-surface-subtle)] px-5 py-3">
          <div className="auth-brand text-[11px] font-black tracking-[0.2em] text-[var(--ui-accent)]">BLUEPOS</div>
          <h1 className="auth-title text-lg font-semibold text-[var(--ui-text)]">{title}</h1>
        </div>
        <div className="auth-card-body px-5 py-4">{children}</div>
      </div>
    </div>
  )
}
