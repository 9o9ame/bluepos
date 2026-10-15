import { useCallback, useEffect, useMemo } from 'react'
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
) {
  const queryClient = useQueryClient()
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
    void refreshProducts(scope)
      .then((rows) => {
        if (!active) return
        queryClient.setQueryData(['local-catalog', 'products', scopeKey], rows)
      })
      .catch(() => undefined)

    return () => {
      active = false
    }
  }, [enabled, queryClient, scopeKey, scope?.branchUlid, scope?.tenantUlid, scope?.warehouseUlid])

  const refreshFromServer = useCallback(async () => {
    if (!scope) return []

    const fresh = await refreshProducts(scope, { force: true })
    queryClient.setQueryData(['local-catalog', 'products', scopeKey], fresh)
    return fresh
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
