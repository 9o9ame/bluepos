import { FormEvent, useState } from 'react'
import { ApiClientError } from '../../api/client'
import { UiButton } from '../ui/UiButton'
import { UiModal } from '../ui/UiModal'

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
    <UiModal title="Verify to continue" size="sm" zIndex={5200} onClose={onCancel}>
      <form className="space-y-3" onSubmit={onSubmit}>
        <p className="text-[12px] text-[var(--ui-text-muted)]">
          Sensitive platform actions require a fresh Email OTP{recoveryHint ? ` (${recoveryHint})` : ''}.
        </p>
        {deliveryHint ? <p className="text-[12px] text-[var(--ui-warning)]">{deliveryHint}</p> : null}
        <input
          autoFocus
          required
          inputMode="numeric"
          className="desktop-input h-9 w-full px-2 text-[12px]"
          placeholder="Verification code"
          value={code}
          onChange={(event) => setCode(event.target.value)}
        />
        {error ? <p className="text-[12px] text-[var(--ui-danger)]">{error}</p> : null}
        <div className="flex items-center gap-2">
          <UiButton type="submit" variant="primary" disabled={submitting}>
            {submitting ? 'Verifying…' : 'Verify'}
          </UiButton>
          <UiButton
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
          </UiButton>
          <UiButton className="ml-auto" onClick={onCancel}>Cancel</UiButton>
        </div>
      </form>
    </UiModal>
  )
}
