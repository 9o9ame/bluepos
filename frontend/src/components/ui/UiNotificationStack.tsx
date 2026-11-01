import { useEffect, useRef, useState, type ReactNode } from 'react'
import './UiNotificationStack.css'

/** Layer real notices only; switching cards never acknowledges an unread alert. */
export function UiNotificationStack({ cards }: { cards: { key: string; content: ReactNode }[] }) {
  const [selected, setSelected] = useState<string | null>(null)
  const stack = useRef<HTMLDivElement>(null)
  const lastWheel = useRef(-Infinity)
  useEffect(() => {
    const element = stack.current
    if (!element) return
    const cycle = (event: WheelEvent) => {
      if (cards.length < 2 || event.ctrlKey || event.deltaY === 0) return
      event.preventDefault()
      event.stopPropagation()
      const now = performance.now()
      // One step per wheel gesture, including high-frequency trackpad events.
      if (now - lastWheel.current < 240) return
      lastWheel.current = now
      setSelected(current => {
        const currentIndex = Math.max(0, cards.findIndex(card => card.key === current))
        return cards[(currentIndex + (event.deltaY > 0 ? 1 : -1) + cards.length) % cards.length].key
      })
    }
    // React's delegated wheel listener is passive; cancel page scrolling locally.
    element.addEventListener('wheel', cycle, { passive: false })
    return () => element.removeEventListener('wheel', cycle)
  }, [cards])
  if (!cards.length) return null
  const index = Math.max(0, cards.findIndex(card => card.key === selected))
  const ordered = [...cards.slice(index), ...cards.slice(0, index)].slice(0, 3)
  return <div ref={stack} className="ui-notification-stack">
    {cards.length > 1 && <span className="ui-notification-stack-count" aria-label={`${cards.length} notifications`}>+{cards.length - 1}</span>}
    <div className="ui-notification-stack-cards">{ordered.slice().reverse().map((card, reverse) => {
      const layer = ordered.length - reverse - 1
      return <div key={card.key} className={`ui-notification-stack-layer${layer === 0 ? ' is-front' : ''}`} style={{ '--notice-layer': layer } as React.CSSProperties} aria-hidden={layer > 0 || undefined} ref={el => { if (el) el.inert = layer > 0 }}>{card.content}</div>
    })}</div>
    {cards.length > 1 && <div className="ui-notification-stack-dots" role="group" aria-label="Choose notification">{cards.map((card, i) => <button type="button" key={card.key} aria-label={`Notification ${i + 1} of ${cards.length}`} aria-pressed={i === index} onClick={() => setSelected(card.key)}/>)}</div>}
  </div>
}
