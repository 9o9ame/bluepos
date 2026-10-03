import { Check, X } from 'lucide-react'
import type { SaleOfferEvaluation } from '../../types/saleSchemes'

type Scheme = SaleOfferEvaluation['schemes'][number]

type Props = {
  schemes: Scheme[]
  /** Scheme ULIDs already added to the cart. */
  appliedUlids: string[]
  onAdd: (scheme: Scheme) => void
  onSkip: (schemeUlid: string) => void
}

/**
 * "Eligible: Free Product X" with Add / Skip.
 *
 * Nothing is added on mount — the server only tells us what the cart qualifies
 * for. A reward reaches the cart only when the salesman presses Add.
 */
export function SchemeOfferPrompt({ schemes, appliedUlids, onAdd, onSkip }: Props) {
  if (schemes.length === 0) {
    return null
  }

  return (
    <div className="sales-scheme-prompt">
      {schemes.map((scheme) => {
        const alreadyApplied = appliedUlids.includes(scheme.ulid)

        return (
          <div key={scheme.ulid} className="sales-scheme-prompt-row">
            <div className="sales-scheme-prompt-text">
              <strong>
                {scheme.auto_apply ? 'Auto free' : 'Eligible'}:{' '}
                {scheme.reward_product.name}
              </strong>
              <span>
                Free up to {scheme.max_reward_qty} · spend {scheme.min_sale_amount}+ · {scheme.name}
              </span>
            </div>

            <div className="sales-scheme-prompt-actions">
              {alreadyApplied ? (
                <span className="sales-scheme-prompt-added">
                  <Check size={12} /> Added
                </span>
              ) : scheme.requires_salesman_decision ? (
                <>
                  <button
                    type="button"
                    className="sales-scheme-prompt-add"
                    onClick={() => onAdd(scheme)}
                  >
                    Add
                  </button>
                  <button
                    type="button"
                    className="sales-scheme-prompt-skip"
                    onClick={() => onSkip(scheme.ulid)}
                  >
                    <X size={11} /> Skip
                  </button>
                </>
              ) : (
                <span className="sales-scheme-prompt-auto">Applied automatically on save</span>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}
