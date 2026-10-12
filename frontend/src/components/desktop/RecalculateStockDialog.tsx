import { useState } from 'react'
import { Boxes, CheckCircle2, Database, LockKeyhole, ShieldCheck } from 'lucide-react'
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
              <span className="recalculate-stock-result-icon">
                <CheckCircle2 size={24} aria-hidden />
              </span>
              <div className="recalculate-stock-result-count">{result.corrected_products}</div>
              <strong>
                {result.corrected_products === 0
                  ? 'Stock balances are already correct'
                  : result.corrected_products === 1
                    ? 'Product stock corrected'
                    : 'Products stock corrected'}
              </strong>
              <span>
                {result.corrected_products === 0
                  ? 'No balance repair was required.'
                  : 'Cached stock balances were repaired from the movement ledger.'}
              </span>
            </div>

            <div className="recalculate-stock-stats">
              <div>
                <span>Checked</span>
                <strong>{result.checked_balances}</strong>
              </div>
              <div>
                <span>Corrected</span>
                <strong>{result.corrected_balances}</strong>
              </div>
              <div>
                <span>Created</span>
                <strong>{result.created_balances}</strong>
              </div>
              <div>
                <span>Valuation pending</span>
                <strong>{result.valuation_unavailable}</strong>
              </div>
            </div>

            {result.valuation_unavailable > 0 ? (
              <p className="recalculate-stock-note">
                {result.valuation_unavailable} balance{result.valuation_unavailable === 1 ? '' : 's'} had no existing average cost. Quantity was rebuilt, but valuation was intentionally left unavailable.
              </p>
            ) : (
              <div className="recalculate-stock-safe-status">
                <ShieldCheck size={14} aria-hidden />
                Recalculation completed without changing stock movements.
              </div>
            )}
          </>
        ) : (
          <>
            <div className="recalculate-stock-intro">
              <span className="recalculate-stock-hero-icon">
                <Boxes size={28} aria-hidden />
              </span>
              <div>
                <strong>Repair cached stock balances</strong>
                <p>
                  Compare current balances with the immutable stock movement ledger and correct only the cached stock state.
                </p>
              </div>
            </div>

            <div className="recalculate-stock-assurances">
              <div>
                <Database size={15} aria-hidden />
                <span>
                  <strong>Ledger based</strong>
                  Quantity comes from posted stock movements.
                </span>
              </div>
              <div>
                <LockKeyhole size={15} aria-hidden />
                <span>
                  <strong>Movements protected</strong>
                  Existing movements are never edited or deleted.
                </span>
              </div>
              <div>
                <ShieldCheck size={15} aria-hidden />
                <span>
                  <strong>Safe valuation</strong>
                  Existing average cost is preserved, never invented.
                </span>
              </div>
            </div>

            <p className="recalculate-stock-caption">
              This operation is tenant-scoped and runs under an inventory lock to avoid concurrent stock-posting races.
            </p>
          </>
        )}
      </div>
    </UiModal>
  )
}
