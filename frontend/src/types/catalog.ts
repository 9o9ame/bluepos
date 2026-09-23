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

export type BusinessSettings = {
  ulid: string
  business_name: string
  legal_name: string | null
  phone: string | null
  email: string | null
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
  reorder_level: string | null
  minimum_stock: string | null
  maximum_stock: string | null
  rack_location: string | null
  description: string | null
  status: string
  is_active: boolean
  primary_barcode?: string | null

  category?: CatalogItem | null
  subcategory?: Subcategory | null
  brand?: Brand | null
  barcode_group?: BarcodeGroup | null

  base_unit?: Unit | null
  secondary_unit?: Unit | null
  secondary_conversion_factor?: string | null

  barcodes?: ProductBarcode[]
  prices?: ProductPrice[]
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