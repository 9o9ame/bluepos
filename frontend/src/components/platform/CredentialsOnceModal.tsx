import { useState } from 'react'
import { UiButton } from '../ui/UiButton'
import { UiModal } from '../ui/UiModal'

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
    const text = [
      business ? `Business: ${business}` : null,
      code ? `Code: ${code}` : null,
      `Username: ${username}`,
      `Temporary password: ${password}`,
    ].filter(Boolean).join('\n')
    await navigator.clipboard.writeText(text)
  }

  return (
    <UiModal
      title={title}
      size="sm"
      zIndex={5200}
      onClose={onClose}
      footer={
        <>
          <UiButton onClick={() => setVisible((value) => !value)}>
            {visible ? 'Hide' : 'Show'}
          </UiButton>
          <UiButton onClick={() => void copy()}>Copy credentials</UiButton>
          <UiButton variant="primary" onClick={onClose}>Done</UiButton>
        </>
      }
    >
      <dl className="space-y-1 text-[12px]">
        {business ? (
          <div>
            <dt className="text-[var(--ui-text-muted)]">Business</dt>
            <dd className="font-semibold">{business}</dd>
          </div>
        ) : null}
        {code ? (
          <div>
            <dt className="text-[var(--ui-text-muted)]">Mart code</dt>
            <dd className="font-semibold">{code}</dd>
          </div>
        ) : null}
        <div>
          <dt className="text-[var(--ui-text-muted)]">Admin username</dt>
          <dd className="font-semibold">{username}</dd>
        </div>
        <div>
          <dt className="text-[var(--ui-text-muted)]">Temporary password</dt>
          <dd className="font-mono font-semibold">{visible ? password : '********'}</dd>
        </div>
      </dl>
      <p
        className="mt-3 p-2 text-[12px]"
        style={{
          border: '1px solid color-mix(in srgb, var(--ui-warning) 55%, var(--ui-border))',
          borderRadius: 'var(--control-radius)',
          background: 'color-mix(in srgb, var(--ui-warning) 10%, var(--ui-surface))',
          color: 'var(--ui-warning)',
        }}
      >
        {warning}
      </p>
    </UiModal>
  )
}
