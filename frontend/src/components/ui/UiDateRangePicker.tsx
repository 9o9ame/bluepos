import { useRef, useState, type InputHTMLAttributes } from 'react'
import { UiDatePicker } from './UiDatePicker'

type Props = {
  fromInputProps: InputHTMLAttributes<HTMLInputElement>
  toInputProps: InputHTMLAttributes<HTMLInputElement>
}

/** One visible control; both original date handlers and form values remain intact. */
export function UiDateRangePicker({ fromInputProps, toInputProps }: Props) {
  const from = useRef<HTMLInputElement>(null)
  const to = useRef<HTMLInputElement>(null)
  const anchor = useRef<HTMLInputElement>(null)
  const [open, setOpen] = useState(false)
  const format = (value: unknown) => typeof value === 'string' && value ? new Date(`${value}T12:00:00`).toLocaleDateString() : '—'
  return <span className="ui-date-range">
    <input ref={anchor} type="text" readOnly className="ui-date-range-trigger" aria-label="Date range" aria-haspopup="dialog" aria-expanded={open}
      disabled={fromInputProps.disabled || toInputProps.disabled} title={fromInputProps.title ?? toInputProps.title}
      value={`${format(fromInputProps.value ?? fromInputProps.defaultValue)} – ${format(toInputProps.value ?? toInputProps.defaultValue)}`}
      onClick={() => from.current?.click()} onKeyDown={event => { if (['Enter', ' ', 'F4', 'ArrowDown'].includes(event.key)) { event.preventDefault(); from.current?.click() } }}/>
    <span className="ui-date-range-fields" aria-hidden="true">
      <UiDatePicker {...fromInputProps} ref={from} rangeRole="from" rangePeer={to} anchorRef={anchor} onOpenChange={setOpen} tabIndex={-1}/>
      <UiDatePicker {...toInputProps} ref={to} rangeRole="to" tabIndex={-1}/>
    </span>
  </span>
}
