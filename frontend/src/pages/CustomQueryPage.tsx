import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Database, Eye, FileSpreadsheet, FileText, Pencil, Play, Printer, RefreshCw, Save, Search, Trash2, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  deleteCustomQuery,
  fetchCustomQueryCatalog,
  fetchSavedCustomQueries,
  runCustomQuery,
  saveCustomQuery,
  updateCustomQuery,
  type CustomQueryRunResult,
} from '../api/customQuery'
import { ApiClientError } from '../api/client'
import { DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiModal } from '../components/ui/UiModal'
import { UiSelect } from '../components/ui/UiSelect'
import { useFeedback } from '../feedback/FeedbackProvider'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import { exportCustomQueryCsv, exportCustomQueryExcel } from './customQueryExport'
import { previewCustomQueryResult, printCustomQueryResult } from './customQueryPrint'
import './CustomQueryPage.css'

type BottomTab = 'results' | 'messages' | 'useful' | 'helping' | 'exports'

type ResultRow = Record<string, unknown> & {
  _rowKey: string
}

const DEFAULT_QUERY = 'SELECT ulid, product_number, name, status FROM products ORDER BY product_number LIMIT 100'

export function CustomQueryPage() {
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const queryClient = useQueryClient()

  const catalogQuery = useQuery({
    queryKey: ['custom-query', 'catalog'],
    queryFn: fetchCustomQueryCatalog,
  })
  const savedQuery = useQuery({
    queryKey: ['custom-query', 'saved'],
    queryFn: fetchSavedCustomQueries,
  })

  const [queryText, setQueryText] = useState(DEFAULT_QUERY)
  const [selectedSavedUlid, setSelectedSavedUlid] = useState('')
  const [result, setResult] = useState<CustomQueryRunResult | null>(null)
  const [resultQueryText, setResultQueryText] = useState('')
  const [message, setMessage] = useState('Ready. Only tenant-safe read-only SELECT queries are allowed.')
  const [bottomTab, setBottomTab] = useState<BottomTab>('results')
  const [saveOpen, setSaveOpen] = useState(false)
  const [saveTitle, setSaveTitle] = useState('')
  const [manageOpen, setManageOpen] = useState(false)
  const [manageTitle, setManageTitle] = useState('')
  const [datasetSearch, setDatasetSearch] = useState('')
  const lineNumbersRef = useRef<HTMLDivElement | null>(null)

  const runMutation = useMutation({
    mutationFn: (submittedQuery: string) => runCustomQuery(submittedQuery),
    onSuccess: (data, submittedQuery) => {
      setResult(data)
      setResultQueryText(submittedQuery)
      setBottomTab('results')
      setMessage(
        `Query completed: ${data.row_count} row${data.row_count === 1 ? '' : 's'} in ${data.elapsed_ms} ms${data.truncated ? ` (limited to ${data.limit})` : ''}.`,
      )
    },
    onError: (error) => {
      setResult(null)
      setResultQueryText('')
      setBottomTab('messages')
      setMessage(error instanceof ApiClientError || error instanceof Error ? error.message : 'Unable to run query.')
    },
  })

  const saveMutation = useMutation({
    mutationFn: () => saveCustomQuery({ title: saveTitle.trim(), query: queryText }),
    onSuccess: async (saved) => {
      setSaveOpen(false)
      setSaveTitle('')
      setSelectedSavedUlid(saved.ulid)
      feedback.success('Custom query saved.')
      await queryClient.invalidateQueries({ queryKey: ['custom-query', 'saved'] })
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to save custom query.'),
  })

  const updateMutation = useMutation({
    mutationFn: () =>
      updateCustomQuery(selectedSavedUlid, {
        title: manageTitle.trim(),
        query: queryText,
      }),
    onSuccess: async (saved) => {
      setManageOpen(false)
      setManageTitle('')
      setSelectedSavedUlid(saved.ulid)
      feedback.success('Saved query updated.')
      await queryClient.invalidateQueries({ queryKey: ['custom-query', 'saved'] })
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to update saved query.'),
  })

  const deleteMutation = useMutation({
    mutationFn: (ulid: string) => deleteCustomQuery(ulid),
    onSuccess: async () => {
      setSelectedSavedUlid('')
      setManageOpen(false)
      setManageTitle('')
      feedback.success('Saved query deleted.')
      await queryClient.invalidateQueries({ queryKey: ['custom-query', 'saved'] })
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to delete saved query.'),
  })

  const runNow = useCallback(() => {
    const submittedQuery = queryText.trim()
    if (!submittedQuery || runMutation.isPending) return
    runMutation.mutate(submittedQuery)
  }, [queryText, runMutation])

  const previewNow = useCallback(() => {
    if (!result) return
    if (!previewCustomQueryResult(result, resultQueryText)) {
      feedback.error('Preview window was blocked by the browser.')
    }
  }, [feedback, result, resultQueryText])

  const printNow = useCallback(() => {
    if (!result) return
    if (!printCustomQueryResult(result, resultQueryText)) {
      feedback.error('Print window was blocked by the browser.')
    }
  }, [feedback, result, resultQueryText])

  useWorkspaceHandlers({
    save: () => setSaveOpen(true),
    refresh: () => {
      void Promise.all([catalogQuery.refetch(), savedQuery.refetch()])
    },
  })

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'F5') {
        event.preventDefault()
        runNow()
      }

      if (event.key === 'F6') {
        event.preventDefault()
        previewNow()
      }
    }

    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [previewNow, runNow])

  const resultRows = useMemo<ResultRow[]>(
    () =>
      (result?.rows ?? []).map((row, index) => ({
        ...row,
        _rowKey: `${index}-${String(row.ulid ?? row.document_number ?? row.code ?? '')}`,
      })),
    [result],
  )

  const resultColumns = useMemo<PosGridColumn<ResultRow>[]>(
    () =>
      (result?.columns ?? []).map((column) => ({
        key: column,
        header: column,
        width: 180,
        render: (row) => formatCell(row[column]),
      })),
    [result],
  )

  const selectedSaved = savedQuery.data?.find((item) => item.ulid === selectedSavedUlid) ?? null

  const lineNumbers = useMemo(
    () => Array.from({ length: Math.max(1, queryText.split(/\r\n|\r|\n/).length) }, (_, index) => index + 1),
    [queryText],
  )

  const filteredDatasets = useMemo(() => {
    const needle = datasetSearch.trim().toLowerCase()
    const datasets = catalogQuery.data?.datasets ?? []

    if (!needle) return datasets

    return datasets
      .map((dataset) => {
        const datasetMatches = dataset.name.toLowerCase().includes(needle)
        const matchingColumns = dataset.columns.filter((column) => column.toLowerCase().includes(needle))

        if (!datasetMatches && matchingColumns.length === 0) return null

        return {
          ...dataset,
          columns: datasetMatches ? dataset.columns : matchingColumns,
        }
      })
      .filter((dataset): dataset is NonNullable<typeof dataset> => dataset !== null)
  }, [catalogQuery.data?.datasets, datasetSearch])

  const savedOptions = [
    { value: '', label: '— Saved queries —' },
    ...(savedQuery.data ?? []).map((saved) => ({
      value: saved.ulid,
      label: saved.title,
    })),
  ]

  return (
    <DesktopPanel title="Custom Query" className="custom-query-page" flush>
      <div className="custom-query-layout">
        <section className="custom-query-main">
          <div className="custom-query-editor-toolbar">
            <div className="custom-query-saved-select">
              <UiSelect
                value={selectedSavedUlid}
                options={savedOptions}
                disabled={savedQuery.isLoading}
                aria-label="Saved custom queries"
                onChange={(ulid) => {
                  setSelectedSavedUlid(ulid)
                  const saved = savedQuery.data?.find((item) => item.ulid === ulid)
                  if (saved) {
                    setQueryText(saved.query)
                    setMessage(`Loaded saved query: ${saved.title}`)
                  }
                }}
              />
            </div>
            <div className="custom-query-toolbar-actions">
              <UiButton
                variant="info"
                icon={<RefreshCw size={14} />}
                label="Refresh"
                onClick={() => {
                  void Promise.all([catalogQuery.refetch(), savedQuery.refetch()])
                }}
              />
              <UiButton
                variant="success"
                icon={<Save size={14} />}
                label="Save"
                onClick={() => setSaveOpen(true)}
              />
              <UiButton
                icon={<Pencil size={14} />}
                label="Manage Saved"
                disabled={!selectedSaved || updateMutation.isPending || deleteMutation.isPending}
                onClick={() => {
                  if (!selectedSaved) return
                  setManageTitle(selectedSaved.title)
                  setManageOpen(true)
                }}
              />
              <UiButton
                variant="primary"
                icon={<Play size={14} />}
                label={runMutation.isPending ? 'Running…' : 'Run Query [F5]'}
                disabled={runMutation.isPending || !queryText.trim()}
                onClick={runNow}
              />
              <UiButton
                variant="info"
                icon={<Eye size={14} />}
                label="Preview [F6]"
                disabled={!result}
                onClick={previewNow}
              />
              <UiButton
                variant="info"
                icon={<Printer size={14} />}
                label="Print"
                disabled={!result}
                onClick={printNow}
              />
              <UiButton
                variant="info"
                icon={<X size={14} />}
                label="Close"
                onClick={closeActiveTab}
              />
            </div>
          </div>

          <div className="custom-query-editor-shell">
            <div ref={lineNumbersRef} className="custom-query-line-numbers" aria-hidden>
              {lineNumbers.map((lineNumber) => (
                <span key={lineNumber}>{lineNumber}</span>
              ))}
            </div>
            <textarea
              className="custom-query-editor"
              spellCheck={false}
              wrap="off"
              value={queryText}
              aria-label="Safe custom query editor"
              onScroll={(event) => {
                if (lineNumbersRef.current) {
                  lineNumbersRef.current.scrollTop = event.currentTarget.scrollTop
                }
              }}
              onChange={(event) => setQueryText(event.target.value)}
            />
          </div>

          <div className="custom-query-bottom-tabs" role="tablist" aria-label="Custom query output">
            <BottomTabButton tab="results" current={bottomTab} onChange={setBottomTab}>Result Pane</BottomTabButton>
            <BottomTabButton tab="messages" current={bottomTab} onChange={setBottomTab}>Messages</BottomTabButton>
            <BottomTabButton tab="useful" current={bottomTab} onChange={setBottomTab}>Useful things</BottomTabButton>
            <BottomTabButton tab="helping" current={bottomTab} onChange={setBottomTab}>Helping</BottomTabButton>
            <BottomTabButton tab="exports" current={bottomTab} onChange={setBottomTab}>Exports</BottomTabButton>
          </div>

          <div className="custom-query-output">
            {bottomTab === 'results' ? (
              <PosDataGrid
                columns={resultColumns}
                rows={resultRows}
                rowKey={(row) => row._rowKey}
                emptyMessage={runMutation.isPending ? 'Running query…' : 'No query results.'}
              />
            ) : null}

            {bottomTab === 'messages' ? (
              <div className="custom-query-message-pane">
                <strong>{result ? 'Last query status' : 'Status'}</strong>
                <pre>{message}</pre>
                {result ? (
                  <div className="custom-query-message-meta">
                    <span>Dataset: {result.dataset}</span>
                    <span>Rows: {result.row_count}</span>
                    <span>Limit: {result.limit}</span>
                    <span>{result.truncated ? 'Result truncated' : 'Complete within limit'}</span>
                  </div>
                ) : null}
              </div>
            ) : null}

            {bottomTab === 'useful' ? (
              <InfoPane
                title="Useful things"
                text="Destructive database utilities from the reference application are intentionally unavailable. BluePOS keeps maintenance, backup, indexing, and data repair outside tenant Custom Query."
              />
            ) : null}

            {bottomTab === 'helping' ? (
              <div className="custom-query-info-pane">
                <strong>Safe query syntax</strong>
                <p>{catalogQuery.data?.syntax ?? 'Loading safe query syntax…'}</p>
                <div className="custom-query-help-grid">
                  <div>
                    <span>Example</span>
                    <code>SELECT ulid, code, name FROM party_profiles ORDER BY name LIMIT 50</code>
                  </div>
                  <div>
                    <span>WHERE</span>
                    <code>WHERE is_active = TRUE AND name ILIKE 'A%'</code>
                  </div>
                  <div>
                    <span>Safety</span>
                    <p>Only allowlisted datasets and columns are available. Tenant, branch, and warehouse scope is applied by the server.</p>
                  </div>
                </div>
              </div>
            ) : null}

            {bottomTab === 'exports' ? (
              <div className="custom-query-info-pane">
                <strong>Exports</strong>
                <p>Export the current safe query result only. No additional database access is performed.</p>
                <div className="custom-query-export-actions">
                  <UiButton
                    icon={<FileText size={14} />}
                    label="Export CSV"
                    disabled={!result || result.row_count === 0}
                    onClick={() => {
                      if (!result) return
                      exportCustomQueryCsv(result)
                      feedback.success('Custom query results exported as CSV.')
                    }}
                  />
                  <UiButton
                    icon={<FileSpreadsheet size={14} />}
                    label="Export Excel (.xls)"
                    disabled={!result || result.row_count === 0}
                    onClick={() => {
                      if (!result) return
                      exportCustomQueryExcel(result)
                      feedback.success('Custom query results exported for Excel.')
                    }}
                  />
                </div>
                {!result || result.row_count === 0 ? (
                  <p className="custom-query-export-empty">Run a query with at least one result row to enable exports.</p>
                ) : (
                  <p className="custom-query-export-ready">
                    Ready to export {result.row_count} row{result.row_count === 1 ? '' : 's'} from {result.dataset}.
                  </p>
                )}
              </div>
            ) : null}
          </div>

          <div className="custom-query-status">
            <span>{message}</span>
            <span>{result ? `${result.dataset} · ${result.row_count} rows` : 'Safe read-only mode'}</span>
          </div>
        </section>

        <aside className="custom-query-browser">
          <div className="custom-query-browser-title">
            <Database size={14} aria-hidden />
            <strong>Safe datasets</strong>
            <span>Count: {filteredDatasets.length}</span>
          </div>
          <label className="custom-query-browser-search">
            <Search size={13} aria-hidden />
            <input
              value={datasetSearch}
              aria-label="Filter datasets and columns"
              placeholder="Filter datasets or columns"
              onChange={(event) => setDatasetSearch(event.target.value)}
            />
            {datasetSearch ? (
              <button type="button" aria-label="Clear dataset filter" onClick={() => setDatasetSearch('')}>
                <X size={12} />
              </button>
            ) : null}
          </label>
          <div className="custom-query-browser-body">
            {filteredDatasets.map((dataset) => (
              <details key={dataset.name} className="custom-query-dataset" open>
                <summary>
                  <span>{dataset.name}</span>
                  <small>{dataset.scope === 'branch_warehouse' ? 'branch + warehouse' : 'tenant'}</small>
                </summary>
                <div className="custom-query-columns">
                  {dataset.columns.map((column) => (
                    <button
                      key={column}
                      type="button"
                      title="Insert column name"
                      onClick={() => {
                        setQueryText((current) => current.endsWith(' ') || current === '' ? current + column : current + ' ' + column)
                      }}
                    >
                      {column}
                    </button>
                  ))}
                </div>
              </details>
            ))}
            {catalogQuery.isLoading ? <div className="custom-query-browser-empty">Loading datasets…</div> : null}
            {catalogQuery.isError ? <div className="custom-query-browser-empty">Unable to load datasets.</div> : null}
            {!catalogQuery.isLoading && !catalogQuery.isError && filteredDatasets.length === 0 ? (
              <div className="custom-query-browser-empty">No matching datasets or columns.</div>
            ) : null}
          </div>
        </aside>
      </div>

      <UiModal
        open={saveOpen}
        title="Save Custom Query"
        size="sm"
        onClose={() => setSaveOpen(false)}
        footer={
          <>
            <UiButton label="Cancel" onClick={() => setSaveOpen(false)} />
            <UiButton
              variant="success"
              icon={<Save size={14} />}
              label={saveMutation.isPending ? 'Saving…' : 'Save'}
              disabled={saveMutation.isPending || !saveTitle.trim() || !queryText.trim()}
              onClick={() => saveMutation.mutate()}
            />
          </>
        }
      >
        <label className="custom-query-save-field">
          <span>Query Name</span>
          <input
            autoFocus
            maxLength={160}
            value={saveTitle}
            onChange={(event) => setSaveTitle(event.target.value)}
          />
        </label>
      </UiModal>

      <UiModal
        open={manageOpen}
        title="Update Saved Query"
        size="sm"
        onClose={() => setManageOpen(false)}
        footer={
          <>
            <UiButton
              variant="danger"
              icon={<Trash2 size={14} />}
              label={deleteMutation.isPending ? 'Deleting…' : 'Delete'}
              disabled={!selectedSaved || deleteMutation.isPending || updateMutation.isPending}
              onClick={async () => {
                if (!selectedSaved) return
                const confirmed = await feedback.confirm(`Delete saved query "${selectedSaved.title}"?`)
                if (!confirmed) return
                deleteMutation.mutate(selectedSaved.ulid)
              }}
            />
            <UiButton label="Cancel" onClick={() => setManageOpen(false)} />
            <UiButton
              variant="success"
              icon={<Save size={14} />}
              label={updateMutation.isPending ? 'Updating…' : 'Update'}
              disabled={
                updateMutation.isPending ||
                deleteMutation.isPending ||
                !selectedSavedUlid ||
                !manageTitle.trim() ||
                !queryText.trim()
              }
              onClick={() => updateMutation.mutate()}
            />
          </>
        }
      >
        <label className="custom-query-save-field">
          <span>Query Name</span>
          <input
            autoFocus
            maxLength={160}
            value={manageTitle}
            onChange={(event) => setManageTitle(event.target.value)}
          />
        </label>
      </UiModal>
    </DesktopPanel>
  )
}

function BottomTabButton({
  tab,
  current,
  onChange,
  children,
}: {
  tab: BottomTab
  current: BottomTab
  onChange: (tab: BottomTab) => void
  children: string
}) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={current === tab}
      className={current === tab ? 'is-active' : undefined}
      onClick={() => onChange(tab)}
    >
      {children}
    </button>
  )
}

function InfoPane({ title, text }: { title: string; text: string }) {
  return (
    <div className="custom-query-info-pane">
      <strong>{title}</strong>
      <p>{text}</p>
    </div>
  )
}

function formatCell(value: unknown): string {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}
