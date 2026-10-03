export type SaleLineKind = 'sale' | 'free_packaging' | 'free_scheme'

export type SalePaymentMethod = 'cash' | 'card' | 'bank' | 'credit'

export type SalePayment = {
  ulid: string
  method: SalePaymentMethod
  amount: string
  reference: string | null
  journal_entry_ulid: string | null
  created_at: string | null
}

export type SaleItem = {
  ulid: string
  line_kind: SaleLineKind
  quantity: string
  unit_price: string
  discount_amount: string
  tax_amount: string
  line_total: string
  notes: string | null
  product?: {
    ulid: string
    name: string
    product_number: string
  } | null
  unit?: { ulid: string; code: string } | null
  sale_scheme?: { ulid: string; name: string } | null
}

export type Sale = {
  ulid: string
  document_number: string
  status: string
  sale_date: string
  subtotal: string
  discount_amount: string
  tax_amount: string
  grand_total: string
  notes: string | null
  posted_at: string | null
  customer: { ulid: string; code: string; name: string } | null
  branch: { ulid: string; code: string; name: string }
  warehouse: { ulid: string; code: string; name: string }
  items: SaleItem[]
  payments?: SalePayment[]
}

/** What the salesman picks at the prompt; the server still decides the truth. */
export type SaleDraftLine = {
  product_ulid: string
  product_name: string
  product_number: string
  quantity: string
  line_kind: SaleLineKind
  /** Only set on free_scheme lines, used for the Skip/Add bookkeeping. */
  scheme_ulid?: string
  /** Client-side PREVIEW price (retail); the server price wins on save. */
  unit_price?: string
  tax_percent?: string
}

export type SalePayload = {
  items: Array<{ product_ulid: string; quantity: string }>
  free_lines?: Array<{
    product_ulid: string
    qty: string
    line_kind: 'free_packaging'
  }>
  applied_scheme_ulids?: string[]
  customer_ulid?: string | null
  notes?: string | null
  sale_date?: string | null
}
