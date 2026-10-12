import { useState } from 'react'
import { Boxes, CheckCircle2 } from 'lucide-react'
import { recalculateStock, type StockRecalculationResult } from '../../api/inventory'
import { useFeedback } from '../../feedback/FeedbackProvider'
import { UiButton } from '../ui/UiButton'
import { UiModal } from '../ui/UiModal'
import './RecalculateStockDialog.css'

export function RecalculateStockDialog({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const feedback = useFeedback()
  const [running, setRunning] = useState(false)
  const [result, setResult] = useState<StockRecalculationResult | null>(null)

  async function runRecalculation() {
    const confirmed = await feedback.confirm(
      'Recalculate stock balances from the immutable stock movement ledger? This repairs cached balances only and does not alter stock movements.',
    )

    if (!confirmed) return

    setRunning(true)
    try {
      const response = await recalculateStock()
      setResult(response)
      feedback.success(
        response.corrected_products === 0
          ? 'Stock balances are already consistent.'
          : `${response.corrected_products} product${response.corrected_products === 1 ? '' : 's'} had stock balances corrected.`,
        'Stock recalculated',
      )
    } catch (error) {
      feedback.fromApiError(error, 'Unable to recalculate stock.')
    } finally {
      setRunning(false)
    }
  }

  function close() {
    if (running) return
    setResult(null)
    onClose()
  }

  return (
    <UiModal
      open={open}
      title="Recalculate Stock"
      size="sm"
      onClose={close}
      closeOnEscape={!running}
      footer={
        <>
          <UiButton variant="info" label="Close" disabled={running} onClick={close} />
          <UiButton
            variant="warning"
            icon={<Boxes size={15} />}
            label={running ? 'Recalculating…' : result ? 'Run Again' : 'Recalculate'}
            disabled={running}
            onClick={() => void runRecalculation()}
          />
        </>
      }
    >
      <div className="recalculate-stock-dialog">
        {result ? (
          <>
            <div className="recalculate-stock-result">
              <CheckCircle2 size={28} aria-hidden />
              <strong>{result.corrected_products}</strong>
              <span>
                {result.corrected_products === 1 ? 'Product stock was corrected' : 'Products stock were corrected'}
              </span>
            </div>

            <div className="recalculate-stock-stats">
              <div>
                <span>Checked balances</span>
                <strong>{result.checked_balances}</strong>
              </div>
              <div>
                <span>Corrected balances</span>
                <strong>{result.corrected_balances}</strong>
              </div>
              <div>
                <span>Created balances</span>
                <strong>{result.created_balances}</strong>
              </div>
              <div>
                <span>Missing valuation basis</span>
                <strong>{result.valuation_unavailable}</strong>
              </div>
            </div>

            {result.valuation_unavailable > 0 ? (
              <p className="recalculate-stock-note">
                Some missing balance rows had no existing average cost. Their quantity was rebuilt, but valuation was left unavailable rather than invented.
              </p>
            ) : null}
          </>
        ) : (
          <div className="recalculate-stock-intro">
            <Boxes size={32} aria-hidden />
            <strong>Repair cached stock balances</strong>
            <p>
              BluePOS will compare stock balances with the immutable stock movement ledger for this tenant and repair quantity, last-movement pointer, and cached stock value where needed.
            </p>
            <p>
              Stock movements are never changed or deleted. Historical average cost is not recalculated in this phase.
            </p>
          </div>
        )}
      </div>
    </UiModal>
  )
}
