import { useCallback, useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import type { AuthSession } from '../../types/auth'
import {
  catalogScopeFromSession,
  filterCachedProducts,
  readCachedProducts,
  readCachedSuppliers,
  refreshProducts,
  refreshSuppliers,
  type CatalogScope,
} from '../../data/catalogCache'

function scopeFromSession(session: AuthSession | null): CatalogScope | null {
  return session ? catalogScopeFromSession(session) : null
}

export function useLocalProducts(
  session: AuthSession | null,
  query: string,
  enabled = true,
  backgroundSync = false,
) {
  const queryClient = useQueryClient()
  const [isBackgroundRefreshing, setIsBackgroundRefreshing] = useState(false)
  const scope = scopeFromSession(session)
  const scopeKey = scope
    ? [scope.tenantUlid, scope.branchUlid, scope.warehouseUlid].join(':')
    : 'none'

  const productsQuery = useQuery({
    queryKey: ['local-catalog', 'products', scopeKey],
    queryFn: async () => {
      if (!scope) return []
      const cached = await readCachedProducts(scope)
      if (cached.length > 0) return cached
      return refreshProducts(scope, { force: true })
    },
    enabled: enabled && Boolean(scope),
    staleTime: Infinity,
  })

  useEffect(() => {
    if (!enabled || !scope) return

    let active = true
    let timer: number | null = null

    const sync = async (force: boolean) => {
      if (active) setIsBackgroundRefreshing(true)
      try {
        const rows = await refreshProducts(scope, force ? { force: true } : undefined)
        if (!active) return
        queryClient.setQueryData(['local-catalog', 'products', scopeKey], rows)
      } catch {
        // Cached catalogue remains usable when the background sync fails.
      } finally {
        if (active) setIsBackgroundRefreshing(false)
      }
    }

    void sync(backgroundSync)

    if (backgroundSync) {
      timer = window.setInterval(() => {
        void sync(true)
      }, 5 * 60 * 1000)
    }

    return () => {
      active = false
      if (timer !== null) {
        window.clearInterval(timer)
      }
    }
  }, [
    backgroundSync,
    enabled,
    queryClient,
    scopeKey,
    scope?.branchUlid,
    scope?.tenantUlid,
    scope?.warehouseUlid,
  ])

  const refreshFromServer = useCallback(async () => {
    if (!scope) return []

    setIsBackgroundRefreshing(true)
    try {
      const fresh = await refreshProducts(scope, { force: true })
      queryClient.setQueryData(['local-catalog', 'products', scopeKey], fresh)
      return fresh
    } finally {
      setIsBackgroundRefreshing(false)
    }
  }, [
    queryClient,
    scopeKey,
    scope?.branchUlid,
    scope?.tenantUlid,
    scope?.warehouseUlid,
  ])

  const rows = useMemo(
    () => filterCachedProducts(productsQuery.data ?? [], query),
    [productsQuery.data, query],
  )

  return {
    ...productsQuery,
    rows,
    total: productsQuery.data?.length ?? 0,
    scope,
    refreshFromServer,
    isBackgroundRefreshing,
  }
}

export function useLocalSuppliers(
  session: AuthSession | null,
  enabled = true,
) {
  const queryClient = useQueryClient()
  const scope = scopeFromSession(session)
  const scopeKey = scope?.tenantUlid ?? 'none'

  const suppliersQuery = useQuery({
    queryKey: ['local-catalog', 'suppliers', scopeKey],
    queryFn: async () => {
      if (!scope) return []
      const cached = await readCachedSuppliers(scope)
      if (cached.length > 0) return cached
      return refreshSuppliers(scope, { force: true })
    },
    enabled: enabled && Boolean(scope),
    staleTime: Infinity,
  })

  useEffect(() => {
    if (!enabled || !scope) return

    let active = true
    void refreshSuppliers(scope)
      .then((rows) => {
        if (!active) return
        queryClient.setQueryData(['local-catalog', 'suppliers', scopeKey], rows)
      })
      .catch(() => undefined)

    return () => {
      active = false
    }
  }, [enabled, queryClient, scopeKey, scope?.tenantUlid])

  return {
    ...suppliersQuery,
    rows: suppliersQuery.data ?? [],
    scope,
  }
}
