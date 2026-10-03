import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Banknote, CreditCard, Landmark, Wallet } from 'lucide-react'
import { ApiClientError } from '../../api/client'
import { createSalePayment } from '../../api/sales'
import type { Sale, SalePaymentMethod } from '../../types/sales'

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
  onCollected: (payment: { amount: string; method: string }) => void
}

/**
 * Collect payment against a saved sale.
 *
 * Partial payments are allowed, so the amount defaults to whatever is due but
 * can be lower. The server recomputes the outstanding balance and rejects an
 * overpayment — we only ever display what it returns.
 */
export function SalePaymentPanel({ sale, outstanding, onCollected }: Props) {
  const [amount, setAmount] = useState(outstanding)
  const [method, setMethod] = useState<SalePaymentMethod>('cash')
  const [error, setError] = useState<string | null>(null)
  const [idemKey, setIdemKey] = useState(() => newKey())

  const mutation = useMutation({
    mutationFn: async () => {
      const payment = await createSalePayment(
        sale.ulid,
        { amount, method },
        idemKey,
      )
      return payment
    },
    onSuccess: (payment) => {
      setError(null)
      onCollected({ amount: payment.amount, method: payment.method })
      // A new key per successful collection; a retry keeps the old one.
      setIdemKey(newKey())
    },
    onError: (err) => {
      setError(err instanceof ApiClientError ? err.message : 'Unable to collect payment.')
    },
  })

  const isSettled = Number(outstanding) <= 0

  return (
    <div className="sales-payment-panel">
      <div className="sales-payment-due">
        <span>Due</span>
        <strong>{outstanding}</strong>
      </div>

      {isSettled ? (
        <p className="sales-payment-settled">This sale is fully paid.</p>
      ) : (
        <>
          <div className="sales-payment-methods">
            {METHODS.map(({ value, label, icon: Icon }) => (
              <button
                key={value}
                type="button"
                className={
                  method === value
                    ? 'sales-payment-method is-active'
                    : 'sales-payment-method'
                }
                onClick={() => setMethod(value)}
              >
                <Icon size={13} /> {label}
              </button>
            ))}
          </div>

          <input
            className="desktop-input sales-payment-amount"
            value={amount}
            inputMode="decimal"
            aria-label="Payment amount"
            onChange={(e) => setAmount(e.target.value)}
          />

          <button
            type="button"
            className="sales-payment-collect"
            disabled={mutation.isPending || !amount}
            onClick={() => mutation.mutate()}
          >
            Collect
          </button>

          {error ? <p className="sales-payment-error">{error}</p> : null}
        </>
      )}
    </div>
  )
}

function newKey(): string {
  return `pay-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}
