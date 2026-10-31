import type { ReactNode } from 'react'
import './auth-showcase.css'

export function AuthLayout({ title, children, notice }: { title: string; children: ReactNode; notice?: ReactNode }) {
  return (
    <div className="auth-layout auth-showcase">
      <div className="auth-showcase-form-side">
        <div className="auth-showcase-ring">
          <div className="auth-showcase-form-card">
            <div className="auth-showcase-heading">
              <div className="auth-brand">BLUEPOS</div>
              <h1 className="auth-title">{title}</h1>
              <p className="auth-showcase-subtitle">Access your workspace securely</p>
            </div>
            <div className="auth-showcase-form-body">{children}</div>
          </div>
        </div>
      </div>
      {notice ? <div className="auth-showcase-notice" role="status" aria-live="polite">{notice}</div> : null}
      <aside className="auth-showcase-promo" aria-label="BluePOS information">
        <div className="auth-showcase-promo-content">
          <div className="auth-showcase-promo-mark">BP</div>
          <p className="auth-showcase-eyebrow">BLUEPOS</p>
          <h2>One workspace. A clearer way to work.</h2>
          <p>Welcome to BluePOS. Sign in to continue to your authorized workspace.</p>
          <div className="auth-showcase-decor" aria-hidden="true"><span /><span /><span /></div>
        </div>
      </aside>
    </div>
  )
}
