import { FormEvent, useEffect, useState } from 'react'
import { RefreshCw, Save, X } from 'lucide-react'
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
import './CoaHierarchyModals.css'

type Props = {
  open: boolean
  selectedAccountTypeUlid: string | null
  onClose: () => void
  onAccountTypeSaved: (type: CoaAccountType) => void
  onHierarchyChanged: () => void
}

function errMsg(err: unknown): string {
  if (err instanceof ApiClientError) return err.message
  if (err instanceof Error) return err.message
  return 'Request failed'
}

export function CoaHierarchyModals({
  open,
  selectedAccountTypeUlid,
  onClose,
  onAccountTypeSaved,
  onHierarchyChanged,
}: Props) {
  const [layer, setLayer] = useState<'type' | 'sub' | 'main'>('type')
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const [types, setTypes] = useState<CoaAccountType[]>([])
  const [subs, setSubs] = useState<CoaSubHead[]>([])
  const [mains, setMains] = useState<CoaMainHead[]>([])

  const [typeUlid, setTypeUlid] = useState<string | null>(null)
  const [typeName, setTypeName] = useState('')
  const [typeSubUlid, setTypeSubUlid] = useState('')
  const [isCash, setIsCash] = useState(false)
  const [isBank, setIsBank] = useState(false)
  const [isRec, setIsRec] = useState(false)
  const [isPay, setIsPay] = useState(false)
  const [pnlLabel, setPnlLabel] = useState('')
  const [hint, setHint] = useState('')
  const [typeSort, setTypeSort] = useState('0')
  const [typeActive, setTypeActive] = useState(true)

  const [subUlid, setSubUlid] = useState<string | null>(null)
  const [subName, setSubName] = useState('')
  const [subMainUlid, setSubMainUlid] = useState('')
  const [subSort, setSubSort] = useState('0')
  const [subActive, setSubActive] = useState(true)

  const [mainUlid, setMainUlid] = useState<string | null>(null)
  const [mainName, setMainName] = useState('')
  const [mainSort, setMainSort] = useState('0')
  const [mainActive, setMainActive] = useState(true)

  async function reloadLists(selectTypeUlid?: string | null) {
    const [t, s, m] = await Promise.all([fetchAccountTypes(), fetchSubHeads(), fetchMainHeads()])
    setTypes(t)
    setSubs(s)
    setMains(m)
    const pick = selectTypeUlid ?? selectedAccountTypeUlid
    if (pick) {
      const found = t.find((row) => row.ulid === pick)
      if (found) applyType(found)
    }
  }

  function applyType(row: CoaAccountType) {
    setTypeUlid(row.ulid)
    setTypeName(row.name)
    setTypeSubUlid(row.sub_head?.ulid ?? row.sub_head_ulid ?? '')
    setIsCash(row.is_cash)
    setIsBank(row.is_bank)
    setIsRec(row.is_receivable)
    setIsPay(row.is_payable)
    setPnlLabel(row.pnl_grouping_label ?? '')
    setHint(row.hint ?? '')
    setTypeSort(String(row.sort_order ?? 0))
    setTypeActive(row.is_active)
  }

  function resetTypeForm(keepSub = true) {
    setTypeUlid(null)
    setTypeName('')
    if (!keepSub) setTypeSubUlid('')
    setIsCash(false)
    setIsBank(false)
    setIsRec(false)
    setIsPay(false)
    setPnlLabel('')
    setHint('')
    setTypeSort('0')
    setTypeActive(true)
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

  useEffect(() => {
    if (!open) return
    setLayer('type')
    setError(null)
    resetTypeForm(false)
    void reloadLists(selectedAccountTypeUlid).catch((err) => setError(errMsg(err)))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  if (!open) return null

  async function saveType(e: FormEvent) {
    e.preventDefault()
    if (!typeName.trim() || !typeSubUlid) {
      setError('Description and Sub Head are required.')
      return
    }
    setSaving(true)
    setError(null)
    const payload = {
      sub_head_ulid: typeSubUlid,
      name: typeName.trim(),
      is_cash: isCash,
      is_bank: isBank,
      is_receivable: isRec,
      is_payable: isPay,
      pnl_grouping_label: pnlLabel.trim() || null,
      hint: hint.trim() || null,
      sort_order: Number(typeSort) || 0,
      is_active: typeActive,
    }
    try {
      const saved = typeUlid
        ? await updateAccountType(typeUlid, payload)
        : await createAccountType(payload)
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
      setTypeSubUlid(saved.ulid)
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
      {layer === 'type' ? (
        <div className="coa-modal-backdrop" style={{ zIndex: 5200 }}>
          <div className="coa-modal coa-modal-wide" role="dialog" aria-label="Account Type Definition">
            <div className="coa-modal-bar">
              <span>Account Type Definition</span>
              <button type="button" onClick={onClose} aria-label="Close">
                <X size={14} />
              </button>
            </div>
            <form className="coa-modal-body" onSubmit={(e) => void saveType(e)}>
              {error ? <div className="coa-modal-error">{error}</div> : null}
              <div className="coa-modal-split">
                <div className="coa-modal-form">
                  <label>
                    Description
                    <input value={typeName} onChange={(e) => setTypeName(e.target.value)} autoFocus />
                  </label>
                  <div className="coa-flag-row">
                    <label><input type="checkbox" checked={isCash} onChange={(e) => setIsCash(e.target.checked)} /> Cash</label>
                    <label><input type="checkbox" checked={isBank} onChange={(e) => setIsBank(e.target.checked)} /> Bank</label>
                    <label><input type="checkbox" checked={isRec} onChange={(e) => setIsRec(e.target.checked)} /> Rec</label>
                    <label><input type="checkbox" checked={isPay} onChange={(e) => setIsPay(e.target.checked)} /> Pay</label>
                  </div>
                  <label className="coa-with-plus">
                    Sub Head Account
                    <span>
                      <select value={typeSubUlid} onChange={(e) => setTypeSubUlid(e.target.value)}>
                        <option value="">Select…</option>
                        {subs.filter((s) => s.is_active || s.ulid === typeSubUlid).map((s) => (
                          <option key={s.ulid} value={s.ulid}>
                            {s.name}{s.main_head ? ` (${s.main_head.name})` : ''}
                          </option>
                        ))}
                      </select>
                      <button
                        type="button"
                        className="coa-plus"
                        title="Add Sub Head"
                        onClick={() => {
                          setError(null)
                          resetSubForm(true)
                          if (typeSubUlid) {
                            const current = subs.find((s) => s.ulid === typeSubUlid)
                            if (current) applySub(current)
                          }
                          setLayer('sub')
                        }}
                      >
                        +
                      </button>
                    </span>
                  </label>
                  <label>
                    P &amp; L Statement Grouping Label
                    <input value={pnlLabel} onChange={(e) => setPnlLabel(e.target.value)} />
                  </label>
                  <label>
                    Hint
                    <input value={hint} onChange={(e) => setHint(e.target.value)} />
                  </label>
                  <div className="coa-inline-2">
                    <label>
                      Sort Order
                      <input value={typeSort} onChange={(e) => setTypeSort(e.target.value)} />
                    </label>
                    <label className="coa-check-inline">
                      <input type="checkbox" checked={typeActive} onChange={(e) => setTypeActive(e.target.checked)} />
                      Active
                    </label>
                  </div>
                </div>
                <div className="coa-modal-list-wrap">
                  <table className="coa-modal-list">
                    <thead>
                      <tr>
                        <th>Description</th>
                        <th>Sub Head</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {types.length === 0 ? (
                        <tr><td colSpan={3} className="coa-empty">No account types</td></tr>
                      ) : (
                        types.map((row) => (
                          <tr
                            key={row.ulid}
                            className={row.ulid === typeUlid ? 'is-selected' : undefined}
                            onClick={() => applyType(row)}
                          >
                            <td>{row.name}</td>
                            <td>{row.sub_head?.name ?? '—'}</td>
                            <td>{row.is_active ? 'Active' : 'Off'}</td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
              <div className="coa-modal-actions">
                <button type="submit" disabled={saving}><Save size={14} /> Save</button>
                <button
                  type="button"
                  disabled={saving}
                  onClick={() => {
                    resetTypeForm(true)
                    void reloadLists(null).catch((err) => setError(errMsg(err)))
                  }}
                >
                  <RefreshCw size={14} /> Refresh
                </button>
                <button type="button" onClick={onClose}><X size={14} /> Close</button>
              </div>
            </form>
          </div>
        </div>
      ) : null}

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
                      <select value={subMainUlid} onChange={(e) => setSubMainUlid(e.target.value)}>
                        <option value="">Select…</option>
                        {mains.filter((m) => m.is_active || m.ulid === subMainUlid).map((m) => (
                          <option key={m.ulid} value={m.ulid}>{m.name}</option>
                        ))}
                      </select>
                      <button
                        type="button"
                        className="coa-plus"
                        title="Add Main Head"
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
                        +
                      </button>
                    </span>
                  </label>
                  <div className="coa-inline-2">
                    <label>
                      Sort Order
                      <input value={subSort} onChange={(e) => setSubSort(e.target.value)} />
                    </label>
                    <label className="coa-check-inline">
                      <input type="checkbox" checked={subActive} onChange={(e) => setSubActive(e.target.checked)} />
                      Active
                    </label>
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
                <button type="submit" disabled={saving || layer !== 'sub'}><Save size={14} /> Save</button>
                <button
                  type="button"
                  disabled={saving || layer !== 'sub'}
                  onClick={() => {
                    resetSubForm(true)
                    void fetchSubHeads().then(setSubs).catch((err) => setError(errMsg(err)))
                  }}
                >
                  <RefreshCw size={14} /> Refresh
                </button>
                <button type="button" onClick={() => { setError(null); setLayer('type') }}>
                  <X size={14} /> Close
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
                    <label className="coa-check-inline">
                      <input type="checkbox" checked={mainActive} onChange={(e) => setMainActive(e.target.checked)} />
                      Active
                    </label>
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
                <button type="submit" disabled={saving}><Save size={14} /> Save</button>
                <button type="button" onClick={() => { setError(null); setLayer('sub') }}>
                  <X size={14} /> Close
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </>
  )
}
