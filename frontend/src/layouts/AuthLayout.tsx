import type { ReactNode } from 'react'

export function AuthLayout({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-[#1f4e79] p-4">
      <div className="w-full max-w-md rounded border border-slate-300 bg-white shadow-xl">
        <div className="border-b border-slate-200 bg-slate-100 px-5 py-3">
          <div className="text-[11px] font-black tracking-[0.2em] text-[#1f4e79]">BLUEPOS</div>
          <h1 className="text-lg font-semibold text-slate-900">{title}</h1>
        </div>
        <div className="px-5 py-4">{children}</div>
      </div>
    </div>
  )
}
