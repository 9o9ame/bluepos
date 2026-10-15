import { useEffect, useMemo, useState } from 'react'
import { UiButton } from '../../components/ui/UiButton'
import { UiSelect } from '../../components/ui/UiSelect'
import { UI_LAYER } from '../../components/ui/uiLayers'
import type {
  SalePaymentAccountOption,
  SalePayload,
} from '../../types/sales'

type FinalizePayment = NonNullable<SalePayload['initial_payments']>[number]

type Props = {
  total: string
  accounts: SalePaymentAccountOption[]
  initialCash?: string
  initialReference?: string
  pending?: boolean
  paymentsEnabled?: boolean
  onConfirm: (payments: FinalizePayment[]) => void
  onCancel: () => void
}

function validMoney(value: string): boolean {
  return value === '' || /^(?:0|[1-9]\d*)(?:\.\d{0,4})?$/.test(value)
}

function moneyValue(value: string): number {
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed : 0
}

export function SaleFinalizePaymentPanel({
  total,
  accounts,
  initialCash = '',
  initialReference = '',
  pending = false,
  paymentsEnabled = true,
  onConfirm,
  onCancel,
}: Props) {
  const defaultCash = useMemo(
    () =>
      accounts.find((account) => account.is_default_cash && account.is_cash)
      ?? accounts.find((account) => account.is_cash)
      ?? null,
    [accounts],
  )
  const [accountUlid, setAccountUlid] = useState('')
  const [cashReceived, setCashReceived] = useState(initialCash)
  const [bankReceived, setBankReceived] = useState('')
  const [reference, setReference] = useState(initialReference)

  useEffect(() => {
    if (!accountUlid && defaultCash) {
      setAccountUlid(defaultCash.ulid)
    }
  }, [accountUlid, defaultCash])

  const selectedAccount =
    accounts.find((account) => account.ulid === accountUlid) ?? null
  const bankSelected = Boolean(selectedAccount?.is_bank)
  const totalNumber = moneyValue(total)
  const cashNumber = moneyValue(cashReceived)
  const bankNumber = bankSelected ? moneyValue(bankReceived) : 0
  const collected = cashNumber + bankNumber
  const balance = Math.max(totalNumber - collected, 0)
  const overpaid = collected > totalNumber + 0.00005
  const cashUnavailable = paymentsEnabled && cashNumber > 0 && !defaultCash
  const bankUnavailable = paymentsEnabled && bankNumber > 0 && !bankSelected

  useEffect(() => {
    if (!bankSelected && bankReceived !== '') {
      setBankReceived('')
    }
  }, [bankReceived, bankSelected])

  function setMoney(
    setter: (value: string) => void,
    value: string,
  ) {
    if (validMoney(value)) setter(value)
  }

  function confirm() {
    if (pending || overpaid || cashUnavailable || bankUnavailable) return

    const payments: FinalizePayment[] = []

    if (paymentsEnabled && cashNumber > 0) {
      payments.push({
        amount: cashNumber.toFixed(4),
        method: 'cash',
      })
    }

    if (paymentsEnabled && bankNumber > 0 && selectedAccount?.is_bank) {
      payments.push({
        amount: bankNumber.toFixed(4),
        method: 'bank',
        account_ulid: selectedAccount.ulid,
        reference: reference.trim() || null,
      })
    }

    onConfirm(payments)
  }

  return (
    <div className="sales-finalize-payment">
      <div className="sales-finalize-payment-heading">
        PAYMENT METHOD
      </div>

      <UiSelect
        value={accountUlid}
        aria-label="Receiving account"
        options={accounts.map((account) => ({
          value: account.ulid,
          label: account.name,
        }))}
        onChange={setAccountUlid}
        disabled={pending || !paymentsEnabled || accounts.length === 0}
        menuZIndex={UI_LAYER.modalDropdown}
      />

      <div className="sales-finalize-total">
        <span>TOTAL:</span>
        <strong>{totalNumber.toFixed(2)}</strong>
      </div>

      <div className="sales-finalize-payment-grid">
        <label>
          <span>CASH RECEIVED:</span>
          <input
            value={cashReceived}
            inputMode="decimal"
            disabled={pending || !paymentsEnabled}
            onChange={(event) =>
              setMoney(setCashReceived, event.target.value)
            }
            aria-label="Cash received"
          />
        </label>

        <label>
          <span>
            {selectedAccount?.is_bank
              ? selectedAccount.name
              : 'CREDIT CARD / ONLINE'}:
          </span>
          <input
            value={bankReceived}
            inputMode="decimal"
            disabled={pending || !paymentsEnabled || !bankSelected}
            onChange={(event) =>
              setMoney(setBankReceived, event.target.value)
            }
            aria-label="Bank or card amount"
          />
        </label>
      </div>

      {bankSelected ? (
        <label className="sales-finalize-reference">
          <span>REFERENCE:</span>
          <input
            value={reference}
            disabled={pending || !paymentsEnabled}
            onChange={(event) => setReference(event.target.value)}
            maxLength={100}
            placeholder="Optional transaction reference"
          />
        </label>
      ) : null}

      <div className="sales-finalize-balance">
        <span>CHANGE / BALANCE:</span>
        <strong>{balance.toFixed(2)}</strong>
      </div>

      {overpaid ? (
        <p className="sales-payment-error">
          Cash plus bank/card amount cannot exceed the invoice total.
        </p>
      ) : null}

      {cashUnavailable ? (
        <p className="sales-payment-error">
          Configure an active Cash in Hand account before receiving cash.
        </p>
      ) : null}

      <div className="sales-finalize-actions">
        <UiButton
          variant="success"
          onClick={confirm}
          disabled={pending || overpaid || cashUnavailable || bankUnavailable}
        >
          {pending ? 'Saving…' : 'Save'}
        </UiButton>

        <UiButton
          variant="info"
          onClick={onCancel}
          disabled={pending}
        >
          Close
        </UiButton>
      </div>
    </div>
  )
}
