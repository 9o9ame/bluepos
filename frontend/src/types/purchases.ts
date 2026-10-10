export type PurchaseStatus = 'draft' | 'posted' | 'cancelled'

export type PurchasePaymentMethod = 'cash' | 'card' | 'bank'

export type PurchasePayment = {
  ulid: string
  method: PurchasePaymentMethod
  amount: string
  reference: string | null
  journal_entry_ulid: string
  created_at: string | null
}

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
  further_tax_amount?: string
  line_total: string
  supplier_product_code: string | null
  batch_number: string | null
  expiry_date: string | null
  notes: string | null
  brand_label?: string | null
  hs_code?: string | null
  pack_size?: string | null
  qty_ctn?: string
  free_pcs?: string
  price_type?: string
  mrp?: string
  trade_disc_pct?: string
  regular_disc_pct?: string
  special_disc_pct?: string
  tax_pct?: string
  further_tax_pct?: string
  product?: PurchaseProductRef | null
  unit?: PurchaseUnitRef | null
}

export type PurchaseInvoice = {
  ulid: string
  document_number: string
  supplier_invoice_number: string | null
  po_number?: string | null
  invoice_type?: string
  currency_code?: string
  calculation_method?: string
  default_sales_tax_pct?: string
  default_further_tax_pct?: string
  default_advance_tax_pct?: string
  default_price_type?: string
  brand_label?: string | null
  invoice_date: string
  due_date: string | null
  status: PurchaseStatus
  subtotal: string
  discount_amount: string
  tax_amount: string
  further_tax_amount?: string
  freight_amount: string
  loading_amount?: string
  other_charges: string
  other_discount?: string
  trade_offer?: string
  advance_tax_amount?: string
  round_off?: string
  grand_total: string
  paid_amount: string
  balance_payable: string
  notes: string | null
  tax_type?: string
  payment_terms?: string
  posted_at: string | null
  supplier?: PurchaseRef | null
  branch?: PurchaseRef | null
  warehouse?: PurchaseRef | null
  lines?: PurchaseInvoiceLine[]
  payments?: PurchasePayment[]
}

export type PurchaseHeaderPayload = {
  supplier_ulid: string
  warehouse_ulid: string
  invoice_date?: string
  due_date?: string | null
  supplier_invoice_number?: string | null
  po_number?: string | null
  invoice_type?: string
  currency_code?: string
  calculation_method?: string
  default_sales_tax_pct?: string
  default_further_tax_pct?: string
  default_advance_tax_pct?: string
  default_price_type?: string
  brand_label?: string | null
  freight_amount?: string
  loading_amount?: string
  other_charges?: string
  other_discount?: string
  trade_offer?: string
  advance_tax_amount?: string
  round_off?: string
  notes?: string | null
  tax_type?: string
  payment_terms?: string
}

export type PurchaseLinePayload = {
  product_ulid: string
  unit_ulid: string
  quantity: string
  conversion_factor?: string
  unit_cost: string
  discount_amount?: string
  tax_amount?: string
  supplier_product_code?: string | null
  batch_number?: string | null
  expiry_date?: string | null
  notes?: string | null
  brand_label?: string | null
  hs_code?: string | null
  pack_size?: string | null
  qty_ctn?: string
  free_pcs?: string
  price_type?: string
  mrp?: string
  trade_disc_pct?: string
  regular_disc_pct?: string
  special_disc_pct?: string
  tax_pct?: string
  further_tax_pct?: string
}


export type PurchaseOrderStatus = 'open' | 'closed' | 'cancelled'

export type PurchaseOrderLine = {
  ulid: string
  quantity: string
  conversion_factor: string
  base_quantity: string
  unit_price: string
  gross_amount: string
  discount_percent: string
  discount_amount: string
  line_total: string
  notes: string | null
  product?: PurchaseProductRef | null
  unit?: PurchaseUnitRef | null
}

export type PurchaseOrder = {
  ulid: string
  document_number: string
  order_date: string
  status: PurchaseOrderStatus
  subtotal: string
  discount_amount: string
  grand_total: string
  notes: string | null
  supplier?: PurchaseRef | null
  branch?: PurchaseRef | null
  warehouse?: PurchaseRef | null
  items?: PurchaseOrderLine[]
}

export type PurchaseOrderItemPayload = {
  product_ulid: string
  unit_ulid: string
  quantity: string
  unit_price: string
  discount_percent?: string
  discount_amount?: string
  notes?: string | null
}

export type PurchaseOrderPayload = {
  supplier_ulid: string
  order_date?: string
  notes?: string | null
  items: PurchaseOrderItemPayload[]
}


export type PurchaseOrderGenerateMode =
  | 'last_n_days'
  | 'between_dates'
  | 'reorder_level'
  | 'min_level'
  | 'max_level'
  | 'optimum_level'
  | 'get_all'

export type PurchaseOrderGenerateRow = {
  product: {
    ulid: string
    product_number: string
    name: string
  }
  unit: PurchaseUnitRef | null
  brand: { ulid: string; name: string } | null
  category: { ulid: string; name: string } | null
  in_stock: string
  stock_value: string
  consumption: string
  difference: string
  suggested_quantity: string
  unit_price: string
}

export type PurchaseOrderGeneratePayload = {
  mode: PurchaseOrderGenerateMode
  days?: number
  date_from?: string
  date_to?: string
  supplier_ulid?: string
  category_ulid?: string
  brand_ulid?: string
  include_non_sold?: boolean
}
