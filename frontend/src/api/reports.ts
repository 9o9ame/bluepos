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
  main_heads?: string[]
  sub_heads?: string[]
  account_types?: string[]
}): Promise<TrialBalanceSixColumnReport> {
  const query = new URLSearchParams({
    mode: 'six_columns',
    start_date: params.start_date,
    end_date: params.end_date,
    group_by: params.group_by,
    arrange_by: params.arrange_by,
  })
  params.main_heads?.forEach((ulid, index) => query.set(`main_heads[${index}]`, ulid))
  params.sub_heads?.forEach((ulid, index) => query.set(`sub_heads[${index}]`, ulid))
  params.account_types?.forEach((ulid, index) => query.set(`account_types[${index}]`, ulid))
  return apiFetch<TrialBalanceSixColumnReport>(
    `/api/reports/accounting/trial-balance?${query.toString()}`,
    { busy: 'fetch' },
  )
}
