import type { CustomQueryRunResult } from '../api/customQuery'

export function previewCustomQueryResult(result: CustomQueryRunResult, queryText: string): boolean {
  return openCustomQueryResult(result, queryText, false)
}

export function printCustomQueryResult(result: CustomQueryRunResult, queryText: string): boolean {
  return openCustomQueryResult(result, queryText, true)
}

function openCustomQueryResult(
  result: CustomQueryRunResult,
  queryText: string,
  autoPrint: boolean,
): boolean {
  const previewWindow = window.open('', '_blank', 'width=1100,height=760')

  if (!previewWindow) return false

  previewWindow.document.open()
  previewWindow.document.write(
    resultHtml(result, queryText).replace('__AUTOPRINT__', autoPrint ? 'window.print()' : ''),
  )
  previewWindow.document.close()

  return true
}

function resultHtml(result: CustomQueryRunResult, queryText: string): string {
  const headers = result.columns
    .map((column) => `<th>${escapeHtml(column)}</th>`)
    .join('')

  const rows = result.rows
    .map(
      (row) =>
        `<tr>${result.columns
          .map((column) => `<td>${escapeHtml(formatValue(row[column]))}</td>`)
          .join('')}</tr>`,
    )
    .join('')

  const generatedAt = new Date().toLocaleString()

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<title>Custom Query - ${escapeHtml(result.dataset)}</title>
<style>
  @page { size: landscape; margin: 10mm; }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    padding: 18px;
    color: #111;
    background: #fff;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 12px;
  }
  h1 { margin: 0 0 6px; font-size: 20px; }
  .meta { margin-bottom: 12px; color: #444; }
  .meta span { margin-right: 18px; }
  .query {
    margin: 0 0 14px;
    padding: 8px 10px;
    border: 1px solid #bbb;
    background: #f6f6f6;
    white-space: pre-wrap;
    word-break: break-word;
    font-family: Consolas, "Courier New", monospace;
  }
  .table-wrap { overflow: auto; }
  table { width: 100%; border-collapse: collapse; }
  th, td {
    padding: 5px 7px;
    border: 1px solid #aaa;
    text-align: left;
    vertical-align: top;
    white-space: pre-wrap;
    word-break: break-word;
  }
  th { background: #ececec; font-weight: 700; }
  tbody tr:nth-child(even) { background: #fafafa; }
  .empty { padding: 20px; text-align: center; color: #555; }
  @media print {
    body { padding: 0; }
    .table-wrap { overflow: visible; }
    thead { display: table-header-group; }
    tr { break-inside: avoid; }
  }
</style>
</head>
<body>
  <h1>Custom Query Result</h1>
  <div class="meta">
    <span><strong>Dataset:</strong> ${escapeHtml(result.dataset)}</span>
    <span><strong>Rows:</strong> ${result.row_count}</span>
    <span><strong>Generated:</strong> ${escapeHtml(generatedAt)}</span>
    ${result.truncated ? `<span><strong>Limited to:</strong> ${result.limit}</span>` : ''}
  </div>
  <pre class="query">${escapeHtml(queryText)}</pre>
  <div class="table-wrap">
    <table>
      <thead><tr>${headers}</tr></thead>
      <tbody>${rows || `<tr><td class="empty" colspan="${Math.max(result.columns.length, 1)}">No rows returned.</td></tr>`}</tbody>
    </table>
  </div>
  <script>
    window.addEventListener('load', function () {
      window.setTimeout(function () {
        window.focus();
        __AUTOPRINT__;
      }, 150);
    });
  </script>
</body>
</html>`
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}
