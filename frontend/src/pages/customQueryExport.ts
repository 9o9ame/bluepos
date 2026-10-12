import type { CustomQueryRunResult } from '../api/customQuery'

const CSV_DANGEROUS_PREFIX = /^[=+\-@\t\r]/

export function exportCustomQueryCsv(result: CustomQueryRunResult): void {
  const rows = [
    result.columns.map(csvCell).join(','),
    ...result.rows.map((row) => result.columns.map((column) => csvCell(row[column])).join(',')),
  ]

  downloadTextFile(
    `${safeFilename(result.dataset)}-${timestampForFilename()}.csv`,
    '\uFEFF' + rows.join('\r\n'),
    'text/csv;charset=utf-8',
  )
}

export function exportCustomQueryExcel(result: CustomQueryRunResult): void {
  const worksheetName = xmlEscape(result.dataset.slice(0, 31) || 'Custom Query')
  const headerCells = result.columns
    .map((column) => spreadsheetCell(column))
    .join('')
  const dataRows = result.rows
    .map((row) => `<Row>${result.columns.map((column) => spreadsheetCell(row[column])).join('')}</Row>`)
    .join('')

  const workbook = `<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Worksheet ss:Name="${worksheetName}">
  <Table>
   <Row>${headerCells}</Row>
   ${dataRows}
  </Table>
 </Worksheet>
</Workbook>`

  downloadTextFile(
    `${safeFilename(result.dataset)}-${timestampForFilename()}.xls`,
    workbook,
    'application/vnd.ms-excel;charset=utf-8',
  )
}

function spreadsheetCell(value: unknown): string {
  return `<Cell><Data ss:Type="String">${xmlEscape(formatExportValue(value))}</Data></Cell>`
}

function csvCell(value: unknown): string {
  let text = formatExportValue(value)

  if (CSV_DANGEROUS_PREFIX.test(text)) {
    text = `'${text}`
  }

  return `"${text.replace(/"/g, '""')}"`
}

function formatExportValue(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function xmlEscape(value: string): string {
  return value
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}

function safeFilename(value: string): string {
  const safe = value.trim().replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '')
  return safe || 'custom-query'
}

function timestampForFilename(): string {
  return new Date().toISOString().replace(/[:.]/g, '-')
}

function downloadTextFile(filename: string, content: string, mimeType: string): void {
  const blob = new Blob([content], { type: mimeType })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')

  anchor.href = url
  anchor.download = filename
  anchor.style.display = 'none'
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}
