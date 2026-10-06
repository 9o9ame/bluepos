import { FormEvent, useEffect, useMemo, useState } from 'react'
import {
  ChevronFirst,
  ChevronLast,
  ChevronLeft,
  ChevronRight,
  Plus,
  RefreshCw,
  Save,
  X,
  XCircle,
} from 'lucide-react'
import {
  createAccountType,
  createMainHead,
  createSubHead,
  fetchAccountTypes,
  fetchMainHeads,
  fetchSubHeads,
  updateAccountType,
  updateMainHead,
  updateSubHead,
  type CoaAccountType,
  type CoaMainHead,
  type CoaSubHead,
} from '../../api/coa'
import { ApiClientError } from '../../api/client'
import { UiSelect } from '../ui/UiSelect'
import { ToggleSwitch } from '../ui/ToggleSwitch'
import './CoaHierarchyModals.css'
import './CoaHierarchyModals.modern.css'

type Props = {
  open: boolean
  selectedAccountTypeUlid: string | null
  onClose: () => void
  onAccountTypeSaved: (type: CoaAccountType) => void
  onHierarchyChanged: () => void
  compact?: boolean
}

type TypeDraft = {
  key: string
  ulid: string | null
  code: string
  name: string
  is_cash: boolean
  is_bank: boolean
  is_receivable: boolean
  is_payable: boolean
  sub_head_ulid: string
  pnl_grouping_label: string
  hint: string
  sort_order: string
  is_active: boolean
  dirty: boolean
}

function errMsg(err: unknown): string {
  if (err instanceof ApiClientError) return err.message
  if (err instanceof Error) return err.message
  return 'Request failed'
}

function toDraft(row: CoaAccountType): TypeDraft {
  return {
    key: row.ulid,
    ulid: row.ulid,
    code: row.code ?? '',
    name: row.name,
    is_cash: row.is_cash,
    is_bank: row.is_bank,
    is_receivable: row.is_receivable,
    is_payable: row.is_payable,
    sub_head_ulid: row.sub_head?.ulid ?? row.sub_head_ulid ?? '',
    pnl_grouping_label: row.pnl_grouping_label ?? '',
    hint: row.hint ?? '',
    sort_order: String(row.sort_order ?? 0),
    is_active: row.is_active,
    dirty: false,
  }
}

function blankDraft(subUlid = ''): TypeDraft {
  return {
    key: `new-${Date.now()}`,
    ulid: null,
    code: '',
    name: '',
    is_cash: false,
    is_bank: false,
    is_receivable: false,
    is_payable: false,
    sub_head_ulid: subUlid,
    pnl_grouping_label: '',
    hint: '',
    sort_order: '0',
    is_active: true,
    dirty: true,
  }
}

