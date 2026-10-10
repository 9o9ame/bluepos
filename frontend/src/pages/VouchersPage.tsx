import { useEffect, useMemo, useState, type KeyboardEvent } from 'react'
import { CheckCircle2, Plus, Printer, RefreshCw, Save, Search, Trash2, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import {
  createVoucher,
  deleteVoucher,
  fetchVoucher,
  fetchVoucherAccounts,
  fetchVoucherSummary,
  fetchVouchers,
  postVoucher,
  updateVoucher,
  type Voucher,
  type VoucherAccount,
  type VoucherInputLine,
  type VoucherPayload,
  type VoucherType,
} from '../api/vouchers'
import { ApiClientError } from '../api/client'
import { askConfirm } from '../feedback/FeedbackProvider'
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { VoucherPrintModal, type VoucherPrintData } from '../components/vouchers/VoucherPrintModal'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import './VouchersPage.css'

type EntryLine = {
  key: string
  account_ulid: string
  narration: string
  amount: string
  debit: string
  credit: string
}

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function currentTime(): string {
  return new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date())
}

function newLine(): EntryLine {
  return {
    key: crypto.randomUUID(),
    account_ulid: '',
    narration: '',
    amount: '',
    debit: '',
    credit: '',
  }
}

function money(value: number | string): string {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed.toFixed(4) : '0.0000'
}

function voucherLabel(type: VoucherType): string {
  if (type === 'opening') return 'Opening Balance Voucher'
  if (type === 'payment') return 'Cash Payment Voucher'
  if (type === 'receiving') return 'Cash Receiving Voucher'
  return 'Journal Voucher'
}

function voucherNumberLabel(type: VoucherType): string {
  if (type === 'opening') return 'OPV#'
  if (type === 'payment') return 'DV#'
  if (type === 'receiving') return 'CV#'
  return 'JV#'
}

function signedEffect(account: VoucherAccount | undefined, debit: number, credit: number): number {
  if (!account) return 0
  return account.normal_balance === 'credit' ? credit - debit : debit - credit
}

