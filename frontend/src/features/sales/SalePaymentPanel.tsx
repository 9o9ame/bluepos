import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import {
  Banknote,
  CreditCard,
  Landmark,
  Wallet,
} from 'lucide-react'
import { ApiClientError } from '../../api/client'
import { createSalePayment } from '../../api/sales'
import type {
  Sale,
  SalePaymentMethod,
} from '../../types/sales'

const METHODS: Array<{
  value: SalePaymentMethod
  label: string
  icon: typeof Banknote
}> = [
  { value: 'cash', label: 'Cash', icon: Banknote },
  { value: 'card', label: 'Card', icon: CreditCard },
  { value: 'bank', label: 'Bank', icon: Landmark },
  { value: 'credit', label: 'On account', icon: Wallet },
]

type Props = {
  sale: Sale
  /** Remaining amount the server considers due. */
  outstanding: string
  onCollected: (payment: {
    amount: string
    method: string
  }) => void
}

function isValidAmount(value: string): boolean {
  return /^(?:0|[1-9]\d*)(?:\.\d{0,4})?$/.test(value)
}

function isPositiveAmount(value: string): boolean {
  return isValidAmount(value) && Number(value) > 0
}

/**
 * Collect payment against a saved sale.
 *
 * Partial payments are allowed. The server remains the source of truth for
 * the final due amount and rejects invalid or excessive payments.
 */
export function SalePaymentPanel({
  sale,
  outstanding,
  onCollected,
}: Props) {
  const [amount, setAmount] = useState(outstanding)
  const [method, setMethod] =
    useState<SalePaymentMethod>('cash')
  const [error, setError] = useState<string | null>(null)
  const [idemKey, setIdemKey] = useState(() => newKey())

  const outstandingNumber = Number(outstanding) || 0

  const mutation = useMutation({
    mutationFn: async () => {
      return createSalePayment(
        sale.ulid,
        {
          amount,
          method,
        },
        idemKey,
      )
    },

    onSuccess: (payment) => {
      setError(null)

      onCollected({
        amount: payment.amount,
        method: payment.method,
      })

      // A new key is generated only after a successful collection.
      // Retries of the same failed attempt keep the existing key.
      setIdemKey(newKey())
    },

    onError: (err) => {
      setError(
        err instanceof ApiClientError
          ? err.message
          : 'Unable to collect payment.',
      )
    },
  })

  // Keep the payment field synchronized with the latest server-side due.
  useEffect(() => {
    setAmount(outstanding)
    setError(null)
  }, [outstanding, sale.ulid])

  const isSettled = outstandingNumber <= 0

  const invalidAmount =
    !isPositiveAmount(amount) ||
    Number(amount) > outstandingNumber

  function handleAmountChange(value: string) {
    if (value === '' || isValidAmount(value)) {
      setAmount(value)
      setError(null)
    }
  }

  function collect() {
    if (invalidAmount || mutation.isPending) {
      return
    }

    mutation.mutate()
  }

  return (
    <div className="sales-payment-panel">
      <div className="sales-payment-due">
        <span>Due</span>
        <strong>{outstanding}</strong>
      </div>

      {isSettled ? (
        <p className="sales-payment-settled">
          This sale is fully paid.
        </p>
      ) : (
        <>
          <div className="sales-payment-methods">
            {METHODS.map(
              ({ value, label, icon: Icon }) => (
                <button
                  key={value}
                  type="button"
                  className={
                    method === value
                      ? 'sales-payment-method is-active'
                      : 'sales-payment-method'
                  }
                  onClick={() => {
                    setMethod(value)
                    setError(null)
                  }}
                  disabled={mutation.isPending}
                >
                  <Icon size={13} />
                  {label}
                </button>
              ),
            )}
          </div>

          <input
            className="desktop-input sales-payment-amount"
            value={amount}
            inputMode="decimal"
            aria-label="Payment amount"
            placeholder="Payment amount"
            onChange={(event) =>
              handleAmountChange(event.target.value)
            }
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault()
                collect()
              }
            }}
            disabled={mutation.isPending}
          />

          {amount !== '' &&
          Number(amount) > outstandingNumber ? (
            <p className="sales-payment-error">
              Payment cannot exceed the outstanding amount.
            </p>
          ) : null}

          <button
            type="button"
            className="sales-payment-collect"
            disabled={
              mutation.isPending ||
              invalidAmount
            }
            onClick={collect}
          >
            {mutation.isPending
              ? 'Collecting…'
              : 'Collect'}
          </button>

          {error ? (
            <p className="sales-payment-error">
              {error}
            </p>
          ) : null}
        </>
      )}
    </div>
  )
}

function newKey(): string {
  return `pay-${Date.now()}-${Math.random()
    .toString(36)
    .slice(2, 10)}`
}