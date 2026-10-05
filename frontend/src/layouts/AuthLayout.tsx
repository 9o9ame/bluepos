import type { ReactNode } from 'react'

export function AuthLayout({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="auth-layout flex min-h-screen items-center justify-center bg-[#1f4e79] p-4">
      <div className="auth-card w-full max-w-md rounded border border-slate-300 bg-white shadow-xl">
        <div className="auth-card-header border-b border-slate-200 bg-slate-100 px-5 py-3">
          <div className="auth-brand text-[11px] font-black tracking-[0.2em] text-[#1f4e79]">BLUEPOS</div>
          <h1 className="auth-title text-lg font-semibold text-slate-900">{title}</h1>
        </div>
        <div className="auth-card-body px-5 py-4">{children}</div>
      </div>
    </div>
  )
}
