import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { RefreshCw, X } from 'lucide-react'
import { recalculateStockBalances, type RecalculateStockResult } from '../api/inventory'
import { DesktopPanel } from '../components/desktop/DesktopPanel'
import { UiButton } from '../components/ui/UiButton'
import { useFeedback } from '../feedback/FeedbackProvider'
import { useAuth } from '../features/auth/AuthProvider'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './RecalculateStockPage.css'

export function RecalculateStockPage() {
  const { session } = useAuth()
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const [result, setResult] = useState<RecalculateStockResult | null>(null)

  const mutation = useMutation({
    mutationFn: recalculateStockBalances,
    onSuccess: (data) => {
      setResult(data)
      feedback.success('Stock balances recalculated.')
    },
    onError: (error) => {
      feedback.fromApiError(error, 'Unable to recalculate stock balances.')
    },
  })

  async function run() {
    const confirmed = await feedback.confirm(
      'Recalculate stock balances for the active warehouse from immutable stock movements? This repairs derived balances only and does not edit movement history.',
    )
    if (!confirmed) return

    setResult(null)
    mutation.mutate()
  }

  return (
    <DesktopPanel
      title="Recalculate Stock"
      toolbar={
        <>
          <UiButton
            variant="warning"
            icon={<RefreshCw size={14} />}
            label={mutation.isPending ? 'Recalculating…' : 'Recalculate'}
            disabled={mutation.isPending}
            onClick={() => void run()}
          />
          <UiButton variant="info" icon={<X size={14} />} label="Close" onClick={closeActiveTab} />
        </>
      }
    >
      <div className="recalculate-stock-layout">
        <section className="recalculate-stock-card">
          <h2>Active warehouse</h2>
          <strong>
            {session?.warehouse.code ?? '—'} — {session?.warehouse.name ?? '—'}
          </strong>
          <p>
            The server tenant, branch and warehouse context is authoritative. No warehouse can be
            selected from this screen.
          </p>
        </section>

        <section className="recalculate-stock-card is-warning">
          <h2>What this tool does</h2>
          <p>
            Rebuilds derived stock quantity, weighted average cost, stock value and last-movement
            pointers from the append-only stock movement ledger.
          </p>
          <p>
            It never edits or deletes stock movements, posted documents, journals or accounting
            records.
          </p>
        </section>

        <section className="recalculate-stock-card">
          <h2>Replay semantics</h2>
          <p>
            Movements are replayed in posting/insertion order so weighted-average costing matches
            the existing stock posting engine, including backdated documents.
          </p>
        </section>

        {result ? (
          <section className="recalculate-stock-result" aria-live="polite">
            <h2>Completed</h2>
            <dl>
              <dt>Warehouse</dt>
              <dd>{result.warehouse.code} — {result.warehouse.name}</dd>
              <dt>Products processed</dt>
              <dd>{result.processed_products}</dd>
              <dt>Balances changed</dt>
              <dd>{result.changed_balances}</dd>
              <dt>Movements replayed</dt>
              <dd>{result.movement_count}</dd>
            </dl>
          </section>
        ) : null}
      </div>
    </DesktopPanel>
  )
}
