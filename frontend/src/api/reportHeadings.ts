import { apiFetch } from './client'
import type { ReportHeading, ReportHeadingLayout } from '../types/reportHeadings'

export function fetchReportHeadings(): Promise<ReportHeading[]> {
  return apiFetch<ReportHeading[]>('/api/report-headings')
}

export function createReportHeading(payload: {
  head_id: string
  used_for: string
  content_html?: string | null
  layout_settings?: ReportHeadingLayout
}): Promise<ReportHeading> {
  return apiFetch<ReportHeading>('/api/report-headings', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateReportHeading(
  ulid: string,
  payload: {
    head_id?: string
    used_for?: string
    content_html?: string | null
    layout_settings?: ReportHeadingLayout
  },
): Promise<ReportHeading> {
  return apiFetch<ReportHeading>(`/api/report-headings/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deleteReportHeading(ulid: string): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>(`/api/report-headings/${ulid}`, {
    method: 'DELETE',
  })
}

export function copyReportHeadingToOthers(ulid: string): Promise<{ ok: boolean; updated: number }> {
  return apiFetch<{ ok: boolean; updated: number }>(`/api/report-headings/${ulid}/copy-to-others`, {
    method: 'POST',
  })
}
