import { useEffect, useState } from 'react'
import {
  getAllBarcodeStylePresets,
  saveBarcodeStylePrefs,
  type BarcodeStyleId,
  type BarcodeStylePreset,
} from './barcodeStyles'
import { UI_LAYER } from '../ui/uiLayers'
import './BarcodeStyleOptionsModal.css'

type BarcodeStyleOptionsModalProps = {
  open: boolean
  onClose: () => void
  onSaved: () => void
}

export function BarcodeStyleOptionsModal({
  open,
  onClose,
  onSaved,
}: BarcodeStyleOptionsModalProps) {
  const [rows, setRows] = useState<BarcodeStylePreset[]>([])

  useEffect(() => {
    if (open) {
      setRows(getAllBarcodeStylePresets())
    }
  }, [open])

  if (!open) return null

  const activeCount = rows.filter((row) => row.active).length

  function move(index: number, direction: -1 | 1) {
    const target = index + direction
    if (target < 0 || target >= rows.length) return
    setRows((current) => {
      const next = [...current]
      const tmp = next[index]
      next[index] = next[target]
      next[target] = tmp
      return next.map((row, order) => ({ ...row, order: order + 1 }))
    })
  }

  function toggleActive(id: BarcodeStyleId) {
    setRows((current) => {
      const row = current.find((item) => item.id === id)
      if (!row) return current
      if (row.active && current.filter((item) => item.active).length <= 1) {
        return current
      }
      return current.map((item) =>
        item.id === id ? { ...item, active: !item.active } : item,
      )
    })
  }

  function save() {
    const activeIds = rows.filter((row) => row.active).map((row) => row.id)
    if (activeIds.length < 1) return
    saveBarcodeStylePrefs({
      activeIds,
      order: rows.map((row) => row.id),
    })
    onSaved()
    onClose()
  }

  return (
    <div className="bp-style-modal-backdrop" style={{ zIndex: UI_LAYER.modal }} role="presentation">
      <div
        className="bp-style-modal"
        role="dialog"
        aria-modal="true"
        aria-label="Report Type On / Off Options"
      >
        <header className="bp-style-modal-title">
          <div className="bp-style-modal-title-text">
            <span className="bp-style-modal-kicker">Barcode Styles</span>
            <strong>Report Type On / Off Options</strong>
          </div>
          <button type="button" aria-label="Close" onClick={onClose}>
            ×
          </button>
        </header>

        <div className="bp-style-modal-body">
          <p className="bp-style-modal-hint">
            Enable styles for the dropdown. Keep at least one active. Reorder
            with ↑ ↓.
          </p>
          <table className="bp-style-modal-grid">
            <thead>
              <tr>
                <th>Index</th>
                <th>Report Name</th>
                <th>Used For</th>
                <th>Order By</th>
                <th>Active</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, index) => (
                <tr
                  key={row.id}
                  className={row.active ? 'is-active-row' : 'is-inactive-row'}
                >
                  <td>
                    <span className="bp-style-index">{index + 1}</span>
                  </td>
                  <td>
                    <span className="bp-style-report-name">{row.name}</span>
                  </td>
                  <td>
                    <span className="bp-style-used-for">{row.usedFor}</span>
                  </td>
                  <td>
                    <div className="bp-style-order-btns">
                      <button
                        type="button"
                        disabled={index === 0}
                        onClick={() => move(index, -1)}
                        aria-label="Move up"
                      >
                        ↑
                      </button>
                      <button
                        type="button"
                        disabled={index === rows.length - 1}
                        onClick={() => move(index, 1)}
                        aria-label="Move down"
                      >
                        ↓
                      </button>
                    </div>
                  </td>
                  <td>
                    <label
                      className={`bp-style-toggle${row.active && activeCount <= 1 ? ' is-locked' : ''}`}
                      title={
                        row.active && activeCount <= 1
                          ? 'At least one barcode style must remain active.'
                          : row.active
                            ? 'Active'
                            : 'Inactive'
                      }
                    >
                      <input
                        type="checkbox"
                        checked={row.active}
                        disabled={row.active && activeCount <= 1}
                        onChange={() => toggleActive(row.id)}
                      />
                      <span className="bp-style-toggle-track" aria-hidden>
                        <span className="bp-style-toggle-thumb" />
                      </span>
                    </label>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <footer className="bp-style-modal-footer">
          <span className="bp-style-modal-count">
            {activeCount} active style{activeCount === 1 ? '' : 's'}
          </span>
          <div className="bp-style-modal-actions">
            <button
              type="button"
              className="bp-style-modal-btn is-primary"
              onClick={save}
            >
              Save
            </button>
            <button
              type="button"
              className="bp-style-modal-btn"
              onClick={onClose}
            >
              Close
            </button>
          </div>
        </footer>
      </div>
    </div>
  )
}
