export type PurchaseStatus = 'draft' | 'posted' | 'cancelled'

export type PurchaseRef = {
  ulid: string
  code: string
  name: string
}

export type PurchaseProductRef = {
  ulid: string
  product_number: string
  sku: string | null
  name: string
  track_batch?: boolean
  track_expiry?: boolean
}

export type PurchaseUnitRef = {
  ulid: string
  code: string
  name: string
}

export type PurchaseInvoiceLine = {
  ulid: string
  quantity: string
  conversion_factor: string
  base_quantity: string
  unit_cost: string
  discount_amount: string
  tax_amount: string
  line_total: string
  supplier_product_code: string | null
  batch_number: string | null
  expiry_date: string | null
  notes: string | null
  product?: PurchaseProductRef | null
  unit?: PurchaseUnitRef | null
}

export type PurchaseInvoice = {
  ulid: string
  document_number: string
  supplier_invoice_number: string | null
  invoice_date: string
  due_date: string | null
  status: PurchaseStatus
  subtotal: string
  discount_amount: string
  tax_amount: string
  freight_amount: string
  other_charges: string
  grand_total: string
  notes: string | null
  posted_at: string | null
  supplier?: PurchaseRef | null
  branch?: PurchaseRef | null
  warehouse?: PurchaseRef | null
  lines?: PurchaseInvoiceLine[]
}
