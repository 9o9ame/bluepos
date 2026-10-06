export type SaleReturnRefund = {
  ulid: string
  method: 'cash' | 'card' | 'bank' | 'credit'
  amount: string
  reference: string | null
  journal_entry_ulid: string | null
  account?: {
    ulid: string
    code: string
    name: string
  } | null
  created_at: string | null
}

export type SaleReturnStatus = 'draft' | 'posted'

export type SaleReturnRef = {
  ulid: string
  code: string
  name: string
}

export type SaleReturnProductRef = {
  ulid: string
  product_number: string
  name: string
  category?: {
    ulid: string
    name: string
  } | null
}

export type SaleReturnLine = {
  ulid: string
  original_sale_item_ulid: string | null
  line_kind: 'sale' | 'free_packaging' | 'free_scheme'
  barcode: string | null
  quantity: string
  conversion_factor: string
  stock_quantity: string
  unit_price: string
  gross_amount: string
  discount_amount: string
  tax_amount: string
  line_total: string
  reason: string | null
  notes: string | null
  product?: SaleReturnProductRef | null
  unit?: {
    ulid: string
    code: string
    name: string
  } | null
}

export type SaleReturn = {
  ulid: string
  document_number: string
  return_date: string
  status: SaleReturnStatus
  subtotal: string
  discount_amount: string
  tax_amount: string
  grand_total: string
  refund_amount: string
  balance_due: string
  reason: string | null
  notes: string | null
  posted_at: string | null
  refunds?: SaleReturnRefund[]
  original_sale?: {
    ulid: string
    document_number: string
    sale_date: string
    grand_total: string
  } | null
  customer?: SaleReturnRef | null
  salesman?: SaleReturnRef | null
  branch?: SaleReturnRef | null
  warehouse?: SaleReturnRef | null
  lines?: SaleReturnLine[]
}

export type ReturnableSaleLine = {
  sale_item_ulid: string
  line_kind: 'sale' | 'free_packaging' | 'free_scheme'
  product: SaleReturnProductRef
  unit: {
    ulid: string
    code: string
    name: string
  } | null
  original_quantity: string
  already_returned_quantity: string
  remaining_returnable_quantity: string
  conversion_factor: string
  unit_price: string
  gross_amount: string
  discount_amount: string
  tax_amount: string
  line_total: string
}

export type ReturnableSaleResponse = {
  sale: {
    ulid: string
    document_number: string
    sale_date: string
    grand_total: string
    previous_balance: string
    customer: SaleReturnRef | null
    salesman: SaleReturnRef | null
  }
  data: ReturnableSaleLine[]
}

export type SaleReturnProductWiseRow = {
  ulid: string
  return_ulid: string
  return_number: string
  return_date: string
  customer: { ulid: string; name: string } | null
  salesman: { ulid: string; name: string } | null
  product: {
    ulid: string
    product_number: string
    name: string
  }
  category: { ulid: string; name: string } | null
  quantity_in: string
  quantity_out: string
  amount: string
}
