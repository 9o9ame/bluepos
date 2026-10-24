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
  const query = new URLSearchParams({
    as_of_date: params.as_of_date,
    group_by: params.group_by,
    arrange_by: params.arrange_by,
  })

  return apiFetch<TrialBalanceReport>(
    `/api/reports/accounting/trial-balance?${query.toString()}`,
    { busy: 'fetch' },
  )
}

export function fetchTrialBalanceSixColumns(params: {
  start_date: string
  end_date: string
  group_by: TrialBalanceGroupBy
  arrange_by: TrialBalanceArrangeBy
}): Promise<TrialBalanceSixColumnReport> {
  const query = new URLSearchParams({ mode: 'six_columns', ...params })
  return apiFetch<TrialBalanceSixColumnReport>(
    `/api/reports/accounting/trial-balance?${query.toString()}`,
    { busy: 'fetch' },
  )
}
