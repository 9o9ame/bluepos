import { useEffect } from 'react'
import { createPortal } from 'react-dom'
import { RefreshCw, Save, Trash2, X } from 'lucide-react'
import { ApiClientError } from '../../api/client'
import { askConfirm } from '../../feedback/FeedbackProvider'
import { UiSelect } from '../ui/UiSelect'
import { UI_LAYER } from '../ui/uiLayers'
import {
  useCatalogMasterEditor,
  type CatalogMasterKind,
  type CatalogMasterRecord,
} from './useCatalogMasterEditor'
import './CatalogQuickEditorModal.css'

export type QuickEditorKind = CatalogMasterKind

type CatalogQuickEditorModalProps = {
  kind: QuickEditorKind | null
  open: boolean
  onClose: () => void
  parentCategoryUlid?: string
  onSaved?: (kind: QuickEditorKind, item: CatalogMasterRecord, created: boolean) => void
}

const COPY: Record<QuickEditorKind, { title: string; nameLabel: string }> = {
  category: {
    title: 'Edit/Define Categories',
    nameLabel: 'Name',
  },
  subcategory: {
    title: 'Edit/Define Subcategories',
    nameLabel: 'Name',
  },
  brand: {
    title: 'Edit/Define Company',
    nameLabel: 'Name',
  },
  unit: {
    title: 'Edit/Define Units',
    nameLabel: 'Name',
  },
  barcode_group: {
    title: 'Edit/Define Barcode Groups',
    nameLabel: 'Name',
  },
}

