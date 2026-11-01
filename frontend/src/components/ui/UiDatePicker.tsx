import { forwardRef, useEffect, useId, useLayoutEffect, useRef, useState, type InputHTMLAttributes, type RefObject } from 'react'
import { createPortal } from 'react-dom'
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react'
import { UiButton } from './UiButton'
import { UiSelect } from './UiSelect'
import { UI_LAYER } from './uiLayers'
import './UiDatePicker.css'

type Props = InputHTMLAttributes<HTMLInputElement> & { rangeRole?: 'from' | 'to'; rangePeer?: RefObject<HTMLInputElement>; anchorRef?: RefObject<HTMLInputElement>; onOpenChange?: (open: boolean) => void }
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const parse = (s: string) => new Date(`${s}T12:00:00`)
const valid = (s: string) => /^\d{4}-\d{2}-\d{2}$/.test(s) && !Number.isNaN(parse(s).getTime())
const allowed = (s: string, el: HTMLInputElement, minimum = el.min) => !s || ((!minimum || s >= minimum) && (!el.max || s <= el.max))

// Native input keeps form serialization, refs, validation and the existing geometry.
// The calendar is portalled above even deeply nested tenant dialogs.
export const UiDatePicker = forwardRef<HTMLInputElement, Props>(function UiDatePicker({ rangeRole, rangePeer, anchorRef, onOpenChange, ...props }, forwardedRef) {
  const input = useRef<HTMLInputElement | null>(null)
  const menu = useRef<HTMLDivElement>(null)
  const pair = useRef<{ from: HTMLInputElement; to: HTMLInputElement } | null>(null)
  const [open, setOpen] = useState(false)
  const [start, setStart] = useState('')
  const [end, setEnd] = useState('')
  const [choosingEnd, setChoosingEnd] = useState(false)
  const [month, setMonth] = useState(new Date())
  const [position, setPosition] = useState({ left: 8, top: 8 })
  const id = useId()
  const isRange = !!pair.current
  useEffect(() => { onOpenChange?.(open) }, [open, onOpenChange])
  const close = () => { setOpen(false); (anchorRef?.current ?? input.current)?.focus() }
  function show() {
    const el = input.current
    if (!el || el.disabled || el.readOnly) return
    pair.current = null
    if (rangePeer?.current) pair.current = { from: el, to: rangePeer.current }
    else if (rangeRole) {
      for (let parent = el.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
        const from = [...parent.querySelectorAll<HTMLInputElement>('input[data-date-range-role="from"]')].filter(e => e.getClientRects().length && !e.disabled && !e.readOnly)
        const to = [...parent.querySelectorAll<HTMLInputElement>('input[data-date-range-role="to"]')].filter(e => e.getClientRects().length && !e.disabled && !e.readOnly)
        if (from.length === 1 && to.length === 1) { pair.current = { from: from[0], to: to[0] }; break }
        if (parent.tagName === 'FORM' || parent.tagName === 'MAIN') break
      }
    }
    setStart(pair.current?.from.value ?? el.value)
    setEnd(pair.current?.to.value ?? '')
    setChoosingEnd(false)
    setMonth(valid(el.value) ? parse(el.value) : new Date())
    setOpen(true)
  }
  useLayoutEffect(() => {
    if (!open) return
    const update = () => {
      const r = (anchorRef?.current ?? input.current)?.getBoundingClientRect()
      const p = menu.current?.getBoundingClientRect()
      if (!r || !p) return
      setPosition({ left: Math.max(8, Math.min(r.left, window.innerWidth - p.width - 8)), top: r.bottom + p.height + 8 <= window.innerHeight ? r.bottom + 5 : Math.max(8, r.top - p.height - 5) })
    }
    update()
    const selected = menu.current?.querySelector<HTMLButtonElement>('[data-day][aria-selected="true"]:not(:disabled)')
    const firstEnabled = menu.current?.querySelector<HTMLButtonElement>('[data-day]:not(:disabled)')
    ;(selected ?? firstEnabled)?.focus()
    window.addEventListener('resize', update)
    window.addEventListener('scroll', update, true)
    return () => { window.removeEventListener('resize', update); window.removeEventListener('scroll', update, true) }
  }, [open])
  useEffect(() => {
    if (!open) return
    const dismiss = (event: PointerEvent) => {
      if (menu.current?.contains(event.target as Node) || input.current === event.target || anchorRef?.current === event.target || (event.target as Element)?.closest('.ui-select-menu')) return
      setOpen(false)
    }
    const tabOut = (event: FocusEvent) => {
      if (!menu.current?.contains(event.target as Node) && input.current !== event.target && anchorRef?.current !== event.target && !(event.target as Element)?.closest('.ui-select-menu')) setOpen(false)
    }
    document.addEventListener('pointerdown', dismiss)
    document.addEventListener('focusin', tabOut)
    return () => { document.removeEventListener('pointerdown', dismiss); document.removeEventListener('focusin', tabOut) }
  }, [open])
  function commit(a: string, b = '') {
    const el = input.current
    if (!el) return
    const nodes = pair.current ? [[pair.current.from, a], [pair.current.to, b]] as const : [[el, a]] as const
    if (pair.current && a && b && a > b) return
    if (nodes.some(([node, value]) => !allowed(value, node, pair.current && node === pair.current.to && node.min === pair.current.from.value ? a : node.min))) return
    for (const [node, value] of nodes) {
      Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(node, value)
      node.dispatchEvent(new Event('input', { bubbles: true }))
      node.dispatchEvent(new Event('change', { bubbles: true }))
    }
    close()
  }
  function select(value: string) {
    if (!isRange) { commit(value); return }
    if (!choosingEnd) { setStart(value); setEnd(''); setChoosingEnd(true) }
    else { setStart(value < start ? value : start); setEnd(value < start ? start : value); setChoosingEnd(false) }
  }
  function preset(kind: 'today' | 'week' | 'month') {
    const last = new Date(), first = new Date()
    if (kind === 'week') first.setDate(first.getDate() - 6)
    if (kind === 'month') first.setDate(1)
    setStart(iso(first)); setEnd(isRange ? iso(last) : ''); setMonth(first); setChoosingEnd(false)
  }
  const first = new Date(month.getFullYear(), month.getMonth(), 1, 12)
  const days = Array.from({ length: 42 }, (_, i) => new Date(first.getFullYear(), first.getMonth(), i - first.getDay() + 1, 12))
  const count = start && end ? Math.round((parse(end).getTime() - parse(start).getTime()) / 86400000) + 1 : start ? 1 : 0
  const range = pair.current
  const canApply = !!start && (range
    ? !!end && start <= end && allowed(start, range.from) && allowed(end, range.to, range.to.min === range.from.value ? start : range.to.min)
    : !!input.current && allowed(start, input.current))
  return <>
    <input {...props} type="date" ref={el => { input.current = el; if (typeof forwardedRef === 'function') forwardedRef(el); else if (forwardedRef) forwardedRef.current = el }}
      className={['ui-date-input', props.className].filter(Boolean).join(' ')} data-date-range-role={rangeRole}
      aria-haspopup="dialog" aria-expanded={open} aria-controls={open ? id : undefined}
      onClick={event => { props.onClick?.(event); if (!event.defaultPrevented) { event.preventDefault(); show() } }}
      onKeyDown={event => { props.onKeyDown?.(event); if (!event.defaultPrevented && (event.key === 'F4' || (event.altKey && event.key === 'ArrowDown'))) { event.preventDefault(); show() } }} />
    {open && createPortal(<div ref={menu} id={id} className="ui-date-picker" role="dialog" aria-label={isRange ? 'Choose date range' : 'Choose date'} style={{ ...position, zIndex: UI_LAYER.feedback }}
      onKeyDown={event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close() }
        if ((event.target as HTMLElement).hasAttribute('data-day')) {
          const offset = ({ ArrowRight: 1, ArrowLeft: -1, ArrowDown: 7, ArrowUp: -7 } as Record<string, number>)[event.key]
          if (offset) {
            event.preventDefault()
            const day = parse((event.target as HTMLElement).getAttribute('data-day')!)
            day.setDate(day.getDate() + offset)
            const target = menu.current?.querySelector<HTMLButtonElement>(`[data-day="${iso(day)}"]`)
            if (target && !target.disabled) target.focus()
          }
        }
      }}>
      <div className="ui-date-presets"><UiButton label="Today" onClick={() => preset('today')}/>{isRange && <><UiButton label="Last 7 days" onClick={() => preset('week')}/><UiButton label="This month" onClick={() => preset('month')}/></>}<UiButton label="Clear" onClick={() => commit('', '')}/><UiButton variant="primary" label={isRange ? 'Apply range' : 'Apply'} disabled={!canApply} onClick={() => commit(start, end)}/></div>
      <div className="ui-date-content"><aside className="ui-date-summary"><CalendarDays size={22}/><small>{isRange ? 'SELECTED RANGE' : 'SELECTED DATE'}</small><strong>{start ? parse(start).toLocaleDateString() : '—'}</strong>{isRange && <><span>↓</span><strong>{end ? parse(end).toLocaleDateString() : 'Select end date'}</strong><span className="ui-date-duration">{count} {count === 1 ? 'day' : 'days'}</span></>}</aside>
        <div className="ui-date-calendar"><div className="ui-date-heading"><UiButton aria-label="Previous month" icon={<ChevronLeft size={15}/>} onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1, 12))}/><UiSelect aria-label="Calendar month" searchable={false} value={String(month.getMonth())} options={Array.from({ length: 12 }, (_, i) => ({ value: String(i), label: new Date(2026, i).toLocaleString(undefined, { month: 'long' }) }))} menuZIndex={UI_LAYER.feedback} onChange={value => setMonth(new Date(month.getFullYear(), Number(value), 1, 12))}/><input aria-label="Calendar year" type="number" min={1} max={9999} key={month.getFullYear()} defaultValue={month.getFullYear()} onBlur={event => { const year = Number(event.target.value); if (year >= 100 && year <= 9999) setMonth(new Date(year, month.getMonth(), 1, 12)); else event.target.value = String(month.getFullYear()) }} onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); event.currentTarget.blur() } }}/><UiButton aria-label="Next month" icon={<ChevronRight size={15}/>} onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1, 12))}/></div>
          <div className="ui-date-weekdays">{Array.from({ length: 7 }, (_, i) => <span key={i}>{new Date(2026, 10, 1 + i).toLocaleDateString(undefined, { weekday: 'short' })}</span>)}</div>
          <div className="ui-date-days" role="grid" aria-label={month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}>{days.map(day => {
            const value = iso(day), el = input.current!
            const range = pair.current
            const disabled = range ? !allowed(value, choosingEnd ? range.to : range.from, choosingEnd && range.to.min === range.from.value ? start : choosingEnd ? range.to.min : range.from.min) : !allowed(value, el)
            return <button type="button" role="gridcell" key={value} data-day={value} aria-label={day.toLocaleDateString(undefined, { dateStyle: 'full' })} aria-selected={value === start || value === end} aria-current={value === iso(new Date()) ? 'date' : undefined} disabled={disabled} className={[day.getMonth() !== month.getMonth() ? 'is-outside' : '', start && end && value > start && value < end ? 'is-between' : ''].join(' ')} onClick={() => select(value)}>{day.getDate()}</button>
          })}</div>
        </div></div>
    </div>, document.body)}
  </>
})
