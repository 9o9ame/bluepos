import { FormEvent, useState } from 'react'
import { ApiClientError } from '../../api/client'

export function PlatformStepUpMfaModal({
  recoveryHint,
  deliveryHint,
  onVerify,
  onResend,
  onCancel,
}: {
  recoveryHint?: string | null
  deliveryHint?: string | null
  onVerify: (code: string) => Promise<void>
  onResend: () => Promise<void>
  onCancel: () => void
}) {
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [resending, setResending] = useState(false)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await onVerify(code)
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to verify the code.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4">
      <form className="w-full max-w-md space-y-3 rounded border border-slate-300 bg-white p-4 shadow-xl" onSubmit={onSubmit}>
        <h2 className="text-base font-semibold">Verify to continue</h2>
        <p className="text-[12px] text-slate-600">
          Sensitive platform actions require a fresh Email OTP{recoveryHint ? ` (${recoveryHint})` : ''}.
        </p>
        {deliveryHint ? <p className="text-[12px] text-amber-800">{deliveryHint}</p> : null}
        <input
          autoFocus
          required
          inputMode="numeric"
          className="h-9 w-full rounded border px-2 text-[12px]"
          placeholder="Verification code"
          value={code}
          onChange={(event) => setCode(event.target.value)}
        />
        {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
        <div className="flex items-center gap-2">
          <button type="submit" className="rounded bg-slate-950 px-3 py-1 text-[12px] text-white" disabled={submitting}>
            {submitting ? 'Verifying…' : 'Verify'}
          </button>
          <button
            type="button"
            className="text-[12px] underline disabled:opacity-60"
            disabled={resending}
            onClick={() => {
              setError(null)
              setResending(true)
              void onResend()
                .catch((err) => setError(err instanceof ApiClientError ? err.message : 'Unable to resend the code.'))
                .finally(() => setResending(false))
            }}
          >
            {resending ? 'Sending…' : 'Resend code'}
          </button>
          <button type="button" className="ml-auto rounded border px-3 py-1 text-[12px]" onClick={onCancel}>
            Cancel
          </button>
        </div>
      </form>
    </div>
  )
}
