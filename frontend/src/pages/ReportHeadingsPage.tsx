import { type ClipboardEvent, FormEvent, useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Bold,
  Copy,
  Italic,
  List,
  ListOrdered,
  Plus,
  RefreshCw,
  Save,
  Trash2,
  Underline,
  X,
} from 'lucide-react'
import {
  copyReportHeadingToOthers,
  createReportHeading,
  deleteReportHeading,
  fetchReportHeadings,
  updateReportHeading,
} from '../api/reportHeadings'
import { DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiModal } from '../components/ui/UiModal'
import { UiSelect } from '../components/ui/UiSelect'
import { useFeedback } from '../feedback/FeedbackProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import type { ReportHeading } from '../types/reportHeadings'
import './ReportHeadingsPage.css'

export function ReportHeadingsPage() {
  const { closeActiveTab } = useWorkspace()
  const canManage = useCan('settings.manage')
  const feedback = useFeedback()
  const queryClient = useQueryClient()
  const editorRef = useRef<HTMLDivElement | null>(null)

  const query = useQuery({
    queryKey: ['report-headings'],
    queryFn: fetchReportHeadings,
  })

  const [selectedUlid, setSelectedUlid] = useState<string | null>(null)
  const [usedFor, setUsedFor] = useState('')
  const [contentHtml, setContentHtml] = useState('')
  const [newOpen, setNewOpen] = useState(false)
  const [newHeadId, setNewHeadId] = useState('')
  const [newUsedFor, setNewUsedFor] = useState('')

  const selected = useMemo(
    () => query.data?.find((row) => row.ulid === selectedUlid) ?? null,
    [query.data, selectedUlid],
  )

  useEffect(() => {
    if (!query.data?.length) {
      setSelectedUlid(null)
      return
    }

    if (!selectedUlid || !query.data.some((row) => row.ulid === selectedUlid)) {
      setSelectedUlid(query.data[0].ulid)
    }
  }, [query.data, selectedUlid])

  useEffect(() => {
    if (!selected) {
      setUsedFor('')
      setContentHtml('')
      if (editorRef.current) editorRef.current.innerHTML = ''
      return
    }

    const nextHtml = selected.content_html ?? ''
    setUsedFor(selected.used_for)
    setContentHtml(nextHtml)
    if (editorRef.current && editorRef.current.innerHTML !== nextHtml) {
      editorRef.current.innerHTML = nextHtml
    }
  }, [selected])

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (!selected) throw new Error('No report heading selected.')
      return updateReportHeading(selected.ulid, {
        used_for: usedFor.trim(),
        content_html: contentHtml || null,
      })
    },
    onSuccess: async (row) => {
      feedback.success('Report heading saved.')
      setSelectedUlid(row.ulid)
      await queryClient.invalidateQueries({ queryKey: ['report-headings'] })
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to save report heading.'),
  })

  const createMutation = useMutation({
    mutationFn: () =>
      createReportHeading({
        head_id: newHeadId.trim(),
        used_for: newUsedFor.trim(),
        content_html: null,
      }),
    onSuccess: async (row) => {
      setNewOpen(false)
      setNewHeadId('')
      setNewUsedFor('')
      setSelectedUlid(row.ulid)
      feedback.success('Report heading created.')
      await queryClient.invalidateQueries({ queryKey: ['report-headings'] })
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to create report heading.'),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteReportHeading,
    onSuccess: async () => {
      setSelectedUlid(null)
      feedback.success('Report heading deleted.')
      await queryClient.invalidateQueries({ queryKey: ['report-headings'] })
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to delete report heading.'),
  })

  const copyMutation = useMutation({
    mutationFn: copyReportHeadingToOthers,
    onSuccess: async (result) => {
      feedback.success(`Heading copied to ${result.updated} other record(s).`)
      await queryClient.invalidateQueries({ queryKey: ['report-headings'] })
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to copy report heading.'),
  })

  async function save(closeAfter = false) {
    if (!selected || !usedFor.trim() || !canManage || saveMutation.isPending) return

    try {
      await saveMutation.mutateAsync()
      if (closeAfter) closeActiveTab()
    } catch {
      // Feedback is handled by the mutation.
    }
  }

  async function removeSelected() {
    if (!selected || !canManage || deleteMutation.isPending) return
    if (!(await feedback.confirm(`Delete report heading "${selected.head_id}"?`))) return
    deleteMutation.mutate(selected.ulid)
  }

  async function copyToOthers() {
    if (!selected || !canManage || copyMutation.isPending) return
    if (!(await feedback.confirm(`Copy "${selected.head_id}" content to all other report headings?`))) return
    copyMutation.mutate(selected.ulid)
  }

  function applyFormat(command: 'bold' | 'italic' | 'underline' | 'insertUnorderedList' | 'insertOrderedList') {
    if (!canManage) return
    editorRef.current?.focus()
    document.execCommand(command)
    setContentHtml(editorRef.current?.innerHTML ?? '')
  }

  function onPaste(event: ClipboardEvent<HTMLDivElement>) {
    if (!canManage) return
    event.preventDefault()
    const text = event.clipboardData.getData('text/plain')
    document.execCommand('insertText', false, text)
    setContentHtml(editorRef.current?.innerHTML ?? '')
  }

  function submitNew(event: FormEvent) {
    event.preventDefault()
    if (!newHeadId.trim() || !newUsedFor.trim() || createMutation.isPending) return
    createMutation.mutate()
  }

  return (
    <>
      <DesktopPanel
        title="Report Headings Options"
        flush
        toolbar={
          <>
            <UiButton
              variant="primary"
              icon={<Plus size={14} />}
              label="New"
              disabled={!canManage}
              onClick={() => setNewOpen(true)}
            />
            <UiButton
              variant="success"
              icon={<Save size={14} />}
              label={saveMutation.isPending ? 'Saving…' : 'Save'}
              disabled={!canManage || !selected || !usedFor.trim() || saveMutation.isPending}
              onClick={() => void save()}
            />
            <UiButton
              variant="info"
              icon={<RefreshCw size={14} />}
              label={query.isFetching ? 'Refreshing…' : 'Refresh'}
              disabled={query.isFetching}
              onClick={() => void query.refetch()}
            />
            <UiButton variant="info" icon={<X size={14} />} label="Close" onClick={closeActiveTab} />
          </>
        }
      >
        <div className="report-headings-reference-layout">
          <div className="report-headings-selector-row">
            <label>
              <span>Head ID</span>
              <UiSelect
                value={selectedUlid ?? ''}
                searchable
                options={(query.data ?? []).map((row) => ({
                  value: row.ulid,
                  label: row.head_id,
                }))}
                onChange={(value) => setSelectedUlid(value || null)}
              />
            </label>

            <label className="report-headings-used-for">
              <span>Used For</span>
              <input
                className="desktop-input"
                value={usedFor}
                maxLength={120}
                disabled={!canManage || !selected}
                onChange={(event) => setUsedFor(event.target.value)}
              />
            </label>
          </div>

          <div className="report-headings-editor-toolbar" aria-label="Formatting toolbar">
            <UiButton
              icon={<Bold size={14} />}
              label="Bold"
              disabled={!canManage || !selected}
              onClick={() => applyFormat('bold')}
            />
            <UiButton
              icon={<Italic size={14} />}
              label="Italic"
              disabled={!canManage || !selected}
              onClick={() => applyFormat('italic')}
            />
            <UiButton
              icon={<Underline size={14} />}
              label="Underline"
              disabled={!canManage || !selected}
              onClick={() => applyFormat('underline')}
            />
            <UiButton
              icon={<List size={14} />}
              label="Bullets"
              disabled={!canManage || !selected}
              onClick={() => applyFormat('insertUnorderedList')}
            />
            <UiButton
              icon={<ListOrdered size={14} />}
              label="Numbering"
              disabled={!canManage || !selected}
              onClick={() => applyFormat('insertOrderedList')}
            />
          </div>

          <div className="report-headings-workarea">
            <div className="report-headings-document-shell">
              <div
                ref={editorRef}
                className="report-headings-document"
                contentEditable={Boolean(canManage && selected)}
                suppressContentEditableWarning
                role="textbox"
                aria-multiline="true"
                data-readonly={!canManage || !selected ? 'true' : 'false'}
                onInput={(event) => setContentHtml(event.currentTarget.innerHTML)}
                onPaste={onPaste}
              />
            </div>

            <div className="report-headings-record-list">
              <PosDataGrid
                columns={[
                  { key: 'head_id', header: 'Head ID', width: 120, render: (row: ReportHeading) => row.head_id },
                  { key: 'used_for', header: 'Used For', render: (row: ReportHeading) => row.used_for },
                ]}
                rows={query.data ?? []}
                rowKey={(row) => row.ulid}
                selectedKey={selectedUlid}
                onSelect={(row) => setSelectedUlid(row.ulid)}
                onActivate={(row) => setSelectedUlid(row.ulid)}
                emptyMessage={query.isLoading ? 'Loading headings…' : 'No report headings.'}
              />
              <div className="report-headings-record-count">
                Record {selected ? (query.data?.findIndex((row) => row.ulid === selected.ulid) ?? -1) + 1 : 0} of {query.data?.length ?? 0}
              </div>
            </div>
          </div>

          <div className="report-headings-bottom-actions">
            <div>
              <UiButton
                variant="success"
                icon={<Save size={14} />}
                label="Apply"
                disabled={!canManage || !selected || !usedFor.trim() || saveMutation.isPending}
                onClick={() => void save()}
              />
            </div>

            <div className="report-headings-bottom-center">
              <UiButton
                variant="danger"
                icon={<Trash2 size={14} />}
                label="Delete"
                disabled={!canManage || !selected || deleteMutation.isPending}
                onClick={() => void removeSelected()}
              />
            </div>

            <div className="report-headings-bottom-right">
              <UiButton
                icon={<Copy size={14} />}
                label={copyMutation.isPending ? 'Copying…' : 'Copy Head to Others'}
                disabled={!canManage || !selected || copyMutation.isPending || (query.data?.length ?? 0) < 2}
                onClick={() => void copyToOthers()}
              />
              <UiButton
                variant="success"
                icon={<Save size={14} />}
                label="OK"
                disabled={!canManage || !selected || !usedFor.trim() || saveMutation.isPending}
                onClick={() => void save(true)}
              />
              <UiButton variant="info" icon={<X size={14} />} label="Close" onClick={closeActiveTab} />
            </div>
          </div>
        </div>
      </DesktopPanel>

      <UiModal
        open={newOpen}
        title="New Report Heading"
        size="sm"
        onClose={() => {
          if (!createMutation.isPending) setNewOpen(false)
        }}
        footer={
          <>
            <UiButton
              variant="info"
              icon={<X size={14} />}
              label="Cancel"
              disabled={createMutation.isPending}
              onClick={() => setNewOpen(false)}
            />
            <UiButton
              variant="success"
              icon={<Save size={14} />}
              label={createMutation.isPending ? 'Creating…' : 'Create'}
              disabled={!newHeadId.trim() || !newUsedFor.trim() || createMutation.isPending}
              onClick={() => {
                const form = document.getElementById('new-report-heading-form') as HTMLFormElement | null
                form?.requestSubmit()
              }}
            />
          </>
        }
      >
        <form id="new-report-heading-form" className="report-headings-new-form" onSubmit={submitNew}>
          <label>
            <span>Head ID</span>
            <input
              className="desktop-input"
              value={newHeadId}
              maxLength={40}
              autoComplete="off"
              placeholder="e.g. HeadCustom"
              onChange={(event) => setNewHeadId(event.target.value)}
              required
            />
          </label>
          <label>
            <span>Used For</span>
            <input
              className="desktop-input"
              value={newUsedFor}
              maxLength={120}
              autoComplete="off"
              onChange={(event) => setNewUsedFor(event.target.value)}
              required
            />
          </label>
        </form>
      </UiModal>
    </>
  )
}
