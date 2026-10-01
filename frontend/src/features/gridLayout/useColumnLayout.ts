import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { fetchColumnPreferences, saveColumnPreferences } from '../../api/columnPreferences'
import {
  defaultColumnStates,
  mergeColumnLayout,
  toPersistedColumns,
  type GridColumnDef,
  type ResolvedGridColumn,
} from './columnCatalog'

type Options = {
  screenKey: string
  catalog: GridColumnDef[]
  enabled?: boolean
}

export function useColumnLayout({ screenKey, catalog, enabled = true }: Options) {
  const [columns, setColumns] = useState<ResolvedGridColumn[]>(() =>
    mergeColumnLayout(catalog, defaultColumnStates(catalog)),
  )
  const [loaded, setLoaded] = useState(false)
  const [scope, setScope] = useState<'user' | 'role' | 'default'>('default')
  const [roleUlid, setRoleUlid] = useState<string | null>(null)
  const saveTimer = useRef<number | null>(null)
  const latestRef = useRef(columns)
  latestRef.current = columns

  useEffect(() => {
    if (!enabled) return
    let cancelled = false
    void (async () => {
      try {
        const payload = await fetchColumnPreferences(screenKey)
        if (cancelled) return
        setScope(payload.scope)
        setRoleUlid(payload.role_ulid)
        setColumns(mergeColumnLayout(catalog, payload.columns.length ? payload.columns : null))
      } catch {
        if (!cancelled) {
          setColumns(mergeColumnLayout(catalog, null))
        }
      } finally {
        if (!cancelled) setLoaded(true)
      }
    })()
    return () => {
      cancelled = true
    }
  }, [catalog, enabled, screenKey])

  const persist = useCallback(
    (next: ResolvedGridColumn[], target: 'user' | 'role' = 'user') => {
      if (saveTimer.current) window.clearTimeout(saveTimer.current)
      saveTimer.current = window.setTimeout(() => {
        void saveColumnPreferences(screenKey, {
          scope: target,
          role_ulid: target === 'role' ? roleUlid : undefined,
          columns: toPersistedColumns(next),
        })
          .then((payload) => {
            setScope(payload.scope)
            setRoleUlid(payload.role_ulid)
          })
          .catch(() => {
            /* keep local layout; toast handled by caller if needed */
          })
      }, 350)
    },
    [roleUlid, screenKey],
  )

  const visibleColumns = useMemo(() => columns.filter((col) => col.visible), [columns])
  const hiddenColumns = useMemo(() => columns.filter((col) => !col.visible), [columns])

  const showColumn = useCallback(
    (key: string) => {
      setColumns((current) => {
        const next = current.map((col) => (col.key === key ? { ...col, visible: true } : col))
        persist(next, 'user')
        return next
      })
    },
    [persist],
  )

  const hideColumn = useCallback(
    (key: string) => {
      setColumns((current) => {
        const target = current.find((col) => col.key === key)
        if (!target || target.locked) return current
        const next = current.map((col) => (col.key === key ? { ...col, visible: false } : col))
        persist(next, 'user')
        return next
      })
    },
    [persist],
  )

  const toggleLock = useCallback(
    (key: string) => {
      setColumns((current) => {
        const def = catalog.find((col) => col.key === key)
        if (def && def.lockable === false) return current
        const next = current.map((col) => {
          if (col.key !== key) return col
          const locked = !col.locked
          return { ...col, locked, visible: locked ? true : col.visible }
        })
        persist(next, 'user')
        return next
      })
    },
    [catalog, persist],
  )

  const moveColumn = useCallback(
    (fromKey: string, toKey: string) => {
      if (fromKey === toKey) return
      setColumns((current) => {
        const from = current.find((col) => col.key === fromKey)
        const to = current.find((col) => col.key === toKey)
        if (!from || !to || from.locked || to.locked) return current
        const next = [...current]
        const fromIndex = next.findIndex((col) => col.key === fromKey)
        const toIndex = next.findIndex((col) => col.key === toKey)
        const [item] = next.splice(fromIndex, 1)
        next.splice(toIndex, 0, item)
        const ordered = next.map((col, index) => ({ ...col, position: index }))
        persist(ordered, 'user')
        return ordered
      })
    },
    [persist],
  )

  const resetToDefaults = useCallback(() => {
    const next = mergeColumnLayout(catalog, null)
    setColumns(next)
    persist(next, 'user')
  }, [catalog, persist])

  const saveAsRoleDefault = useCallback(async () => {
    const payload = await saveColumnPreferences(screenKey, {
      scope: 'role',
      role_ulid: roleUlid,
      columns: toPersistedColumns(latestRef.current),
    })
    setScope(payload.scope)
    setRoleUlid(payload.role_ulid)
  }, [roleUlid, screenKey])

  return {
    columns,
    visibleColumns,
    hiddenColumns,
    loaded,
    scope,
    roleUlid,
    showColumn,
    hideColumn,
    toggleLock,
    moveColumn,
    resetToDefaults,
    saveAsRoleDefault,
  }
}
