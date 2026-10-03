import { apiFetch } from './client'
import type { SaleOfferEvaluation, SaleScheme } from '../types/saleSchemes'

export function fetchSaleSchemes() {
  return apiFetch<SaleScheme[]>('/api/sale-schemes')
}

export function createSaleScheme(payload: Record<string, unknown>) {
  return apiFetch<SaleScheme>('/api/sale-schemes', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateSaleScheme(ulid: string, payload: Record<string, unknown>) {
  return apiFetch<SaleScheme>(`/api/sale-schemes/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deactivateSaleScheme(ulid: string) {
  return apiFetch<{ ok: boolean; archived: boolean }>(`/api/sale-schemes/${ulid}`, {
    method: 'DELETE',
  })
}

export function evaluateSaleOffers(payload: { subtotal: string; document_date?: string }) {
  return apiFetch<SaleOfferEvaluation>('/api/sale-offers/evaluate', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}
