import { apiFetch } from './client'
import type {
  TrialBalanceArrangeBy,
  TrialBalanceGroupBy,
  TrialBalanceReport,
  TrialBalanceSixColumnReport,
} from '../types/reports'

export function fetchTrialBalanceReport(params: {
  as_of_date: string
  group_by: TrialBalanceGroupBy
  arrange_by: TrialBalanceArrangeBy
}): Promise<TrialBalanceReport> {
  return apiFetch<TrialBalanceReport>('/api/reports/accounting/trial-balance/preview', {
    method: 'POST', busy: 'fetch', body: JSON.stringify(params),
  })
}

export function fetchTrialBalanceSixColumns(params: {
  start_date: string
  end_date: string
  group_by: TrialBalanceGroupBy
  arrange_by: TrialBalanceArrangeBy
  main_heads?: string[]
  sub_heads?: string[]
  account_types?: string[]
  document_types?: string[]
  party_categories?: string[]
  closing_operator?: string
  closing_amount?: string
}): Promise<TrialBalanceSixColumnReport> {
  return apiFetch<TrialBalanceSixColumnReport>('/api/reports/accounting/trial-balance/preview', {
    method: 'POST', busy: 'fetch', body: JSON.stringify({mode: 'six_columns', ...params}),
  })
}