export function CatalogQuickEditorModal({
  kind,
  open,
  onClose,
  parentCategoryUlid,
  onSaved,
}: CatalogQuickEditorModalProps) {
  const activeKind = kind ?? 'category'
  const copy = COPY[activeKind]
  const editor = useCatalogMasterEditor(activeKind, {
    enabled: open,
    parentCategoryUlid,
    onSaved,
  })

  useEffect(() => {
    if (!open) return

    editor.startNew()
    // Reset only when the popup opens or changes master type.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, activeKind])

  useEffect(() => {
    if (!open) return

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.preventDefault()
        onClose()
      }
    }

    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [open, onClose])

  if (!open || !kind || typeof document === 'undefined') {
    return null
  }

  async function save() {
    if (!editor.canSave) return

    editor.setError(null)

    if (!editor.code.trim() || !editor.name.trim()) {
      editor.setError('Code and name are required.')
      return
    }

    if (activeKind === 'subcategory' && !editor.categoryUlid) {
      editor.setError('Category is required.')
      return
    }

    if (activeKind === 'unit' && !editor.symbol.trim()) {
      editor.setError('Symbol is required.')
      return
    }

    try {
      await editor.save()
    } catch (err) {
      editor.setError(
        err instanceof ApiClientError ? err.message : err instanceof Error ? err.message : 'Unable to save.',
      )
    }
  }

  const tableColSpan =
    activeKind === 'unit' ? 3 : activeKind === 'subcategory' ? 3 : 2

  return createPortal(
    <div
      className="catalog-popup-backdrop"
      style={{ zIndex: UI_LAYER.modal }}
      role="presentation"
      onMouseDown={(event) => {
        if (event.currentTarget === event.target) onClose()
      }}
    >
      <section
        className={`catalog-popup catalog-popup--${activeKind}`}
        role="dialog"
        aria-modal="true"
        aria-label={copy.title}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="catalog-popup-windowbar">
          <span>{copy.title}</span>
          <button type="button" onClick={onClose} aria-label="Close">
            <X size={13} />
          </button>
        </div>

        <div className="catalog-popup-content">
          <div className="catalog-popup-form">
            {activeKind === 'subcategory' ? (
              <div className="catalog-popup-form-row catalog-popup-form-row--full">
                <label htmlFor="catalog-popup-category">Category:</label>
                <UiSelect
                  value={editor.categoryUlid}
                  triggerClassName="catalog-popup-select"
                  aria-label="Category"
                  menuZIndex={UI_LAYER.nestedDropdown}
                  options={[
                    { value: '', label: 'Select category…' },
                    ...editor.categories.map((category) => ({
                      value: category.ulid,
                      label: `${category.code} — ${category.name}`,
                    })),
                  ]}
                  onChange={editor.setCategoryUlid}
                />
              </div>
            ) : null}

            <div className="catalog-popup-form-row catalog-popup-form-row--code-name">
              <label htmlFor="catalog-popup-code">Code:</label>
              <input
                id="catalog-popup-code"
                className="catalog-popup-code"
                value={editor.code}
                onChange={(event) => editor.setCode(event.target.value)}
                autoFocus
              />

              <label htmlFor="catalog-popup-name">{copy.nameLabel}:</label>
              <input
                id="catalog-popup-name"
                className="catalog-popup-name"
                value={editor.name}
                onChange={(event) => editor.setName(event.target.value)}
              />
            </div>

            {activeKind === 'unit' ? (
              <div className="catalog-popup-form-row catalog-popup-form-row--unit-extra">
                <label htmlFor="catalog-popup-symbol">Symbol:</label>
                <input
                  id="catalog-popup-symbol"
                  className="catalog-popup-normal"
                  value={editor.symbol}
                  onChange={(event) => editor.setSymbol(event.target.value)}
                />

                <label className="catalog-popup-checkbox" htmlFor="catalog-popup-decimal">
                  <span>Allows Decimal:</span>
                  <input
                    id="catalog-popup-decimal"
                    type="checkbox"
                    checked={editor.allowsDecimal}
                    onChange={(event) => editor.setAllowsDecimal(event.target.checked)}
                  />
                </label>
              </div>
            ) : null}
          </div>

          {editor.error ? <div className="catalog-popup-error">{editor.error}</div> : null}

          <div className="catalog-popup-grid-wrap">
            <table className="catalog-popup-grid">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>{copy.nameLabel}</th>
                  {activeKind === 'subcategory' ? <th>Category</th> : null}
                  {activeKind === 'unit' ? <th>Symbol</th> : null}
                </tr>
              </thead>
              <tbody>
                {editor.rows.map((row) => (
                  <tr
                    key={row.ulid}
                    className={editor.selectedKey === row.ulid ? 'is-selected' : undefined}
                    onClick={() => editor.select(row)}
                  >
                    <td>{row.code}</td>
                    <td>{row.name}</td>
                    {activeKind === 'subcategory' ? (
                      <td>
                        {'category' in row && row.category
                          ? row.category.name
                          : editor.categories.find((c) => c.ulid === editor.categoryUlid)?.name ?? '—'}
                      </td>
                    ) : null}
                    {activeKind === 'unit' ? <td>{'symbol' in row ? row.symbol : ''}</td> : null}
                  </tr>
                ))}

                {editor.rows.length === 0 ? (
                  <tr>
                    <td colSpan={tableColSpan} className="catalog-popup-empty">
                      {activeKind === 'subcategory' && !editor.categoryUlid
                        ? 'Select a category to list subcategories'
                        : 'No records'}
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>

          <div className="catalog-popup-records">{editor.rows.length} Records</div>
        </div>

        <div className="catalog-popup-actions">
          <button
            type="button"
            className="catalog-popup-action"
            data-tone="delete"
            disabled={
              !editor.canDelete ||
              !editor.selected ||
              !editor.selected.is_active ||
              editor.isDeactivating
            }
            onClick={() => {
              void (async () => {
                if (!editor.selected || !(await askConfirm(`Deactivate ${editor.selected.name}?`))) return
                void editor.deactivate().catch((err) => {
                  editor.setError(err instanceof ApiClientError ? err.message : 'Unable to deactivate record.')
                })
              })()
            }}
          >
            <Trash2 size={22} />
            Delete
          </button>

          <span className="catalog-popup-action-spacer" />

          <button
            type="button"
            className="catalog-popup-action"
            data-tone="save"
            disabled={!editor.canSave || editor.isSaving}
            onClick={() => void save()}
          >
            <Save size={22} />
            Save
          </button>

          <button
            type="button"
            className="catalog-popup-action"
            data-tone="refresh"
            title="Refresh list and start a new record"
            onClick={() => void editor.refresh()}
          >
            <RefreshCw size={22} />
            Refresh
          </button>

          <button
            type="button"
            className="catalog-popup-action"
            data-tone="close"
            onClick={onClose}
          >
            <X size={22} />
            Close
          </button>
        </div>
      </section>
    </div>,
    document.body,
  )
}
