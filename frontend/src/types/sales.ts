export type SaleLineKind = 'sale' | 'free_packaging' | 'free_scheme'

export type SalePaymentMethod = 'cash' | 'card' | 'bank' | 'credit'

/** UI/API sale price mode. "default" currently resolves to retail on the server. */
export type SalePriceType = 'default' | 'retail' | 'wholesale'

export type SalesmanOption = {
  ulid: string
  code: string
  name: string
  address: string | null
  mobile: string | null
}

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
  stock_quantity: string
  barcode: string | null
  conversion_factor: string
  price_type: 'retail' | 'wholesale' | null
  unit_price: string
  gross_amount: string
  discount_percent: string
  discount_amount: string
  tax_percent: string
  tax_amount: string
  net_amount: string
  line_total: string
  notes: string | null
  product?: {
    ulid: string
    name: string
    product_number: string
  } | null
  unit?: {
    ulid: string
    code: string
    name: string
    symbol: string
    allows_decimal: boolean
  } | null
  sale_scheme?: {
    ulid: string
    name: string
  } | null
}

export type Sale = {
  ulid: string
  document_number: string
  status: string
  sale_date: string
  price_type: 'retail' | 'wholesale' | null
  subtotal: string
  discount_amount: string
  tax_amount: string
  grand_total: string
  returned_amount: string
  net_sale_total: string
  paid_amount: string
  balance_due: string
  notes: string | null
  posted_at: string | null
  salesman?: SalesmanOption | null
  customer: {
    ulid: string
    code: string
    name: string
  } | null
  branch: {
    ulid: string
    code: string
    name: string
  }
  warehouse: {
    ulid: string
    code: string
    name: string
  }
  items: SaleItem[]
  payments?: SalePayment[]
}

export type SaleDraftUnitOption = {
  unit_ulid: string
  code: string
  name: string
  symbol: string
  allows_decimal: boolean
  conversion_factor: string
  /** If this option came from a barcode, preserve the barcode with the unit. */
  barcode?: string | null
}

/** What the salesman picks; the server remains the source of truth. */
export type SaleDraftLine = {
  /** Client-only key so the same product may exist in different units/barcodes. */
  line_key: string
  product_ulid: string
  product_name: string
  product_number: string
  quantity: string
  line_kind: SaleLineKind

  /** Only set on free_scheme lines. */
  scheme_ulid?: string

  /** Selected sale unit / barcode snapshot for the request and UI preview. */
  unit_ulid?: string
  unit_code?: string
  unit_name?: string
  unit_symbol?: string
  unit_allows_decimal?: boolean
  barcode?: string | null
  conversion_factor?: string
  available_units?: SaleDraftUnitOption[]

  /** Current active-warehouse stock in BASE units when this line was added. */
  available_base_stock?: string | null

  /** Base-unit price snapshots used only for the client preview. */
  retail_price?: string | null
  wholesale_price?: string | null

  /** Client-side PREVIEW price; the server price wins on save. */
  unit_price?: string

  /** Only one of these should be positive. The server validates again. */
  discount_percent?: string
  discount_amount?: string

  /** Client-side PREVIEW product tax percentage. Server product tax wins. */
  tax_percent?: string

  notes?: string | null
}


export type SaleHold = {
  ulid: string
  held_at: string | null
  sale_date: string | null
  price_type: SalePriceType
  notes: string | null
  sale_line_count: number
  customer: {
    ulid: string
    code: string
    name: string
  } | null
  salesman: SalesmanOption | null
  branch: {
    ulid: string
    code: string
    name: string
  }
  warehouse: {
    ulid: string
    code: string
    name: string
  }
  lines?: SaleDraftLine[]
}

export type SaleHoldPayload = {
  sale_date?: string | null
  customer_ulid?: string | null
  salesman_ulid?: string | null
  payment_due?: boolean
  notes?: string | null
  price_type: SalePriceType
  lines: Array<{
    product_ulid: string
    line_kind: SaleLineKind
    unit_ulid?: string | null
    scheme_ulid?: string | null
    barcode?: string | null
    quantity: string
    discount_percent?: string | null
    discount_amount?: string | null
    notes?: string | null
  }>
}

export type AppliedSaleScheme = {
  scheme_ulid: string
  qty: string
}

export type SalePayload = {
  price_type?: SalePriceType

  items: Array<{
    product_ulid?: string
    barcode?: string | null
    unit_ulid?: string | null
    quantity: string
    discount_percent?: string | null
    discount_amount?: string | null
    notes?: string | null
  }>

  free_lines?: Array<{
    product_ulid: string
    qty: string
    line_kind: 'free_packaging'
  }>

  /**
   * Schemes explicitly selected by the salesman.
   * The server validates eligibility and maximum quantity.
   */
  applied_schemes?: AppliedSaleScheme[]

  customer_ulid?: string | null
  salesman_ulid?: string | null
  notes?: string | null
  sale_date?: string | null
}
