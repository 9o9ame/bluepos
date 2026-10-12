export type ReportHeadingLayout = {
  margin: 'normal' | 'narrow' | 'wide'
  orientation: 'portrait' | 'landscape'
  page_size: 'a4' | 'letter' | 'legal'
  columns: 1 | 2 | 3
  line_numbers: boolean
  page_color: string
}

export type ReportHeading = {
  ulid: string
  head_id: string
  used_for: string
  content_html: string | null
  layout_settings: ReportHeadingLayout
}
