import { useEffect, useMemo, useState } from 'react'
import { Barcode, Printer, X } from 'lucide-react'
import {
  code39Svg,
  getBarcodeLabelSizeOptions,
  loadBarcodePrintSettings,
  printBarcodeLabels,
  saveBarcodePrintSettings,
  type BarcodeLabelSize,
} from './barcodePrint'
import './ProductBarcodePrintModal.css'

export type PrintableProductBarcode = {
  id: string
  barcode: string
  unitCode: string
  conversionFactor: string
  isPrimary: boolean
}

type ProductBarcodePrintModalProps = {
  open: boolean
  businessName: string
  productName: string
  productNumber: string
  price: string
  barcodes: PrintableProductBarcode[]
  initialBarcodeId: string | null
  onClose: () => void
}

export function ProductBarcodePrintModal({
  open,
  businessName,
  productName,
  productNumber,
  price,
  barcodes,
  initialBarcodeId,
  onClose,
}: ProductBarcodePrintModalProps) {
  const stored = useMemo(() => loadBarcodePrintSettings(), [open])
  const [barcodeId, setBarcodeId] = useState('')
  const [copies, setCopies] = useState('1')
  const [labelSize, setLabelSize] = useState<BarcodeLabelSize>(
    stored.labelSize,
  )
  const [showPrice, setShowPrice] = useState(stored.showPrice)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open) return

    const preferred =
      barcodes.find((row) => row.id === initialBarcodeId) ??
      barcodes.find((row) => row.isPrimary) ??
      barcodes[0] ??
      null

    setBarcodeId(preferred?.id ?? '')
    setCopies('1')
    setLabelSize(stored.labelSize)
    setShowPrice(stored.showPrice)
    setError(null)
  }, [open, initialBarcodeId, barcodes, stored.labelSize, stored.showPrice])

  useEffect(() => {
    if (!open) return

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.preventDefault()
        onClose()
      }
    }

    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [open, onClose])

  if (!open) return null

  const selected =
    barcodes.find((row) => row.id === barcodeId) ??
    barcodes[0] ??
    null

  function print() {
    if (!selected) {
      setError('This product has no barcode to print.')
      return
    }

    const copyCount = Math.floor(Number(copies))
    if (!Number.isFinite(copyCount) || copyCount < 1 || copyCount > 100) {
      setError('Copies must be between 1 and 100.')
      return
    }

    try {
      saveBarcodePrintSettings({ labelSize, showPrice })

      printBarcodeLabels({
        businessName,
        productName,
        productNumber,
        barcode: selected.barcode,
        unitCode: selected.unitCode,
        price,
        copies: copyCount,
        labelSize,
        showPrice,
      })

      setError(null)
    } catch (err) {
      setError(
        err instanceof Error ? err.message : 'Unable to print barcode.',
      )
    }
  }

  return (
    <div
      className="product-barcode-print-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.currentTarget === event.target) onClose()
      }}
    >
      <section
        className="product-barcode-print-modal"
        role="dialog"
        aria-modal="true"
        aria-label="Barcode Print"
        onMouseDown={(event) => event.stopPropagation()}
      >
        <header className="product-barcode-print-titlebar">
          <span className="product-barcode-print-title">
            <Barcode size={18} />
            Barcode Print
          </span>
          <button
            type="button"
            className="product-barcode-print-close"
            aria-label="Close barcode print"
            onClick={onClose}
          >
            <X size={17} />
          </button>
        </header>

        <div className="product-barcode-print-content">
          <div className="product-barcode-print-fields">
            <label>
              <span>Product</span>
              <input value={`${productNumber} — ${productName}`} readOnly />
            </label>

            <label>
              <span>Barcode</span>
              <select
                value={barcodeId}
                onChange={(event) => setBarcodeId(event.target.value)}
              >
                {barcodes.map((row) => (
                  <option key={row.id} value={row.id}>
                    {row.barcode}
                    {row.unitCode ? ` — ${row.unitCode}` : ''}
                    {row.isPrimary ? ' — Primary' : ''}
                  </option>
                ))}
              </select>
            </label>

            <div className="product-barcode-print-two">
              <label>
                <span>Copies</span>
                <input
                  type="number"
                  min={1}
                  max={100}
                  step={1}
                  value={copies}
                  onChange={(event) => setCopies(event.target.value)}
                />
              </label>

              <label>
                <span>Label Size</span>
                <select
                  value={labelSize}
                  onChange={(event) =>
                    setLabelSize(event.target.value as BarcodeLabelSize)
                  }
                >
                  {getBarcodeLabelSizeOptions().map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>
            </div>

            <label className="product-barcode-print-check">
              <input
                type="checkbox"
                checked={showPrice}
                onChange={(event) => setShowPrice(event.target.checked)}
              />
              <span>Show sale price on label</span>
            </label>

            {selected ? (
              <div className="product-barcode-print-facts">
                <span>
                  Unit: <strong>{selected.unitCode || '—'}</strong>
                </span>
                <span>
                  Factor: <strong>{selected.conversionFactor}</strong>
                </span>
                <span>
                  Price: <strong>{price || '0.0000'}</strong>
                </span>
              </div>
            ) : null}

            {error ? (
              <p className="product-barcode-print-error">{error}</p>
            ) : null}
          </div>

          <div className="product-barcode-print-preview">
            <div className="product-barcode-print-label-preview">
              <strong>{businessName}</strong>
              <b>{productName || 'Product Name'}</b>
              <div className="product-barcode-print-meta">
                <span>{productNumber}</span>
                <span>{selected?.unitCode ?? ''}</span>
              </div>

              {selected ? (
                <div
                  className="product-barcode-print-svg"
                  dangerouslySetInnerHTML={{
                    __html: code39Svg(selected.barcode),
                  }}
                />
              ) : (
                <div className="product-barcode-print-no-barcode">
                  No barcode
                </div>
              )}

              <code>{selected?.barcode ?? '—'}</code>
              {showPrice ? <em>Rs. {Number(price || 0).toFixed(2)}</em> : null}
            </div>

            <small>
              Browser print dialog will open. Choose your thermal/label
              printer and use 100% scale.
            </small>
          </div>
        </div>

        <footer className="product-barcode-print-actions">
          <button type="button" onClick={onClose}>
            Cancel
          </button>
          <button
            type="button"
            className="is-primary"
            disabled={!selected}
            onClick={print}
          >
            <Printer size={16} />
            Print Labels
          </button>
        </footer>
      </section>
    </div>
  )
}
