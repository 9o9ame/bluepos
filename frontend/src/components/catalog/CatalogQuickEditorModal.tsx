import { useEffect } from 'react'
import { createPortal } from 'react-dom'
import { Plus, RefreshCw, Save, Trash2, X } from 'lucide-react'
import { ApiClientError } from '../../api/client'
import { useWorkspace } from '../../features/workspace/WorkspaceProvider'
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
  onSaved?: (kind: QuickEditorKind, item: CatalogMasterRecord, created: boolean) => void
}

const COPY: Record<QuickEditorKind, { title: string; listTitle: string }> = {
  category: {
    title: 'Edit/Define Categories',
    listTitle: 'Edit/Define Categories',
  },
  brand: {
    title: 'Edit/Define Manufacture',
    listTitle: 'Edit/Define Manufacture',
  },
  unit: {
    title: 'Edit/Define Units',
    listTitle: 'Edit/Define Units',
  },
  barcode_group: {
    title: 'Edit/Define Barcode Groups',
    listTitle: 'Edit/Define Barcode Groups',
  },
}

export function CatalogQuickEditorModal({
  kind,
  open,
  onClose,
  onSaved,
}: CatalogQuickEditorModalProps) {
  const activeKind = kind ?? 'category'
  const copy = COPY[activeKind]
  const { openModule } = useWorkspace()
  const editor = useCatalogMasterEditor(activeKind, {
    enabled: open,
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

  return createPortal(
    <div
      className="catalog-popup-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.currentTarget === event.target) onClose()
      }}
    >
      <section
        className="catalog-popup"
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

        <div className="catalog-popup-green-title">{copy.title}</div>

        <div className="catalog-popup-content">
          <div className="catalog-popup-subtitle">
            <strong>{copy.listTitle}</strong>
            <span
              role={activeKind === 'barcode_group' ? undefined : 'button'}
              tabIndex={activeKind === 'barcode_group' ? undefined : 0}
              onClick={() => {
                const path = {
                  category: '/definition/categories',
                  brand: '/definition/brands',
                  unit: '/definition/units',
                  barcode_group: null,
                }[activeKind]
                if (path) {
                  onClose()
                  openModule(path)
                }
              }}
              onKeyDown={(event) => {
                if (event.key === 'Enter') event.currentTarget.click()
              }}
            >
              Tabular View
            </span>
          </div>

          <div className="catalog-popup-form">
            <label>Code:</label>
            <input
              className="catalog-popup-code"
              value={editor.code}
              onChange={(event) => editor.setCode(event.target.value)}
              autoFocus
            />

            <label>{activeKind === 'unit' ? 'Unit Name:' : 'Desc:'}</label>
            <input
              className="catalog-popup-name"
              value={editor.name}
              onChange={(event) => editor.setName(event.target.value)}
            />

            {activeKind === 'unit' ? (
              <>
                <label>Symbol:</label>
                <input
                  className="catalog-popup-normal"
                  value={editor.symbol}
                  onChange={(event) => editor.setSymbol(event.target.value)}
                />

                <label className="catalog-popup-checkbox">
                  <span>Allows Decimal:</span>
                  <input
                    type="checkbox"
                    checked={editor.allowsDecimal}
                    onChange={(event) => editor.setAllowsDecimal(event.target.checked)}
                  />
                </label>
              </>
            ) : null}
          </div>

          {editor.error ? <div className="catalog-popup-error">{editor.error}</div> : null}

          <div className="catalog-popup-grid-wrap">
            <table className="catalog-popup-grid">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>{activeKind === 'unit' ? 'Unit Name' : 'Desc'}</th>
                  {activeKind === 'unit' ? <th>Symbol</th> : null}
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {editor.rows.map((row) => (
                  <tr
                    key={row.ulid}
                    className={editor.selectedKey === row.ulid ? 'is-selected' : undefined}
                    onClick={() => editor.select(row)}
                  >
                    <td>{row.code}</td><td>{row.code}</td>
                    <td>{row.name}</td>

                    {activeKind === 'unit' ? (
                      <td>{'symbol' in row ? row.symbol : ''}</td>
                    ) : null}

                    <td>
                      {row.is_active ? 'Active' : 'Archived'}
                    </td>
                  </tr>
                ))}

                {editor.rows.length === 0 ? (
                  <tr>
                    <td colSpan={activeKind === 'unit' ? 4 : 3} className="catalog-popup-empty" >
                      No records
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
            disabled={
              !editor.selected ||
              editor.isDeactivating ||
              editor.isActivating ||
              (
                editor.selected.is_active
                  ? !editor.canDelete
                  : !editor.canActivate
              )
            }
            onClick={() => {
              if (!editor.selected) {
                return
              }

              if (editor.selected.is_active) {
                if (
                  !window.confirm(
                    `Deactivate ${editor.selected.name}?`,
                  )
                ) {
                  return
                }

                void editor.deactivate().catch((err) => {
                  editor.setError(
                    err instanceof ApiClientError
                      ? err.message
                      : 'Unable to deactivate record.',
                  )
                })

                return
              }

              void editor.activate().catch((err) => {
                editor.setError(
                  err instanceof ApiClientError
                    ? err.message
                    : 'Unable to activate record.',
                )
              })
            }}
          >
            {editor.selected && !editor.selected.is_active ? (
              <>
                <RefreshCw size={22} />
                Activate
              </>
            ) : (
              <>
                <Trash2 size={22} />
                Delete
              </>
            )}
          </button>

          <button type="button" className="catalog-popup-action" onClick={editor.startNew}>
            <Plus size={22} />
            New
          </button>

          <span className="catalog-popup-action-spacer" />

          <button
            type="button"
            className="catalog-popup-action"
            disabled={!editor.canSave || editor.isSaving}
            onClick={() => void save()}
          >
            <Save size={22} />
            Save
          </button>

          <button
            type="button"
            className="catalog-popup-action"
            onClick={() => void editor.refresh()}
          >
            <RefreshCw size={22} />
            Refresh
          </button>

          <button type="button" className="catalog-popup-action" onClick={onClose}>
            <X size={22} />
            Close
          </button>
        </div>
      </section>
    </div>,
    document.body,
  )
}
