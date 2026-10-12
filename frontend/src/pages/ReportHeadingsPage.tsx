import { FormEvent, useEffect, useMemo, useState } from 'react'
import { EditorContent, useEditor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { TextStyleKit } from '@tiptap/extension-text-style'
import UnderlineExtension from '@tiptap/extension-underline'
import TextAlign from '@tiptap/extension-text-align'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlignCenter,
  AlignJustify,
  AlignLeft,
  AlignRight,
  Bold,
  Copy,
  Heading2,
  Italic,
  List,
  ListOrdered,
  Pilcrow,
  Plus,
  Redo2,
  RefreshCw,
  RemoveFormatting,
  Save,
  Strikethrough,
  Trash2,
  Undo2,
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

const EDITOR_TABS = ['Home', 'View', 'Page Layout', 'Insert', 'Quick Settings'] as const

const FONT_FAMILIES = [
  { value: 'Inter', label: 'Inter' },
  { value: 'Arial', label: 'Arial' },
  { value: 'Calibri', label: 'Calibri' },
  { value: 'Georgia', label: 'Georgia' },
  { value: 'Times New Roman', label: 'Times New Roman' },
  { value: 'Verdana', label: 'Verdana' },
  { value: 'Courier New', label: 'Courier New' },
]

const FONT_SIZES = ['8', '9', '10', '11', '12', '14', '16', '18', '20', '24', '28', '32'].map((size) => ({
  value: `${size}px`,
  label: size,
}))

export function ReportHeadingsPage() {
  const { closeActiveTab } = useWorkspace()
  const canManage = useCan('settings.manage')
  const feedback = useFeedback()
  const queryClient = useQueryClient()

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
  const [editorTab, setEditorTab] = useState<(typeof EDITOR_TABS)[number]>('Home')

  const selected = useMemo(
    () => query.data?.find((row) => row.ulid === selectedUlid) ?? null,
    [query.data, selectedUlid],
  )

  const editor = useEditor({
    extensions: [
      StarterKit,
      TextStyleKit.configure({
        lineHeight: false,
      }),
      UnderlineExtension,
      TextAlign.configure({
        types: ['heading', 'paragraph'],
      }),
    ],
    content: '',
    editable: false,
    editorProps: {
      attributes: {
        class: 'report-headings-document',
        'aria-label': 'Report heading rich text editor',
      },
    },
    onUpdate: ({ editor: currentEditor }) => {
      setContentHtml(currentEditor.getHTML())
    },
  })

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
    if (!editor) return

    if (!selected) {
      setUsedFor('')
      setContentHtml('')
      editor.commands.setContent('', { emitUpdate: false })
      editor.setEditable(false)
      return
    }

    const nextHtml = selected.content_html ?? ''
    setUsedFor(selected.used_for)
    setContentHtml(nextHtml)
    editor.commands.setContent(nextHtml, { emitUpdate: false })
    editor.setEditable(canManage)
  }, [canManage, editor, selected])

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

  function submitNew(event: FormEvent) {
    event.preventDefault()
    if (!newHeadId.trim() || !newUsedFor.trim() || createMutation.isPending) return
    createMutation.mutate()
  }

  const editorDisabled = !canManage || !selected || !editor

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
          <div className="report-headings-editor-tabs" role="tablist" aria-label="Editor ribbon tabs">
            {EDITOR_TABS.map((tab) => (
              <button
                key={tab}
                type="button"
                role="tab"
                aria-selected={editorTab === tab}
                className={editorTab === tab ? 'is-active' : undefined}
                onClick={() => setEditorTab(tab)}
              >
                {tab}
              </button>
            ))}
          </div>

          <div className="report-headings-editor-ribbon" role="tabpanel">
            {editorTab === 'Home' ? (
              <>
                <div className="report-headings-editor-group">
                  <div className="report-headings-editor-group-actions">
                    <UiButton
                      icon={<Undo2 size={14} />}
                      label="Undo"
                      disabled={editorDisabled || !editor?.can().chain().focus().undo().run()}
                      onClick={() => editor?.chain().focus().undo().run()}
                    />
                    <UiButton
                      icon={<Redo2 size={14} />}
                      label="Redo"
                      disabled={editorDisabled || !editor?.can().chain().focus().redo().run()}
                      onClick={() => editor?.chain().focus().redo().run()}
                    />
                  </div>
                  <span>Common</span>
                </div>

                <div className="report-headings-editor-group is-font-group">
                  <div className="report-headings-editor-font-row">
                    <UiSelect
                      value={editor?.getAttributes('textStyle').fontFamily ?? ''}
                      searchable={false}
                      disabled={editorDisabled}
                      placeholder="Font"
                      options={FONT_FAMILIES}
                      onChange={(value) => {
                        if (!editor) return
                        if (value) editor.chain().focus().setFontFamily(value).run()
                        else editor.chain().focus().unsetFontFamily().run()
                      }}
                    />
                    <UiSelect
                      value={editor?.getAttributes('textStyle').fontSize ?? ''}
                      searchable={false}
                      disabled={editorDisabled}
                      placeholder="Size"
                      options={FONT_SIZES}
                      onChange={(value) => {
                        if (!editor) return
                        if (value) editor.chain().focus().setFontSize(value).run()
                        else editor.chain().focus().unsetFontSize().run()
                      }}
                    />
                  </div>
                  <div className="report-headings-editor-group-actions">
                    <UiButton
                      className={editor?.isActive('bold') ? 'is-editor-active' : undefined}
                      icon={<Bold size={14} />}
                      label="Bold"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().toggleBold().run()}
                    />
                    <UiButton
                      className={editor?.isActive('italic') ? 'is-editor-active' : undefined}
                      icon={<Italic size={14} />}
                      label="Italic"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().toggleItalic().run()}
                    />
                    <UiButton
                      className={editor?.isActive('underline') ? 'is-editor-active' : undefined}
                      icon={<Underline size={14} />}
                      label="Underline"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().toggleUnderline().run()}
                    />
                    <UiButton
                      className={editor?.isActive('strike') ? 'is-editor-active' : undefined}
                      icon={<Strikethrough size={14} />}
                      label="Strike"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().toggleStrike().run()}
                    />
                    <label className="report-headings-color-control" title="Text color">
                      <span>A</span>
                      <input
                        type="color"
                        aria-label="Text color"
                        disabled={editorDisabled}
                        value={editor?.getAttributes('textStyle').color ?? '#000000'}
                        onChange={(event) => editor?.chain().focus().setColor(event.target.value).run()}
                      />
                    </label>
                    <label className="report-headings-color-control" title="Highlight color">
                      <span>ab</span>
                      <input
                        type="color"
                        aria-label="Highlight color"
                        disabled={editorDisabled}
                        value={editor?.getAttributes('textStyle').backgroundColor ?? '#ffff00'}
                        onChange={(event) => editor?.chain().focus().setBackgroundColor(event.target.value).run()}
                      />
                    </label>
                    <UiButton
                      icon={<RemoveFormatting size={14} />}
                      label="Clear"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().clearNodes().unsetAllMarks().run()}
                    />
                  </div>
                  <span>Font</span>
                </div>

                <div className="report-headings-editor-group">
                  <div className="report-headings-editor-group-actions">
                    <UiButton
                      className={editor?.isActive('paragraph') ? 'is-editor-active' : undefined}
                      icon={<Pilcrow size={14} />}
                      label="Paragraph"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().setParagraph().run()}
                    />
                    <UiButton
                      className={editor?.isActive('heading', { level: 2 }) ? 'is-editor-active' : undefined}
                      icon={<Heading2 size={14} />}
                      label="Heading"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().toggleHeading({ level: 2 }).run()}
                    />
                    <UiButton
                      className={editor?.isActive('bulletList') ? 'is-editor-active' : undefined}
                      icon={<List size={14} />}
                      label="Bullets"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().toggleBulletList().run()}
                    />
                    <UiButton
                      className={editor?.isActive('orderedList') ? 'is-editor-active' : undefined}
                      icon={<ListOrdered size={14} />}
                      label="Numbering"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().toggleOrderedList().run()}
                    />
                    <UiButton
                      className={editor?.isActive({ textAlign: 'left' }) ? 'is-editor-active' : undefined}
                      icon={<AlignLeft size={14} />}
                      label="Left"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().setTextAlign('left').run()}
                    />
                    <UiButton
                      className={editor?.isActive({ textAlign: 'center' }) ? 'is-editor-active' : undefined}
                      icon={<AlignCenter size={14} />}
                      label="Center"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().setTextAlign('center').run()}
                    />
                    <UiButton
                      className={editor?.isActive({ textAlign: 'right' }) ? 'is-editor-active' : undefined}
                      icon={<AlignRight size={14} />}
                      label="Right"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().setTextAlign('right').run()}
                    />
                    <UiButton
                      className={editor?.isActive({ textAlign: 'justify' }) ? 'is-editor-active' : undefined}
                      icon={<AlignJustify size={14} />}
                      label="Justify"
                      disabled={editorDisabled}
                      onClick={() => editor?.chain().focus().setTextAlign('justify').run()}
                    />
                  </div>
                  <span>Paragraph</span>
                </div>
              </>
            ) : (
              <div className="report-headings-editor-placeholder">
                {editorTab} options will be enabled as the editor feature set is expanded.
              </div>
            )}
          </div>

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

          <div className="report-headings-workarea">
            <div className="report-headings-document-shell">
              <div className="report-headings-ruler" aria-hidden>
                {Array.from({ length: 21 }, (_, index) => (
                  <span key={index}>{index % 2 === 0 ? index / 2 : ''}</span>
                ))}
              </div>
              <div className="report-headings-paper">
                <EditorContent editor={editor} />
              </div>
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