export function VouchersPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const [searchParams] = useSearchParams()
  const canView = useCan('accounting.journal.view')
  const canCreate = useCan('vouchers.create')
  const canApprove = useCan('vouchers.approve')

  const requestedType = searchParams.get('type')
  const initialType: VoucherType =
    requestedType === 'opening' || requestedType === 'receiving' || requestedType === 'journal'
      ? requestedType
      : 'payment'

  const [tab, setTab] = useState<'entry' | 'search' | 'summary'>('entry')
  const [type, setType] = useState<VoucherType>(initialType)
  const [document, setDocument] = useState<Voucher | null>(null)
  const [entryDate, setEntryDate] = useState(today())
  const [bookNumber, setBookNumber] = useState('')
  const [headerAccountUlid, setHeaderAccountUlid] = useState('')
  const [lines, setLines] = useState<EntryLine[]>([newLine()])
  const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID())
  const [error, setError] = useState<string | null>(null)
  const [printOpen, setPrintOpen] = useState(false)

  const [q, setQ] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedVoucherUlid, setSelectedVoucherUlid] = useState<string | null>(null)
  const [summaryFrom, setSummaryFrom] = useState(today())
  const [summaryTo, setSummaryTo] = useState(today())
  const [summaryType, setSummaryType] = useState('')

  const balanceParams = {
    as_of: entryDate,
    before_voucher_ulid: document?.ulid,
  }

  const allAccountsQuery = useQuery({
    queryKey: ['voucher-accounts', 'all', entryDate, document?.ulid ?? 'new'],
    queryFn: () => fetchVoucherAccounts(balanceParams),
    enabled: canView,
  })
  const cashAccountsQuery = useQuery({
    queryKey: ['voucher-accounts', 'cash', entryDate, document?.ulid ?? 'new'],
    queryFn: () => fetchVoucherAccounts({ ...balanceParams, cash_only: true }),
    enabled: canView,
  })
  const listQuery = useQuery({
    queryKey: ['vouchers', q, typeFilter, statusFilter, dateFrom, dateTo],
    queryFn: () =>
      fetchVouchers({
        q: q || undefined,
        type: (typeFilter || undefined) as VoucherType | undefined,
        status: (statusFilter || undefined) as 'draft' | 'posted' | undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        per_page: 100,
      }),
    enabled: canView && tab === 'search',
  })
  const summaryQuery = useQuery({
    queryKey: ['voucher-summary', summaryFrom, summaryTo, summaryType],
    queryFn: () =>
      fetchVoucherSummary({
        date_from: summaryFrom,
        date_to: summaryTo,
        type: (summaryType || undefined) as VoucherType | undefined,
      }),
    enabled: canView && tab === 'summary',
  })

  const accounts = allAccountsQuery.data ?? []
  const cashAccounts = cashAccountsQuery.data ?? []
  const accountMap = useMemo(
    () => new Map(accounts.map((account) => [account.ulid, account])),
    [accounts],
  )
  const cashAccountMap = useMemo(
    () => new Map(cashAccounts.map((account) => [account.ulid, account])),
    [cashAccounts],
  )
  const readOnly = document?.status === 'posted'
  const heading = voucherLabel(type)
  const isDebitCreditType = type === 'journal' || type === 'opening'
  const displayTime = document?.posted_at
    ? new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit', hour12: false }).format(
        new Date(document.posted_at),
      )
    : currentTime()

  const debitTotal = useMemo(
    () =>
      isDebitCreditType
        ? lines.reduce((sum, line) => sum + (Number(line.debit) || 0), 0)
        : lines.reduce((sum, line) => sum + (Number(line.amount) || 0), 0),
    [lines, type],
  )
  const creditTotal = useMemo(
    () =>
      isDebitCreditType
        ? lines.reduce((sum, line) => sum + (Number(line.credit) || 0), 0)
        : lines.reduce((sum, line) => sum + (Number(line.amount) || 0), 0),
    [lines, type],
  )

  const headerAccount = cashAccountMap.get(headerAccountUlid) ?? accountMap.get(headerAccountUlid)
  const preBalance = isDebitCreditType ? 0 : Number(headerAccount?.balance ?? 0)
  const thisVoucher = isDebitCreditType ? 0 : lines.reduce((sum, line) => sum + (Number(line.amount) || 0), 0)
  const headerDebit = type === 'receiving' ? thisVoucher : 0
  const headerCredit = type === 'payment' ? thisVoucher : 0
  const totalBalance =
    isDebitCreditType
      ? 0
      : preBalance + signedEffect(headerAccount, headerDebit, headerCredit)

  const printData = useMemo<VoucherPrintData | null>(() => {
    if (!document) return null

    return {
      title: heading,
      voucherNumber: document.voucher_number,
      bookNumber,
      date: entryDate,
      time: displayTime,
      account: type === 'opening' ? 'OPV' : type === 'journal' ? 'JV' : (headerAccount ? `${headerAccount.code} · ${headerAccount.name}` : ''),
      preBalance: money(preBalance),
      thisVoucher: money(thisVoucher),
      totalBalance: money(totalBalance),
      type: type === 'opening' ? 'journal' : type,
      rows: lines.map((line) => {
        const account = accountMap.get(line.account_ulid)
        return {
          account: account ? `${account.code} · ${account.name}` : '',
          narration: line.narration,
          balance: money(lineBalance(line)),
          amount: isDebitCreditType ? undefined : money(line.amount || 0),
          debit: isDebitCreditType ? money(line.debit || 0) : undefined,
          credit: isDebitCreditType ? money(line.credit || 0) : undefined,
          closing: money(lineClosing(line)),
        }
      }),
    }
  }, [
    accountMap,
    bookNumber,
    displayTime,
    document,
    entryDate,
    headerAccount,
    heading,
    lines,
    preBalance,
    thisVoucher,
    totalBalance,
    type,
  ])

  useEffect(() => {
    if (
      requestedType !== 'opening'
      && requestedType !== 'payment'
      && requestedType !== 'receiving'
      && requestedType !== 'journal'
    ) {
      return
    }

    if (requestedType !== type || document) {
      resetEditor(requestedType)
    }
    setTab('entry')
  }, [requestedType])

  function handleError(err: unknown) {
    setError(err instanceof ApiClientError || err instanceof Error ? err.message : 'Voucher request failed.')
  }

  function resetEditor(nextType: VoucherType = type) {
    setDocument(null)
    setType(nextType)
    setEntryDate(today())
    setBookNumber('')
    setHeaderAccountUlid('')
    setLines([newLine()])
    setIdempotencyKey(crypto.randomUUID())
    setError(null)
  }

  function loadVoucher(voucher: Voucher) {
    setDocument(voucher)
    setType(voucher.type)
    setEntryDate(voucher.entry_date)
    setBookNumber(voucher.book_number ?? '')
    setHeaderAccountUlid(voucher.header_account?.ulid ?? '')
    const source =
      voucher.type === 'payment' || voucher.type === 'receiving'
        ? voucher.lines.filter((line) => !line.is_header)
        : voucher.lines
    setLines(
      source.map((line) => ({
        key: line.ulid,
        account_ulid: line.account?.ulid ?? '',
        narration: line.narration ?? '',
        amount:
          voucher.type === 'payment'
            ? line.debit
            : voucher.type === 'receiving'
              ? line.credit
              : '',
        debit: line.debit,
        credit: line.credit,
      })),
    )
    setError(null)
  }

  function buildPayload(): VoucherPayload {
    const detailLines: VoucherInputLine[] = lines.map((line) =>
      isDebitCreditType
        ? {
            account_ulid: line.account_ulid,
            narration: line.narration || null,
            debit: line.debit || '0',
            credit: line.credit || '0',
          }
        : {
            account_ulid: line.account_ulid,
            narration: line.narration || null,
            amount: line.amount || '0',
          },
    )

    return {
      entry_date: entryDate,
      book_number: bookNumber || null,
      description: null,
      header_account_ulid: isDebitCreditType ? null : headerAccountUlid,
      lines: detailLines,
    }
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = buildPayload()
      if (document?.ulid) return updateVoucher(document.ulid, payload)
      return createVoucher({ ...payload, type }, idempotencyKey)
    },
    onSuccess: async (saved) => {
      loadVoucher(saved)
      await queryClient.invalidateQueries({ queryKey: ['vouchers'] })
      await queryClient.invalidateQueries({ queryKey: ['voucher-accounts'] })
    },
  })

  const postMutation = useMutation({
    mutationFn: async () => {
      const saved = await saveMutation.mutateAsync()
      return postVoucher(saved.ulid)
    },
    onSuccess: async (posted) => {
      loadVoucher(posted)
      await queryClient.invalidateQueries({ queryKey: ['vouchers'] })
      await queryClient.invalidateQueries({ queryKey: ['voucher-summary'] })
      await queryClient.invalidateQueries({ queryKey: ['voucher-accounts'] })
    },
  })

  const openMutation = useMutation({
    mutationFn: fetchVoucher,
    onSuccess: (voucher) => {
      loadVoucher(voucher)
      setTab('entry')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteVoucher,
    onSuccess: async () => {
      resetEditor(type)
      await queryClient.invalidateQueries({ queryKey: ['vouchers'] })
    },
  })

  useWorkspaceHandlers({
    save: () => {
      if (tab === 'entry' && !readOnly && canCreate) {
        void saveMutation.mutateAsync().catch(handleError)
      }
    },
    refresh: () => {
      if (tab === 'search') void listQuery.refetch()
      else if (tab === 'summary') void summaryQuery.refetch()
      else if (document?.ulid) void openMutation.mutateAsync(document.ulid).catch(handleError)
    },
  })

  function updateLine(key: string, patch: Partial<EntryLine>) {
    setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  }

  function focusLineCell(rowIndex: number, field: string) {
    requestAnimationFrame(() => {
      const target = window.document.querySelector<HTMLElement>(
        `[data-voucher-row="${rowIndex}"][data-voucher-field="${field}"]`,
      )
      target?.focus()
    })
  }

  function handleLineEnter(
    event: KeyboardEvent<HTMLElement>,
    rowIndex: number,
    field: 'account' | 'narration' | 'amount' | 'debit' | 'credit',
  ) {
    if (event.key !== 'Enter' || readOnly) return

    event.preventDefault()

    const fields: Array<'account' | 'narration' | 'amount' | 'debit' | 'credit'> =
      isDebitCreditType
        ? ['account', 'narration', 'debit', 'credit']
        : ['account', 'narration', 'amount']
    const fieldIndex = fields.indexOf(field)

    if (fieldIndex >= 0 && fieldIndex < fields.length - 1) {
      focusLineCell(rowIndex, fields[fieldIndex + 1])
      return
    }

    const nextRowIndex = rowIndex + 1
    if (nextRowIndex < lines.length) {
      focusLineCell(nextRowIndex, 'account')
      return
    }

    setLines((current) => [...current, newLine()])
    focusLineCell(nextRowIndex, 'account')
  }

  function lineBalance(line: EntryLine): number {
    return Number(accountMap.get(line.account_ulid)?.balance ?? 0)
  }

  function lineClosing(line: EntryLine): number {
    const account = accountMap.get(line.account_ulid)
    const opening = Number(account?.balance ?? 0)

    if (type === 'payment') {
      const amount = Number(line.amount) || 0
      return opening + signedEffect(account, amount, 0)
    }
    if (type === 'receiving') {
      const amount = Number(line.amount) || 0
      return opening + signedEffect(account, 0, amount)
    }

    return opening + signedEffect(account, Number(line.debit) || 0, Number(line.credit) || 0)
  }

  if (!canView) {
    return (
      <DesktopPanel title="Vouchers">
        <p className="voucher-permission">You do not have permission to view accounting vouchers.</p>
      </DesktopPanel>
    )
  }

  return (
    <DesktopPanel
      className="voucher-workspace"
      title={heading}
      toolbar={
        <>
          <DesktopButton icon={<Plus size={13} />} label="New" disabled={!canCreate} onClick={() => resetEditor(type)} />
          <DesktopButton
            icon={<Save size={13} />}
            label="Save Draft"
            disabled={tab !== 'entry' || readOnly || !canCreate || saveMutation.isPending}
            onClick={() => void saveMutation.mutateAsync().catch(handleError)}
          />
          <DesktopButton
            icon={<CheckCircle2 size={13} />}
            label="Post"
            variant="success"
            disabled={tab !== 'entry' || readOnly || !canApprove || postMutation.isPending}
            onClick={() => {
              void (async () => {
                if (!(await askConfirm('Post this voucher? Posted vouchers cannot be edited.'))) return
                void postMutation.mutateAsync().catch(handleError)
              })()
            }}
          />
          <DesktopButton
            icon={<Printer size={13} />}
            label="Print"
            variant="info"
            disabled={tab !== 'entry' || !document?.ulid}
            onClick={() => setPrintOpen(true)}
          />
          <DesktopButton
            icon={<RefreshCw size={13} />}
            label="Refresh"
            shortcut="F8"
            onClick={() => {
              if (tab === 'search') void listQuery.refetch()
              else if (tab === 'summary') void summaryQuery.refetch()
              else if (document?.ulid) void openMutation.mutateAsync(document.ulid).catch(handleError)
              else void queryClient.invalidateQueries({ queryKey: ['voucher-accounts'] })
            }}
          />
          {document?.ulid && !readOnly ? (
            <DesktopButton
              icon={<Trash2 size={13} />}
              label="Delete"
              variant="danger"
              onClick={() => {
                void (async () => {
                  if (!(await askConfirm('Delete this voucher draft?'))) return
                  void deleteMutation.mutateAsync(document.ulid).catch(handleError)
                })()
              }}
            />
          ) : null}
          <DesktopButton icon={<X size={13} />} label="Close" onClick={closeActiveTab} />
        </>
      }
    >
      <div className="voucher-tabs" role="tablist" aria-label="Voucher workspace">
        <UiButton variant={tab === 'entry' ? 'primary' : 'default'} onClick={() => setTab('entry')}>Voucher Entry</UiButton>
        <UiButton variant={tab === 'search' ? 'primary' : 'default'} icon={<Search size={13} />} onClick={() => setTab('search')}>Search Vouchers</UiButton>
        <UiButton variant={tab === 'summary' ? 'primary' : 'default'} onClick={() => setTab('summary')}>Summaries</UiButton>
      </div>

      {error ? <div className="voucher-error">{error}</div> : null}

      {tab === 'entry' ? (
        <div className="voucher-entry">
          <div className="voucher-entry-meta">
            <div className="voucher-meta-row">
              <label className="voucher-no-field">
                <span>{voucherNumberLabel(type)}</span>
                <input className="desktop-input" value={document?.voucher_number ?? 'Auto'} disabled />
              </label>
              <label className="voucher-book-field">
                <span>Book#</span>
                <input
                  className="desktop-input"
                  value={bookNumber}
                  disabled={readOnly}
                  maxLength={50}
                  onChange={(event) => setBookNumber(event.target.value)}
                />
              </label>
              <label className="voucher-date-field">
                <span>Date</span>
                <input className="desktop-input" type="date" value={entryDate} disabled={readOnly} onChange={(event) => setEntryDate(event.target.value)} />
              </label>
              <label className="voucher-time-field">
                <span>Time</span>
                <input className="desktop-input" value={displayTime} disabled />
              </label>
              <label className="voucher-account-field">
                <span>Account</span>
                {isDebitCreditType ? (
                  <input className="desktop-input" value={type === 'opening' ? 'OPV' : 'JV'} disabled />
                ) : (
                  <UiSelect
                    aria-label="Voucher cash or bank account"
                    value={headerAccountUlid}
                    disabled={readOnly}
                    placeholder="Select cash / bank account"
                    menuMinWidth={720}
                    menuColumns={[
                      { header: 'Code', width: '90px' },
                      { header: 'Vendor / Customer / Account', width: 'minmax(220px, 1.8fr)' },
                      { header: 'Address', width: 'minmax(160px, 1fr)' },
                      { header: 'Acc Type', width: '150px' },
                      { header: 'Party', width: '90px' },
                    ]}
                    options={cashAccounts.map((account) => ({
                      value: account.ulid,
                      label: `${account.code} · ${account.name}`,
                      columns: [
                        account.code,
                        account.name,
                        account.address ?? '',
                        account.account_type ?? '',
                        account.party_type ?? 'account',
                      ],
                    }))}
                    onChange={setHeaderAccountUlid}
                  />
                )}
              </label>
              <label className="voucher-balance-field">
                <span>Pre balance</span>
                <input className="desktop-input voucher-balance is-pre" value={money(preBalance)} disabled />
              </label>
              <label className="voucher-balance-field">
                <span>This Voucher</span>
                <input className="desktop-input voucher-balance is-current" value={money(thisVoucher)} disabled />
              </label>
              <label className="voucher-balance-field">
                <span>Total Bal</span>
                <input className="desktop-input voucher-balance is-total" value={money(totalBalance)} disabled />
              </label>
            </div>
          </div>

          <div className="voucher-line-grid-wrap">
            <table className="voucher-line-grid">
              <thead>
                <tr>
                  <th>Vendor / Customer / Account</th>
                  <th>Narration</th>
                  <th>Balance</th>
                  {isDebitCreditType ? <><th>Debit</th><th>Credit</th></> : <th>Amount</th>}
                  <th>Closing</th>
                  <th aria-label="Actions" />
                </tr>
              </thead>
              <tbody>
                {lines.map((line) => (
                  <tr key={line.key}>
                    <td>
                      <UiSelect
                        aria-label="Voucher account"
                        value={line.account_ulid}
                        disabled={readOnly}
                        placeholder="Select account"
                        menuMinWidth={820}
                        menuColumns={[
                      { header: 'Code', width: '90px' },
                      { header: 'Vendor / Customer / Account', width: 'minmax(220px, 1.8fr)' },
                      { header: 'Address', width: 'minmax(160px, 1fr)' },
                      { header: 'Acc Type', width: '150px' },
                      { header: 'Party', width: '90px' },
                    ]}
                        options={accounts.map((account) => ({
                          value: account.ulid,
                          label: `${account.code} · ${account.name}`,
                          columns: [
                            account.code,
                            account.name,
                            account.address ?? '',
                            account.account_type ?? '',
                            account.party_type ?? 'account',
                          ],
                        }))}
                        triggerProps={{
                          'data-voucher-row': lines.indexOf(line),
                          'data-voucher-field': 'account',
                        }}
                        onChange={(value) => {
                          const rowIndex = lines.indexOf(line)
                          updateLine(line.key, { account_ulid: value })
                          focusLineCell(rowIndex, 'narration')
                        }}
                      />
                    </td>
                    <td>
                      <input
                        className="desktop-input"
                        value={line.narration}
                        disabled={readOnly}
                        data-voucher-row={lines.indexOf(line)}
                        data-voucher-field="narration"
                        onKeyDown={(event) => handleLineEnter(event, lines.indexOf(line), 'narration')}
                        onChange={(event) => updateLine(line.key, { narration: event.target.value })}
                      />
                    </td>
                    <td className="voucher-readonly-money">{money(lineBalance(line))}</td>
                    {isDebitCreditType ? (
                      <>
                        <td>
                          <input
                            className="desktop-input voucher-money"
                            inputMode="decimal"
                            value={line.debit}
                            disabled={readOnly}
                            data-voucher-row={lines.indexOf(line)}
                            data-voucher-field="debit"
                            onKeyDown={(event) => handleLineEnter(event, lines.indexOf(line), 'debit')}
                            onChange={(event) => updateLine(line.key, { debit: event.target.value })}
                          />
                        </td>
                        <td>
                          <input
                            className="desktop-input voucher-money"
                            inputMode="decimal"
                            value={line.credit}
                            disabled={readOnly}
                            data-voucher-row={lines.indexOf(line)}
                            data-voucher-field="credit"
                            onKeyDown={(event) => handleLineEnter(event, lines.indexOf(line), 'credit')}
                            onChange={(event) => updateLine(line.key, { credit: event.target.value })}
                          />
                        </td>
                      </>
                    ) : (
                      <td>
                        <input
                          className="desktop-input voucher-money"
                          inputMode="decimal"
                          value={line.amount}
                          disabled={readOnly}
                          data-voucher-row={lines.indexOf(line)}
                          data-voucher-field="amount"
                          onKeyDown={(event) => handleLineEnter(event, lines.indexOf(line), 'amount')}
                          onChange={(event) => updateLine(line.key, { amount: event.target.value })}
                        />
                      </td>
                    )}
                    <td className="voucher-readonly-money">{money(lineClosing(line))}</td>
                    <td>
                      <UiButton
                        variant="danger"
                        disabled={readOnly || lines.length <= 1}
                        aria-label="Remove voucher line"
                        onClick={() => setLines((current) => current.filter((item) => item.key !== line.key))}
                      >
                        <Trash2 size={13} />
                      </UiButton>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="voucher-entry-footer">
            <div className="voucher-totals">
              <span>Debit <strong>{money(debitTotal)}</strong></span>
              <span>Credit <strong>{money(creditTotal)}</strong></span>
              <span>Status <strong>{(document?.status ?? 'draft').toUpperCase()}</strong></span>
            </div>
          </div>
        </div>
      ) : null}

      {tab === 'search' ? (
        <div className="voucher-search">
          <div className="voucher-filters">
            <input className="desktop-input" placeholder="Voucher # / Book# / narration / account" value={q} onChange={(event) => setQ(event.target.value)} />
            <UiSelect
              aria-label="Voucher type filter"
              value={typeFilter}
              options={[
                { value: '', label: 'All voucher types' },
                { value: 'opening', label: 'Opening Balance Voucher' },
                { value: 'payment', label: 'Payment Voucher' },
                { value: 'receiving', label: 'Receiving Voucher' },
                { value: 'journal', label: 'Journal Voucher' },
              ]}
              onChange={setTypeFilter}
            />
            <UiSelect
              aria-label="Voucher status filter"
              value={statusFilter}
              options={[
                { value: '', label: 'All statuses' },
                { value: 'draft', label: 'Draft' },
                { value: 'posted', label: 'Posted' },
              ]}
              onChange={setStatusFilter}
            />
            <input className="desktop-input" type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} />
            <input className="desktop-input" type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} />
          </div>

          <PosDataGrid
            columns={[
              { key: 'voucher_number', header: 'Voucher #', width: 120, render: (row) => row.voucher_number },
              { key: 'book_number', header: 'Book#', width: 100, render: (row) => row.book_number ?? '—' },
              { key: 'entry_date', header: 'Date', width: 105, render: (row) => row.entry_date },
              { key: 'type', header: 'Type', width: 100, render: (row) => row.type.toUpperCase() },
              { key: 'description', header: 'Narration', render: (row) => row.lines.find((line) => line.narration)?.narration ?? '—' },
              { key: 'debit', header: 'Debit', width: 120, align: 'right', render: (row) => row.total_debit },
              { key: 'credit', header: 'Credit', width: 120, align: 'right', render: (row) => row.total_credit },
              { key: 'status', header: 'Status', width: 90, render: (row) => row.status.toUpperCase() },
            ]}
            rows={listQuery.data?.data ?? []}
            rowKey={(row) => row.ulid}
            selectedKey={selectedVoucherUlid}
            onSelect={(row) => setSelectedVoucherUlid(row.ulid)}
            onActivate={(row) => void openMutation.mutateAsync(row.ulid).catch(handleError)}
            emptyMessage="No vouchers found."
          />
        </div>
      ) : null}

      {tab === 'summary' ? (
        <div className="voucher-summary">
          <div className="voucher-filters">
            <input className="desktop-input" type="date" value={summaryFrom} onChange={(event) => setSummaryFrom(event.target.value)} />
            <input className="desktop-input" type="date" value={summaryTo} onChange={(event) => setSummaryTo(event.target.value)} />
            <UiSelect
              aria-label="Summary voucher type"
              value={summaryType}
              options={[
                { value: '', label: 'All voucher types' },
                { value: 'opening', label: 'Opening Balance Voucher' },
                { value: 'payment', label: 'Payment Voucher' },
                { value: 'receiving', label: 'Receiving Voucher' },
                { value: 'journal', label: 'Journal Voucher' },
              ]}
              onChange={setSummaryType}
            />
          </div>
          <PosDataGrid
            columns={[
              { key: 'date', header: 'Date', render: (row) => row.date },
              { key: 'debit', header: 'Debit', align: 'right', render: (row) => row.debit },
              { key: 'credit', header: 'Credit', align: 'right', render: (row) => row.credit },
            ]}
            rows={summaryQuery.data?.data ?? []}
            rowKey={(row) => row.date}
            emptyMessage="No posted voucher totals for this period."
          />
        </div>
      ) : null}

      <VoucherPrintModal
        open={printOpen}
        onClose={() => setPrintOpen(false)}
        data={printData}
      />
    </DesktopPanel>
  )
}