export function CoaHierarchyModals({
  open,
  selectedAccountTypeUlid,
  onClose,
  onAccountTypeSaved,
  onHierarchyChanged,
  compact = false,
}: Props) {
  const [layer, setLayer] = useState<'type' | 'sub' | 'main'>('type')
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [onlyActive, setOnlyActive] = useState(false)

  const [typeRows, setTypeRows] = useState<TypeDraft[]>([])
  const [selectedTypeKey, setSelectedTypeKey] = useState<string | null>(null)
  const [subs, setSubs] = useState<CoaSubHead[]>([])
  const [mains, setMains] = useState<CoaMainHead[]>([])

  const [subUlid, setSubUlid] = useState<string | null>(null)
  const [subName, setSubName] = useState('')
  const [subMainUlid, setSubMainUlid] = useState('')
  const [subSort, setSubSort] = useState('0')
  const [subActive, setSubActive] = useState(true)
  const [subTargetTypeKey, setSubTargetTypeKey] = useState<string | null>(null)

  const [mainUlid, setMainUlid] = useState<string | null>(null)
  const [mainName, setMainName] = useState('')
  const [mainSort, setMainSort] = useState('0')
  const [mainActive, setMainActive] = useState(true)

  const visibleRows = useMemo(
    () => (onlyActive ? typeRows.filter((r) => r.is_active || r.dirty || !r.ulid) : typeRows),
    [typeRows, onlyActive],
  )

  const selectedIndex = visibleRows.findIndex((r) => r.key === selectedTypeKey)
  const selectedRow = selectedIndex >= 0 ? visibleRows[selectedIndex] : null

  async function reloadLists(selectTypeUlid?: string | null) {
    const [t, s, m] = await Promise.all([fetchAccountTypes(), fetchSubHeads(), fetchMainHeads()])
    const drafts = t.map(toDraft)
    setTypeRows(drafts)
    setSubs(s)
    setMains(m)
    const pick = selectTypeUlid ?? selectedAccountTypeUlid
    const found = pick ? drafts.find((row) => row.ulid === pick) : drafts[0]
    setSelectedTypeKey(found?.key ?? drafts[0]?.key ?? null)
  }

  function patchTypeRow(key: string, patch: Partial<TypeDraft>) {
    setTypeRows((rows) =>
      rows.map((row) => (row.key === key ? { ...row, ...patch, dirty: true } : row)),
    )
  }

  function applySub(row: CoaSubHead) {
    setSubUlid(row.ulid)
    setSubName(row.name)
    setSubMainUlid(row.main_head?.ulid ?? row.main_head_ulid ?? '')
    setSubSort(String(row.sort_order ?? 0))
    setSubActive(row.is_active)
  }

  function resetSubForm(keepMain = true) {
    setSubUlid(null)
    setSubName('')
    if (!keepMain) setSubMainUlid('')
    setSubSort('0')
    setSubActive(true)
  }

  function applyMain(row: CoaMainHead) {
    setMainUlid(row.ulid)
    setMainName(row.name)
    setMainSort(String(row.sort_order ?? 0))
    setMainActive(row.is_active)
  }

  function resetMainForm() {
    setMainUlid(null)
    setMainName('')
    setMainSort('0')
    setMainActive(true)
  }

  function openSubEditor(forTypeKey: string | null, preferSubUlid?: string) {
    setError(null)
    setSubTargetTypeKey(forTypeKey)
    resetSubForm(true)
    if (preferSubUlid) {
      const current = subs.find((s) => s.ulid === preferSubUlid)
      if (current) applySub(current)
    }
    setLayer('sub')
  }

  function addTypeRow() {
    const draft = blankDraft(selectedRow?.sub_head_ulid ?? '')
    setTypeRows((rows) => [...rows, draft])
    setSelectedTypeKey(draft.key)
  }

  function removeSelectedDraft() {
    if (!selectedRow) return
    if (selectedRow.ulid) {
      setError('Existing Account Types are deactivated via Status Off, then Save.')
      return
    }
    setTypeRows((rows) => rows.filter((r) => r.key !== selectedRow.key))
    setSelectedTypeKey(null)
  }

  function moveSelection(index: number) {
    if (visibleRows.length === 0) return
    const bounded = Math.min(Math.max(index, 0), visibleRows.length - 1)
    setSelectedTypeKey(visibleRows[bounded].key)
  }

  useEffect(() => {
    if (!open) return
    setLayer('type')
    setError(null)
    setOnlyActive(false)
    void reloadLists(selectedAccountTypeUlid).catch((err) => setError(errMsg(err)))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  if (!open) return null

  async function saveSelectedType(e?: FormEvent) {
    e?.preventDefault()
    if (!selectedRow) {
      setError('Select a row to save.')
      return
    }
    if (!selectedRow.name.trim() || !selectedRow.sub_head_ulid) {
      setError('Description and Sub Head are required.')
      return
    }
    if (!selectedRow.ulid && !selectedRow.code.trim()) {
      setError('Code is required for new Account Types.')
      return
    }

    setSaving(true)
    setError(null)
    const payload = {
      sub_head_ulid: selectedRow.sub_head_ulid,
      code: selectedRow.code.trim() ? selectedRow.code.trim().toUpperCase() : null,
      name: selectedRow.name.trim(),
      is_cash: selectedRow.is_cash,
      is_bank: selectedRow.is_bank,
      is_receivable: selectedRow.is_receivable,
      is_payable: selectedRow.is_payable,
      pnl_grouping_label: selectedRow.pnl_grouping_label.trim() || null,
      hint: selectedRow.hint.trim() || null,
      sort_order: Number(selectedRow.sort_order) || 0,
      is_active: selectedRow.is_active,
    }

    try {
      const saved = selectedRow.ulid
        ? await updateAccountType(selectedRow.ulid, payload)
        : await createAccountType({ ...payload, code: selectedRow.code.trim().toUpperCase() })
      await reloadLists(saved.ulid)
      onAccountTypeSaved(saved)
      onHierarchyChanged()
    } catch (err) {
      setError(errMsg(err))
    } finally {
      setSaving(false)
    }
  }

  async function saveSub(e: FormEvent) {
    e.preventDefault()
    if (!subName.trim() || !subMainUlid) {
      setError('Sub Head name and Main Head are required.')
      return
    }
    setSaving(true)
    setError(null)
    const payload = {
      main_head_ulid: subMainUlid,
      name: subName.trim(),
      sort_order: Number(subSort) || 0,
      is_active: subActive,
    }
    try {
      const saved = subUlid ? await updateSubHead(subUlid, payload) : await createSubHead(payload)
      const refreshed = await fetchSubHeads()
      setSubs(refreshed)
      if (subTargetTypeKey) {
        patchTypeRow(subTargetTypeKey, { sub_head_ulid: saved.ulid })
      }
      applySub(saved)
      onHierarchyChanged()
      setLayer('type')
    } catch (err) {
      setError(errMsg(err))
    } finally {
      setSaving(false)
    }
  }

  async function saveMain(e: FormEvent) {
    e.preventDefault()
    if (!mainName.trim()) {
      setError('Main Head name is required.')
      return
    }
    setSaving(true)
    setError(null)
    const payload = {
      name: mainName.trim(),
      sort_order: Number(mainSort) || 0,
      is_active: mainActive,
    }
    try {
      const saved = mainUlid ? await updateMainHead(mainUlid, payload) : await createMainHead(payload)
      const refreshed = await fetchMainHeads()
      setMains(refreshed)
      setSubMainUlid(saved.ulid)
      applyMain(saved)
      onHierarchyChanged()
      setLayer('sub')
    } catch (err) {
      setError(errMsg(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <div className="coa-modal-backdrop" style={{ zIndex: 5200 }}>
        <div
          className={`coa-modal coa-modal-sheet${compact ? ' is-compact-nested' : ''}`}
          role="dialog"
          aria-label="Account Type Definition"
        >
          <div className="coa-modal-bar">
            <span>Account Type Definition</span>
            <div className="coa-modal-bar-right">
              <ToggleSwitch
                label="Only Active"
                tone="active"
                checked={onlyActive}
                onChange={setOnlyActive}
              />
              <button type="button" onClick={onClose} aria-label="Close">
                <X size={14} />
              </button>
            </div>
          </div>

          <form className="coa-modal-body coa-sheet-body" onSubmit={(e) => void saveSelectedType(e)}>
            {error && layer === 'type' ? <div className="coa-modal-error">{error}</div> : null}

              <div className="coa-sheet-wrap">
                <table className="coa-sheet-grid">
                  <thead>
                    <tr>
                      <th className="col-code">Code</th>
                      <th className="col-name">Description of Account Type</th>
                      <th className="col-flag">Cash</th>
                      <th className="col-flag">Bank</th>
                      <th className="col-flag">Rec</th>
                      <th className="col-flag">Pay</th>
                      <th className="col-sub">Sub Head Account</th>
                      <th className="col-pnl">P &amp; L Statement Grouping Label</th>
                      <th className="col-hint">Hint</th>
                      <th className="col-sort">Sort</th>
                      <th className="col-status">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {visibleRows.length === 0 ? (
                      <tr>
                        <td colSpan={11} className="coa-empty">
                          No account types — click + to add
                        </td>
                      </tr>
                    ) : (
                      visibleRows.map((row) => (
                        <tr
                          key={row.key}
                          className={row.key === selectedTypeKey ? 'is-selected' : undefined}
                          onClick={() => setSelectedTypeKey(row.key)}
                        >
                          <td>
                            <input
                              value={row.code}
                              disabled={Boolean(row.ulid)}
                              title={row.ulid ? 'Code is stable after create' : undefined}
                              onChange={(e) => patchTypeRow(row.key, { code: e.target.value })}
                            />
                          </td>
                          <td>
                            <input
                              value={row.name}
                              onChange={(e) => patchTypeRow(row.key, { name: e.target.value })}
                            />
                          </td>
                          <td className="col-flag">
                            <ToggleSwitch
                              label=""
                              title="Cash"
                              tone="cash"
                              checked={row.is_cash}
                              onChange={(v) => patchTypeRow(row.key, { is_cash: v })}
                            />
                          </td>
                          <td className="col-flag">
                            <ToggleSwitch
                              label=""
                              title="Bank"
                              tone="bank"
                              checked={row.is_bank}
                              onChange={(v) => patchTypeRow(row.key, { is_bank: v })}
                            />
                          </td>
                          <td className="col-flag">
                            <ToggleSwitch
                              label=""
                              title="Receivable"
                              tone="rec"
                              checked={row.is_receivable}
                              onChange={(v) => patchTypeRow(row.key, { is_receivable: v })}
                            />
                          </td>
                          <td className="col-flag">
                            <ToggleSwitch
                              label=""
                              title="Payable"
                              tone="pay"
                              checked={row.is_payable}
                              onChange={(v) => patchTypeRow(row.key, { is_payable: v })}
                            />
                          </td>
                          <td>
                            <div className="coa-sheet-sub">
                              <UiSelect
                                value={row.sub_head_ulid}
                                aria-label="Sub Head Account"
                                menuZIndex={5250}
                                options={[
                                  { value: '', label: 'Select…' },
                                  ...subs
                                    .filter((s) => s.is_active || s.ulid === row.sub_head_ulid)
                                    .map((s) => ({
                                      value: s.ulid,
                                      label: `${s.name}${s.main_head ? ` (${s.main_head.name})` : ''}`,
                                    })),
                                ]}
                                onChange={(ulid) => patchTypeRow(row.key, { sub_head_ulid: ulid })}
                              />
                              <button
                                type="button"
                                className="coa-plus"
                                title="Add / Edit Sub Head"
                                aria-label="Add or edit Sub Head"
                                onClick={(e) => {
                                  e.stopPropagation()
                                  openSubEditor(row.key, row.sub_head_ulid)
                                }}
                              >
                                <Plus size={15} strokeWidth={3} />
                              </button>
                            </div>
                          </td>
                          <td>
                            <input
                              value={row.pnl_grouping_label}
                              onChange={(e) => patchTypeRow(row.key, { pnl_grouping_label: e.target.value })}
                            />
                          </td>
                          <td>
                            <input
                              value={row.hint}
                              onChange={(e) => patchTypeRow(row.key, { hint: e.target.value })}
                            />
                          </td>
                          <td>
                            <input
                              className="is-num"
                              value={row.sort_order}
                              onChange={(e) => patchTypeRow(row.key, { sort_order: e.target.value })}
                            />
                          </td>
                          <td className="col-flag">
                            <ToggleSwitch
                              label=""
                              title={row.is_active ? 'Active' : 'Off'}
                              tone="active"
                              checked={row.is_active}
                              onChange={(v) => patchTypeRow(row.key, { is_active: v })}
                            />
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              <div className="coa-sheet-records">
                {visibleRows.length} Records
              </div>

              <div className="coa-sheet-foot">
                <div className="coa-sheet-nav" aria-label="Record navigation">
                  <button type="button" disabled={visibleRows.length === 0} onClick={() => moveSelection(0)}>
                    <ChevronFirst size={14} />
                  </button>
                  <button
                    type="button"
                    disabled={selectedIndex <= 0}
                    onClick={() => moveSelection(selectedIndex - 1)}
                  >
                    <ChevronLeft size={14} />
                  </button>
                  <span>
                    Record {visibleRows.length === 0 ? 0 : selectedIndex + 1} of {visibleRows.length}
                  </span>
                  <button
                    type="button"
                    disabled={selectedIndex < 0 || selectedIndex >= visibleRows.length - 1}
                    onClick={() => moveSelection(selectedIndex + 1)}
                  >
                    <ChevronRight size={14} />
                  </button>
                  <button
                    type="button"
                    disabled={visibleRows.length === 0}
                    onClick={() => moveSelection(visibleRows.length - 1)}
                  >
                    <ChevronLast size={14} />
                  </button>
                  <button type="button" title="Add row" onClick={addTypeRow}>
                    <Plus size={14} />
                  </button>
                  <button type="button" title="Remove unsaved row" onClick={removeSelectedDraft}>
                    <XCircle size={14} />
                  </button>
                </div>

                <div className="coa-modal-actions">
                  <button
                    type="button"
                    data-tone="refresh"
                    disabled={saving}
                    onClick={() => void reloadLists(selectedRow?.ulid).catch((err) => setError(errMsg(err)))}
                  >
                    <RefreshCw size={16} /> Refresh
                  </button>
                  <button type="submit" data-tone="save" disabled={saving || !selectedRow}>
                    <Save size={16} /> Save
                  </button>
                  <button type="button" data-tone="close" onClick={onClose}>
                    <X size={16} /> Close
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>

      {layer === 'sub' || layer === 'main' ? (
        <div className="coa-modal-backdrop" style={{ zIndex: 5300 }}>
          <div className="coa-modal" role="dialog" aria-label="Sub Account Edit/New">
            <div className="coa-modal-bar">
              <span>Sub Account Edit/New</span>
              <button type="button" onClick={() => { setError(null); setLayer('type') }} aria-label="Close">
                <X size={14} />
              </button>
            </div>
            <form className="coa-modal-body" onSubmit={(e) => void saveSub(e)}>
              {error && layer === 'sub' ? <div className="coa-modal-error">{error}</div> : null}
              <div className="coa-modal-split">
                <div className="coa-modal-form">
                  <label>
                    Sub Head Name
                    <input value={subName} onChange={(e) => setSubName(e.target.value)} autoFocus={layer === 'sub'} />
                  </label>
                  <label className="coa-with-plus">
                    Main Head
                    <span>
                      <UiSelect
                        value={subMainUlid}
                        aria-label="Main Head"
                        menuZIndex={5350}
                        options={[
                          { value: '', label: 'Select…' },
                          ...mains
                            .filter((m) => m.is_active || m.ulid === subMainUlid)
                            .map((m) => ({ value: m.ulid, label: m.name })),
                        ]}
                        onChange={setSubMainUlid}
                      />
                      <button
                        type="button"
                        className="coa-plus"
                        title="Add Main Head"
                        aria-label="Add Main Head"
                        onClick={() => {
                          setError(null)
                          resetMainForm()
                          if (subMainUlid) {
                            const current = mains.find((m) => m.ulid === subMainUlid)
                            if (current) applyMain(current)
                          }
                          setLayer('main')
                        }}
                      >
                        <Plus size={15} strokeWidth={3} />
                      </button>
                    </span>
                  </label>
                  <div className="coa-inline-2">
                    <label>
                      Sort Order
                      <input value={subSort} onChange={(e) => setSubSort(e.target.value)} />
                    </label>
                    <ToggleSwitch label="Active" tone="active" checked={subActive} onChange={setSubActive} />
                  </div>
                </div>
                <div className="coa-modal-list-wrap">
                  <table className="coa-modal-list">
                    <thead>
                      <tr>
                        <th>Sub Head</th>
                        <th>Main Head</th>
                      </tr>
                    </thead>
                    <tbody>
                      {subs.length === 0 ? (
                        <tr><td colSpan={2} className="coa-empty">No sub heads</td></tr>
                      ) : (
                        subs.map((row) => (
                          <tr
                            key={row.ulid}
                            className={row.ulid === subUlid ? 'is-selected' : undefined}
                            onClick={() => applySub(row)}
                          >
                            <td>{row.name}</td>
                            <td>{row.main_head?.name ?? '—'}</td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
              <div className="coa-modal-actions">
                <button type="submit" data-tone="save" disabled={saving || layer !== 'sub'}>
                  <Save size={16} /> Save
                </button>
                <button
                  type="button"
                  data-tone="refresh"
                  disabled={saving || layer !== 'sub'}
                  onClick={() => {
                    resetSubForm(true)
                    void fetchSubHeads().then(setSubs).catch((err) => setError(errMsg(err)))
                  }}
                >
                  <RefreshCw size={16} /> Refresh
                </button>
                <button type="button" data-tone="close" onClick={() => { setError(null); setLayer('type') }}>
                  <X size={16} /> Close
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}

      {layer === 'main' ? (
        <div className="coa-modal-backdrop" style={{ zIndex: 5400 }}>
          <div className="coa-modal coa-modal-narrow" role="dialog" aria-label="Add/Edit Main Record">
            <div className="coa-modal-bar">
              <span>Add/Edit Main Record</span>
              <button type="button" onClick={() => { setError(null); setLayer('sub') }} aria-label="Close">
                <X size={14} />
              </button>
            </div>
            <form className="coa-modal-body" onSubmit={(e) => void saveMain(e)}>
              {error ? <div className="coa-modal-error">{error}</div> : null}
              <div className="coa-modal-split coa-modal-split-stack">
                <div className="coa-modal-form">
                  <label>
                    Main Head
                    <input value={mainName} onChange={(e) => setMainName(e.target.value)} autoFocus />
                  </label>
                  <div className="coa-inline-2">
                    <label>
                      Sort Order
                      <input value={mainSort} onChange={(e) => setMainSort(e.target.value)} />
                    </label>
                    <ToggleSwitch label="Active" tone="active" checked={mainActive} onChange={setMainActive} />
                  </div>
                </div>
                <div className="coa-modal-list-wrap">
                  <table className="coa-modal-list">
                    <thead>
                      <tr>
                        <th>Main Head</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {mains.length === 0 ? (
                        <tr><td colSpan={2} className="coa-empty">No main heads</td></tr>
                      ) : (
                        mains.map((row) => (
                          <tr
                            key={row.ulid}
                            className={row.ulid === mainUlid ? 'is-selected' : undefined}
                            onClick={() => applyMain(row)}
                          >
                            <td>{row.name}</td>
                            <td>{row.is_active ? 'Active' : 'Off'}</td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
              <div className="coa-modal-actions">
                <button type="submit" data-tone="save" disabled={saving}>
                  <Save size={16} /> Save
                </button>
                <button type="button" data-tone="close" onClick={() => { setError(null); setLayer('sub') }}>
                  <X size={16} /> Close
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </>
  )
}
