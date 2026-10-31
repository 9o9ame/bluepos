import { useRef, type ClipboardEvent, type KeyboardEvent } from 'react'
import './premium-otp.css'

type Props = { value: string; onChange: (value: string) => void; disabled?: boolean }

export function PremiumOtpInput({ value, onChange, disabled = false }: Props) {
  const refs = useRef<Array<HTMLInputElement | null>>([])
  const digits = Array.from({ length: 6 }, (_, i) => value[i] ?? '')

  function update(index: number, raw: string) {
    const cleaned = raw.replace(/\D/g, '')
    if (!cleaned) {
      const copy = [...digits]
      copy[index] = ''
      onChange(copy.join(''))
      return
    }
    const copy = [...digits]
    for (let i = 0; i < cleaned.length && index + i < 6; i++) copy[index + i] = cleaned[i]
    onChange(copy.join(''))
    refs.current[Math.min(index + cleaned.length, 5)]?.focus()
  }

  function onPaste(event: ClipboardEvent<HTMLInputElement>) {
    event.preventDefault()
    const code = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6)
    if (code) { onChange(code); refs.current[Math.min(code.length, 5)]?.focus() }
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>, index: number) {
    if (event.key === 'Backspace' && !digits[index] && index > 0) refs.current[index - 1]?.focus()
    if (event.key === 'ArrowLeft' && index > 0) { event.preventDefault(); refs.current[index - 1]?.focus() }
    if (event.key === 'ArrowRight' && index < 5) { event.preventDefault(); refs.current[index + 1]?.focus() }
  }

  return (
    <div className="premium-otp" role="group" aria-label="Six-digit verification code">
      {digits.map((digit, index) => (
        <input
          key={index}
          ref={el => { refs.current[index] = el }}
          aria-label={`Code digit ${index + 1}`}
          type="text" inputMode="numeric" pattern="[0-9]*"
          autoComplete={index === 0 ? 'one-time-code' : 'off'}
          maxLength={6} value={digit} disabled={disabled}
          onChange={event => update(index, event.target.value)}
          onPaste={onPaste}
          onKeyDown={event => onKeyDown(event, index)}
        />
      ))}
    </div>
  )
}
