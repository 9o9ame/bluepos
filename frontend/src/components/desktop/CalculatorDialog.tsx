import { useState } from 'react'
import { DesktopButton } from './DesktopPanel'

type CalculatorDialogProps = {
  open: boolean
  onClose: () => void
}

export function CalculatorDialog({ open, onClose }: CalculatorDialogProps) {
  const [expression, setExpression] = useState('')
  const [result, setResult] = useState('0')

  if (!open) {
    return null
  }

  function evaluate() {
    const sanitized = expression.replace(/[^0-9+\-*/(). ]/g, '')
    if (!sanitized.trim()) {
      setResult('0')
      return
    }
    try {
      const value = Function(`"use strict"; return (${sanitized})`)()
      setResult(typeof value === 'number' && Number.isFinite(value) ? String(value) : 'Error')
    } catch {
      setResult('Error')
    }
  }

  return (
    <div className="calc-dialog" role="dialog" aria-modal="true" aria-label="Calculator">
      <div className="calc-card">
        <div className="desktop-panel-header">Calculator</div>
        <div className="desktop-panel-body">
          <label className="desktop-field">
            <span>Expression</span>
            <input
              className="desktop-input"
              value={expression}
              onChange={(event) => setExpression(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault()
                  evaluate()
                }
                if (event.key === 'Escape') {
                  onClose()
                }
              }}
              autoFocus
            />
          </label>
          <p className="mt-2 text-[12px]">Result: {result}</p>
        </div>
        <div className="desktop-panel-footer">
          <DesktopButton label="Calculate" onClick={evaluate} />
          <DesktopButton label="Close" shortcut="Esc" onClick={onClose} />
        </div>
      </div>
    </div>
  )
}
