import { useState } from 'react'

export function CredentialsOnceModal({
  title,
  business,
  code,
  username,
  password,
  warning,
  onClose,
}: {
  title: string
  business?: string
  code?: string
  username: string
  password: string
  warning: string
  onClose: () => void
}) {
  const [visible, setVisible] = useState(false)

  async function copy() {
    const text = [business ? `Business: ${business}` : null, code ? `Code: ${code}` : null, `Username: ${username}`, `Temporary password: ${password}`]
      .filter(Boolean)
      .join('\n')
    await navigator.clipboard.writeText(text)
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4">
      <div className="w-full max-w-md rounded border border-slate-300 bg-white p-4 shadow-xl">
        <h2 className="text-base font-semibold">{title}</h2>
        <dl className="mt-3 space-y-1 text-[12px]">
          {business ? (
            <div>
              <dt className="text-slate-500">Business</dt>
              <dd className="font-semibold">{business}</dd>
            </div>
          ) : null}
          {code ? (
            <div>
              <dt className="text-slate-500">Mart code</dt>
              <dd className="font-semibold">{code}</dd>
            </div>
          ) : null}
          <div>
            <dt className="text-slate-500">Admin username</dt>
            <dd className="font-semibold">{username}</dd>
          </div>
          <div>
            <dt className="text-slate-500">Temporary password</dt>
            <dd className="font-mono font-semibold">{visible ? password : '********'}</dd>
          </div>
        </dl>
        <p className="mt-3 rounded border border-amber-300 bg-amber-50 p-2 text-[12px] text-amber-900">{warning}</p>
        <div className="mt-3 flex flex-wrap gap-2">
          <button type="button" className="rounded border px-3 py-1 text-[12px]" onClick={() => setVisible((value) => !value)}>
            {visible ? 'Hide' : 'Show'}
          </button>
          <button type="button" className="rounded border px-3 py-1 text-[12px]" onClick={() => void copy()}>
            Copy credentials
          </button>
          <button type="button" className="rounded bg-slate-950 px-3 py-1 text-[12px] text-white" onClick={onClose}>
            Done
          </button>
        </div>
      </div>
    </div>
  )
}
