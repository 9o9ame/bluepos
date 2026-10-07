import { useDeferredValue, useMemo, useState } from 'react'
import { useInfiniteQuery, useMutation, useQuery } from '@tanstack/react-query'
import { Plus, RotateCcw, Search } from 'lucide-react'
import { createExpense, fetchExpenseAccounts, fetchExpenses } from '../../api/sales'
import { UiSelect } from '../../components/ui/UiSelect'
import { useCan } from '../auth/useCan'
import { useAuth } from '../auth/AuthProvider'
import { useFeedback } from '../../feedback/FeedbackProvider'

function newExpenseKey(): string {
  return `expense-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function money(value: string | null | undefined): string {
  return (Number.parseFloat(value ?? '0') || 0).toFixed(2)
}

export function SalesExpenses() {
  const canView = useCan('expenses.view')
  const canCreate = useCan('expenses.create')
  const { session } = useAuth()
  const feedback = useFeedback()

  const [searchText, setSearchText] = useState('')
  const search = useDeferredValue(searchText.trim())
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const [expenseDate, setExpenseDate] = useState(
    () => new Date().toISOString().slice(0, 10),
  )
  const [expenseAccountUlid, setExpenseAccountUlid] = useState('')
  const [paymentAccountUlid, setPaymentAccountUlid] = useState('')
  const [amount, setAmount] = useState('')
  const [reference, setReference] = useState('')
  const [description, setDescription] = useState('')
  const [idempotencyKey, setIdempotencyKey] = useState(newExpenseKey)

  const accountsQuery = useQuery({
    queryKey: ['sales', 'expenses', 'accounts', session?.branch.ulid],
    queryFn: fetchExpenseAccounts,
    enabled: Boolean(session) && canCreate,
    retry: false,
  })

  const expensesQuery = useInfiniteQuery({
    queryKey: [
      'sales',
      'expenses',
      session?.branch.ulid,
      search,
      dateFrom,
      dateTo,
    ],
    initialPageParam: 1,
    queryFn: ({ pageParam }) =>
      fetchExpenses({
        page: pageParam,
        per_page: 40,
        q: search || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
      }),
    getNextPageParam: (lastPage) =>
      lastPage.meta.current_page < lastPage.meta.last_page
        ? lastPage.meta.current_page + 1
        : undefined,
    enabled: Boolean(session) && canView,
    retry: false,
  })

  const rows = expensesQuery.data?.pages.flatMap((page) => page.data) ?? []
  const meta = expensesQuery.data?.pages[0]?.meta

  const loadedTotal = useMemo(
    () =>
      rows.reduce(
        (sum, row) => sum + (Number.parseFloat(row.amount) || 0),
        0,
      ),
    [rows],
  )

  const accountOptions = useMemo(
    () => [
      { value: '', label: 'Select account' },
      ...(accountsQuery.data ?? []).map((account) => ({
        value: account.ulid,
        label: `${account.code} — ${account.name}`,
      })),
    ],
    [accountsQuery.data],
  )

  const createMutation = useMutation({
    mutationFn: () =>
      createExpense(
        {
          expense_date: expenseDate,
          expense_account_ulid: expenseAccountUlid,
          payment_account_ulid: paymentAccountUlid,
          amount,
          reference: reference.trim() || null,
          description: description.trim() || null,
        },
        idempotencyKey,
      ),
    onSuccess: async () => {
      feedback.success('Expense posted successfully.', 'Expenses')
      setAmount('')
      setReference('')
      setDescription('')
      setExpenseAccountUlid('')
      setPaymentAccountUlid('')
      setIdempotencyKey(newExpenseKey())
      await expensesQuery.refetch()
    },
    onError: (error) => {
      feedback.error(
        error instanceof Error ? error.message : 'Unable to post expense.',
        'Expenses',
      )
    },
  })

  function submitExpense() {
    if (!expenseAccountUlid || !paymentAccountUlid) {
      feedback.error('Select both the expense and payment accounts.', 'Expenses')
      return
    }

    if (expenseAccountUlid === paymentAccountUlid) {
      feedback.error('Expense and payment accounts must be different.', 'Expenses')
      return
    }

    if ((Number.parseFloat(amount) || 0) <= 0) {
      feedback.error('Expense amount must be greater than zero.', 'Expenses')
      return
    }

    createMutation.mutate()
  }

  function resetFilters() {
    setSearchText('')
    setDateFrom('')
    setDateTo('')
  }

  function loadMoreOnScroll(element: HTMLDivElement) {
    if (!expensesQuery.hasNextPage || expensesQuery.isFetchingNextPage) return

    const remaining =
      element.scrollHeight - element.scrollTop - element.clientHeight

    if (remaining < 140) {
      void expensesQuery.fetchNextPage()
    }
  }

  if (!canView && !canCreate) {
    return (
      <section className="sales-pending-view sales-expenses-view" aria-label="Expenses">
        <div className="sales-pending-empty">
          You do not have permission to view or create expenses.
        </div>
      </section>
    )
  }

  return (
    <section className="sales-pending-view" aria-label="Expenses">
      {canCreate || canView ? (
        <section className="sales-hold-section sales-expense-control-card">
          <header className="sales-pending-section-head sales-expense-combined-head">
            <div>
              <span className="sales-pending-kicker">POSTED ACCOUNTING</span>
              <h3>New Expense</h3>
            </div>

            {canView ? (
              <div className="sales-expense-history-head">
                <div>
                  <span className="sales-pending-kicker">POSTED EXPENSES</span>
                  <h3>Expense History</h3>
                </div>
                <strong>{meta?.total ?? 0}</strong>
              </div>
            ) : null}
          </header>

          {canCreate ? (
            <div className="sales-due-toolbar sales-expense-entry-toolbar">
            <label>
              <span>Date</span>
              <input
                type="date"
                value={expenseDate}
                onChange={(event) => setExpenseDate(event.target.value)}
              />
            </label>

            <label>
              <span>Expense Account</span>
              <UiSelect
                value={expenseAccountUlid}
                className="sales-expense-account-select"
                options={accountOptions}
                aria-label="Expense account"
                onChange={setExpenseAccountUlid}
              />
            </label>

            <label>
              <span>Payment Account</span>
              <UiSelect
                value={paymentAccountUlid}
                className="sales-expense-account-select"
                options={accountOptions}
                aria-label="Payment account"
                onChange={setPaymentAccountUlid}
              />
            </label>

            <label>
              <span>Amount</span>
              <input
                value={amount}
                inputMode="decimal"
                placeholder="0.00"
                onChange={(event) => setAmount(event.target.value)}
              />
            </label>

            <label>
              <span>Reference</span>
              <input
                value={reference}
                maxLength={100}
                placeholder="Optional"
                onChange={(event) => setReference(event.target.value)}
              />
            </label>

            <label>
              <span>Description</span>
              <input
                value={description}
                maxLength={500}
                placeholder="Optional"
                onChange={(event) => setDescription(event.target.value)}
              />
            </label>

            <button
              type="button"
              className="sales-due-reset"
              disabled={createMutation.isPending || accountsQuery.isFetching}
              onClick={submitExpense}
            >
              <Plus size={13} />
              {createMutation.isPending ? 'Posting…' : 'Post Expense'}
            </button>
            </div>
          ) : null}

          {canView ? (
            <div className="sales-due-toolbar sales-expense-history-toolbar">
              <div className="sales-due-search">
                <Search size={14} aria-hidden="true" />
                <input
                  value={searchText}
                  onChange={(event) => setSearchText(event.target.value)}
                  placeholder="Reference, description, account…"
                  aria-label="Search expenses"
                />
              </div>

              <label>
                <span>From</span>
                <input
                  type="date"
                  value={dateFrom}
                  onChange={(event) => setDateFrom(event.target.value)}
                />
              </label>

              <label>
                <span>To</span>
                <input
                  type="date"
                  value={dateTo}
                  onChange={(event) => setDateTo(event.target.value)}
                />
              </label>

              <button type="button" className="sales-due-reset" onClick={resetFilters}>
                <RotateCcw size={13} />
                Reset
              </button>

              <div className="sales-due-toolbar-summary">
                <div className="is-due">
                  <span>LOADED EXPENSES</span>
                  <strong>{loadedTotal.toFixed(2)}</strong>
                </div>
              </div>
            </div>
          ) : null}
        </section>
      ) : null}

      {canView ? (
        <section className="sales-due-section sales-expense-history-section">
          <div
            className="sales-pending-grid-wrap sales-due-grid-wrap"
            onScroll={(event) => loadMoreOnScroll(event.currentTarget)}
          >
            <table className="sales-pending-grid">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Expense Account</th>
                  <th>Payment Account</th>
                  <th>Reference</th>
                  <th>Description</th>
                  <th className="num">Amount</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((expense) => (
                  <tr key={expense.ulid}>
                    <td>{expense.expense_date}</td>
                    <td>
                      {expense.expense_account.code} — {expense.expense_account.name}
                    </td>
                    <td>
                      {expense.payment_account.code} — {expense.payment_account.name}
                    </td>
                    <td>{expense.reference || '—'}</td>
                    <td>{expense.description || '—'}</td>
                    <td className="num is-due-value">{money(expense.amount)}</td>
                  </tr>
                ))}

                {rows.length === 0 && !expensesQuery.isPending ? (
                  <tr>
                    <td colSpan={6} className="sales-pending-empty">
                      No posted expenses match these filters.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>

          <div className="sales-infinite-status" role="status">
            {expensesQuery.isFetchingNextPage
              ? 'Loading more expenses…'
              : expensesQuery.hasNextPage
                ? `Scroll for more · ${rows.length} of ${meta?.total ?? rows.length}`
                : rows.length > 0
                  ? `All ${rows.length} expense(s) loaded`
                  : ''}
          </div>
        </section>
      ) : null}
    </section>
  )
}
