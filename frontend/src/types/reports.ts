export type TrialBalanceGroupBy =
  | 'account_type'
  | 'account_type_head'
  | 'main_account'
  | 'level_wise'

export type TrialBalanceArrangeBy =
  | 'balance_asc'
  | 'balance_desc'
  | 'credit_asc'
  | 'credit_desc'
  | 'debit_asc'
  | 'debit_desc'
  | 'code_asc'
  | 'code_desc'
  | 'name_asc'
  | 'name_desc'

export type TrialBalanceHierarchyRef = {
  ulid: string
  name: string
  sort_order?: number
  code?: string | null
}

export type TrialBalanceRow = {
  account: {
    ulid: string
    code: string
    name: string
  }
  main_head: TrialBalanceHierarchyRef | null
  account_type_head: TrialBalanceHierarchyRef | null
  account_type: TrialBalanceHierarchyRef | null
  debit: string
  credit: string
  balance: string
}

export type TrialBalanceTotals = {
  debit: string
  credit: string
  difference: string
  difference_side: 'Dr' | 'Cr'
}

export type TrialBalanceAccountTypeSection = {
  account_type: TrialBalanceHierarchyRef | null
  rows: TrialBalanceRow[]
  totals: TrialBalanceTotals
}

export type TrialBalanceAccountTypeHeadSection = {
  account_type_head: TrialBalanceHierarchyRef | null
  account_types: TrialBalanceAccountTypeSection[]
  totals: TrialBalanceTotals
}

export type TrialBalanceGroup = {
  key: string
  label: string
  rows: TrialBalanceRow[]
  sections: TrialBalanceAccountTypeHeadSection[] | null
  totals: TrialBalanceTotals
}

export type TrialBalanceReport = {
  as_of_date: string
  group_by: TrialBalanceGroupBy
  arrange_by: TrialBalanceArrangeBy
  groups: TrialBalanceGroup[]
  totals: TrialBalanceTotals
}

export type TrialBalanceSixColumns = {
  previous_debit: string
  previous_credit: string
  within_debit: string
  within_credit: string
  closing_debit: string
  closing_credit: string
}

export type TrialBalanceSixColumnReport = {
  start_date: string
  end_date: string
  group_by: TrialBalanceGroupBy
  arrange_by: TrialBalanceArrangeBy
  groups: Array<Omit<TrialBalanceGroup, 'rows'> & {
    rows: Array<TrialBalanceRow & { six_columns: TrialBalanceSixColumns }>
  }>
  totals: TrialBalanceSixColumns
}
