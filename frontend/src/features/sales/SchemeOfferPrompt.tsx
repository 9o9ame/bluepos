import { useEffect, useState } from 'react'
import { Check, X } from 'lucide-react'
import type { SaleOfferEvaluation } from '../../types/saleSchemes'

type Scheme = SaleOfferEvaluation['schemes'][number]

type Props = {
  schemes: Scheme[]
  appliedUlids: string[]
  onAdd: (scheme: Scheme, quantity: string) => void
  onSkip: (schemeUlid: string) => void
}

/**
 * Shows eligible sale schemes.
 *
 * Nothing is added automatically.
 * The salesman chooses whether to apply the scheme and how many
 * free items to give, up to the configured maximum.
 */
export function SchemeOfferPrompt({
  schemes,
  appliedUlids,
  onAdd,
  onSkip,
}: Props) {
  const [editingSchemeUlid, setEditingSchemeUlid] = useState<string | null>(
    null,
  )

  const [quantities, setQuantities] = useState<Record<string, string>>({})

  useEffect(() => {
    setQuantities((current) => {
      const next = { ...current }

      for (const scheme of schemes) {
        if (next[scheme.ulid] === undefined) {
          next[scheme.ulid] = scheme.max_reward_qty
        }
      }

      return next
    })
  }, [schemes])

  if (schemes.length === 0) {
    return null
  }

  function startAdding(scheme: Scheme) {
    setEditingSchemeUlid(scheme.ulid)

    setQuantities((current) => ({
      ...current,
      [scheme.ulid]:
        current[scheme.ulid] ?? scheme.max_reward_qty,
    }))
  }

  function cancelAdding(schemeUlid: string) {
    setEditingSchemeUlid((current) =>
      current === schemeUlid ? null : current,
    )
  }

  function confirmAdd(scheme: Scheme) {
    const quantity = quantities[scheme.ulid] ?? ''

    if (!isPositiveQuantity(quantity)) {
      return
    }

    if (exceedsMaximum(quantity, scheme.max_reward_qty)) {
      return
    }

    onAdd(scheme, normalizeQuantity(quantity))

    setEditingSchemeUlid(null)
  }

  function updateQuantity(
    scheme: Scheme,
    value: string,
  ) {
    setQuantities((current) => ({
      ...current,
      [scheme.ulid]: value,
    }))
  }

  return (
    <div className="sales-scheme-prompt">
      {schemes.map((scheme) => {
        const alreadyApplied = appliedUlids.includes(
          scheme.ulid,
        )

        const isEditing =
          editingSchemeUlid === scheme.ulid

        const quantity =
          quantities[scheme.ulid] ??
          scheme.max_reward_qty

        const invalidQuantity =
          quantity !== '' &&
          (!isPositiveQuantity(quantity) ||
            exceedsMaximum(
              quantity,
              scheme.max_reward_qty,
            ))

        return (
          <div
            key={scheme.ulid}
            className="sales-scheme-prompt-row"
          >
            <div className="sales-scheme-prompt-text">
              <strong>
                Eligible: {scheme.reward_product.name}
              </strong>

              <span>
                Free up to {scheme.max_reward_qty} · spend{' '}
                {scheme.min_sale_amount}+ · {scheme.name}
              </span>
            </div>

            <div className="sales-scheme-prompt-actions">
              {alreadyApplied ? (
                <span className="sales-scheme-prompt-added">
                  <Check size={12} />
                  Added
                </span>
              ) : isEditing ? (
                <>
                  <input
                    type="number"
                    min="0.000001"
                    step="0.000001"
                    max={scheme.max_reward_qty}
                    value={quantity}
                    aria-label={`Free quantity for ${scheme.name}`}
                    onChange={(event) =>
                      updateQuantity(
                        scheme,
                        event.target.value,
                      )
                    }
                    className="sales-scheme-prompt-qty"
                    autoFocus
                  />

                  <button
                    type="button"
                    className="sales-scheme-prompt-add"
                    disabled={invalidQuantity}
                    onClick={() => confirmAdd(scheme)}
                  >
                    Add
                  </button>

                  <button
                    type="button"
                    className="sales-scheme-prompt-skip"
                    onClick={() =>
                      cancelAdding(scheme.ulid)
                    }
                  >
                    <X size={11} />
                    Cancel
                  </button>
                </>
              ) : (
                <>
                  <button
                    type="button"
                    className="sales-scheme-prompt-add"
                    onClick={() => startAdding(scheme)}
                  >
                    Add Scheme
                  </button>

                  <button
                    type="button"
                    className="sales-scheme-prompt-skip"
                    onClick={() => onSkip(scheme.ulid)}
                  >
                    <X size={11} />
                    Skip
                  </button>
                </>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}

/**
 * Accept a positive decimal with up to 6 decimal places.
 */
function isPositiveQuantity(value: string): boolean {
  if (
    !/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/.test(value)
  ) {
    return false
  }

  return Number(value) > 0
}

/**
 * Prevent quantity from exceeding the configured maximum.
 */
function exceedsMaximum(
  value: string,
  maximum: string,
): boolean {
  return Number(value) > Number(maximum)
}

function normalizeQuantity(value: string): string {
  return Number(value).toFixed(6)
}