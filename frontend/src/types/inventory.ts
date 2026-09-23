export type StockWarehouseBalance = {
  warehouse: {
    ulid: string
    code: string
    name: string
  }
  quantity: string
  average_cost: string | null
  stock_value: string | null
}

export type ProductStock = {
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
  warehouses: StockWarehouseBalance[]
}

export type StockBalance = {
  quantity: string
  average_cost: string | null
  stock_value: string | null
  product?: {
    ulid: string
    product_number: string
    sku: string | null
    name: string
  } | null
  warehouse?: {
    ulid: string
    code: string
    name: string
  } | null
}

export type OpeningBalanceLine = {
  ulid: string
  quantity: string
  unit_cost: string
  total_cost: string
  notes: string | null
  product?: {
    ulid: string
    product_number: string
    sku: string | null
    name: string
    is_active: boolean
  } | null
}

export type OpeningBalance = {
  ulid: string
  document_number: string
  document_date: string
  status: 'draft' | 'posted'
  notes: string | null
  posted_at: string | null
  warehouse?: {
    ulid: string
    code: string
    name: string
    status: string
  } | null
  lines?: OpeningBalanceLine[]
}
