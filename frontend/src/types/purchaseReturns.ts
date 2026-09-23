export type PurchaseReturnStatus = 'draft' | 'posted'

export type PurchaseReturnRef = {
  ulid: string
  code: string
  name: string
}

export type PurchaseReturnProductRef = {
  ulid: string
  product_number: string
  sku: string | null
  name: string
  track_batch?: boolean
  track_expiry?: boolean
}

export type PurchaseReturnUnitRef = {
  ulid: string
  code: string
  name: string
}

export type PurchaseReturnLine = {
  ulid: string
  original_purchase_line_ulid?: string | null
  quantity: string
  conversion_factor: string
  base_quantity: string
  unit_cost: string
  discount_amount: string
  tax_amount: string
  line_total: string
  batch_number: string | null
  expiry_date: string | null
  reason: string | null
  notes: string | null
  product?: PurchaseReturnProductRef | null
  unit?: PurchaseReturnUnitRef | null
}

export type PurchaseReturn = {
  ulid: string
  document_number: string
  return_date: string
  status: PurchaseReturnStatus
  subtotal: string
  discount_amount: string
  tax_amount: string
  grand_total: string
  supplier_reference: string | null
  reason: string | null
  notes: string | null
  posted_at: string | null
  original_purchase?: {
    ulid: string
    document_number: string
    supplier_invoice_number: string | null
  } | null
  supplier?: PurchaseReturnRef | null
  warehouse?: PurchaseReturnRef | null
  branch?: PurchaseReturnRef | null
  lines?: PurchaseReturnLine[]
}

export type ReturnablePurchaseLine = {
  purchase_line_ulid: string
  product: PurchaseReturnProductRef
  unit: PurchaseReturnUnitRef
  original_quantity: string
  original_base_quantity: string
  already_returned_base_quantity: string
  remaining_returnable_base_quantity: string
  unit_cost: string
  conversion_factor: string
  batch_number: string | null
  expiry_date: string | null
}
