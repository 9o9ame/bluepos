export type CatalogItem = {
  ulid: string
  code: string
  name: string
  description: string | null
  is_active: boolean
  sort_order?: number
}

export type Subcategory = CatalogItem & {
  category_ulid?: string | null
  category?: CatalogItem | null
}

export type Unit = {
  ulid: string
  code: string
  name: string
  symbol: string
  allows_decimal: boolean
  is_active: boolean
}

export type Brand = CatalogItem

export type BarcodeGroup = CatalogItem

export type Supplier = {
  ulid: string
  code: string
  name: string
  contact_person: string | null
  phone: string | null
  mobile?: string | null
  mobile_secondary?: string | null
  phone_secondary?: string | null
  email: string | null
  backup_email: string | null
  software_options: Partial<SoftwareOptions> | Record<string, never>
  address: string | null
  billing_address?: string | null
  deals_in?: string | null
  tax_number: string | null
  notes: string | null
  is_active: boolean
}

export type PrimarySupplier = {
  ulid: string
  code: string
  name: string
  is_active: boolean
}

export type SoftwareOptions = {
  sale: {
    expiry_warning_days: number
    show_receiving_window: boolean
    manual_date_change: boolean
    keep_pending_invoice_date: boolean
    print_dotted_line: boolean
    dont_round_off: boolean
    message: string | null
    print_heading: string | null
    show_remarks: boolean
    show_discount_percent: boolean
    show_discount_amount: boolean
    show_previous_rates: boolean
    amount_editable: boolean
    amount_change_updates_qty: boolean
    disable_txt_id: boolean
    fbr_message: string | null
    show_qty_option_discount: boolean
    invoice_on: boolean
    default_yes: boolean
    disallow_duplicate_products: boolean
  }
  purchase: {
    show_expiry_batch: boolean
    show_bonus: boolean
    show_sales_tax: boolean
    show_rate_change_window: boolean
    show_vehicle_bilty: boolean
    amount_editable: boolean
    show_stock_taking: boolean
    stock_taking_method: 'full' | 'simple' | 'individual'
    default_vendor_ulid: string | null
    default_customer_ulid: string | null
    po_heading: string | null
  }
  general: {
    view_invoices_days: number
    view_history_days: number
    show_purchase_qty: boolean
    weight_in_invoices: boolean
    single_user_product_ids: boolean
    use_custom_font_all: boolean
    font_family: string | null
    font_size: number
    apply_custom_font_style: boolean
    custom_font_family: string | null
    custom_font_size: number | null
    use_last_purchase_rate_profit: boolean
    keep_product_prices: boolean
    auto_generate_barcodes: boolean
    recalc_sheet: boolean
    show_previous_balance: boolean
    show_salesman_vcs: boolean
    heading_ulid: string | null
    direct_print: boolean
    book_set: boolean
    sale_modal: boolean
    purchase_modal: boolean
    freight: string
    default_format: string | null
    description1: string | null
    description2: string | null
    disable_search_dates: boolean
    bill_to_bill: boolean
    products_group_option: boolean
  }
}

export type BusinessSettings = {
  ulid: string
  business_name: string
  legal_name: string | null
  phone: string | null
  email: string | null
  backup_email: string | null
  address: string | null
  city: string | null
  country_code: string
  currency_code: string
  timezone: string
  date_format: string
  number_format: string
  tax_registration_number: string | null
  invoice_prefix: string | null
  receipt_footer: string | null
  default_tax_percent: string
  negative_stock_allowed: boolean
  expiry_tracking_enabled: boolean
  batch_tracking_enabled: boolean
  default_price_level: string
  opening_balance_equity_account_ulid: string | null
  opening_balance_equity_account: {
    ulid: string
    code: string
    name: string
  } | null
  default_cash_account_ulid: string | null
  default_cash_account: {
    ulid: string
    code: string
    name: string
  } | null
  sales_clearing_account_ulid: string | null
  sales_clearing_account: {
    ulid: string
    code: string
    name: string
  } | null
  purchase_clearing_account_ulid: string | null
  purchase_clearing_account: {
    ulid: string
    code: string
    name: string
  } | null
}

export type ProductBarcode = {
  ulid: string
  barcode: string
  conversion_factor: string
  is_primary: boolean
  is_active: boolean
  unit?: Unit | null
}

export type ProductPrice = {
  ulid: string
  price_type: 'retail' | 'wholesale' | 'minimum_sale'
  amount: string
  currency_code: string
  is_active: boolean
  effective_from?: string | null
  effective_to?: string | null
}

export type Product = {
  ulid: string
  product_number: string
  sku: string | null
  name: string
  alternate_name: string | null
  tax_percent: string
  is_taxable: boolean
  track_batch: boolean
  track_expiry: boolean
  is_packaging: boolean
  max_free_qty_per_sale: string | null
  reorder_level: string | null
  minimum_stock: string | null
  maximum_stock: string | null
  rack_location: string | null
  description: string | null
  status: string
  is_active: boolean
  primary_barcode?: string | null
  image_url?: string | null

  category?: CatalogItem | null
  subcategory?: Subcategory | null
  brand?: Brand | null
  barcode_group?: BarcodeGroup | null
  primary_supplier?: PrimarySupplier | null
  supplier_product_code?: string | null

  base_unit?: Unit | null
  secondary_unit?: Unit | null
  secondary_conversion_factor?: string | null

  barcodes?: ProductBarcode[]
  prices?: ProductPrice[]
  sales_lookup?: {
    in_stock: string
    average_cost: string | null
    stock_value?: string | null
    retail_stock_value?: string | null
  }
}

export type ProductStockResponse = {
  product: {
    ulid: string
    product_number: string
    sku: string | null
    name: string
  }
  active_warehouse: {
    ulid: string
    code: string
    name: string
    quantity: string
  }
  total_quantity: string
  warehouses: Array<{
    warehouse: {
      ulid: string
      code: string
      name: string
    }
    quantity: string
    average_cost: string | null
    stock_value: string | null
  }>
}

export type Paginated<T> = {
  data: T[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
  }
}

export const PRODUCT_COLUMNS = [
  { key: 'product_number', label: 'Product #', visible: true, width: 90 },
  { key: 'sku', label: 'SKU', visible: true, width: 90 },
  { key: 'primary_barcode', label: 'Barcode', visible: true, width: 120 },
  { key: 'name', label: 'Product Name', visible: true, width: 180 },
  { key: 'category', label: 'Category', visible: true, width: 110 },
  { key: 'brand', label: 'Brand', visible: true, width: 100 },
  { key: 'unit', label: 'Unit', visible: true, width: 70 },
  { key: 'retail', label: 'Retail Price', visible: true, width: 90 },
  { key: 'wholesale', label: 'Wholesale Price', visible: true, width: 100 },
  { key: 'tax', label: 'Tax', visible: true, width: 70 },
  { key: 'status', label: 'Status', visible: true, width: 90 },
] as const