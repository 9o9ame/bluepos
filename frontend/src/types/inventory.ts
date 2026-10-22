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
  revision_number?: number
  is_amended?: boolean
  warehouse?: {
    ulid: string
    code: string
    name: string
    status: string
  } | null
  lines?: OpeningBalanceLine[]
}


export type StockTakeLine = {
  ulid: string
  system_quantity: string
  counted_quantity: string
  variance_quantity: string
  notes: string | null
  product?: {
    ulid: string
    product_number: string
    sku: string | null
    name: string
  } | null
}

export type StockTake = {
  ulid: string
  document_number: string
  count_date: string
  status: 'draft' | 'posted'
  notes: string | null
  posted_at: string | null
  warehouse?: {
    ulid: string
    code: string
    name: string
    status: string
  } | null
  lines?: StockTakeLine[]
}


export type InventoryAdjustmentLine = {
  ulid: string
  in_stock: string
  qty_plus: string
  qty_minus: string
  after_stock: string
  price: string
  amount: string
  remarks: string | null
  product?: {
    ulid: string
    product_number: string
    sku: string | null
    name: string
  } | null
}

export type InventoryAdjustment = {
  ulid: string
  document_number: string
  adjustment_date: string
  adjustment_type: 'stock_adjustment'
  status: 'draft' | 'posted'
  notes: string | null
  net_amount: string
  posted_at: string | null
  warehouse?: {
    ulid: string
    code: string
    name: string
  } | null
  account?: {
    ulid: string
    code: string
    name: string
  } | null
  lines?: InventoryAdjustmentLine[]
}

export type InventoryAdjustmentAccount = {
  ulid: string
  code: string
  name: string
}


export type ProductStockStatusRow = {
  product: {
    ulid: string
    product_number: string
    sku: string | null
    name: string
    category: { ulid: string; name: string } | null
    brand: { ulid: string; name: string } | null
  }
  quantity: string
  reorder_level: string | null
  average_cost: string | null
  stock_value: string | null
  retail_rate: string | null
  retail_stock_value: string | null
}

export type ProductStockStatusReport = {
  data: ProductStockStatusRow[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
    warehouse: {
      ulid: string
      code: string
      name: string
    }
    totals: {
      quantity: string
      stock_value: string | null
      retail_stock_value: string
    }
  }
}

export type ProductLedgerRow = {
  ulid: string
  occurred_at: string | null
  movement_type: string
  reference_type: string
  reference_ulid: string | null
  quantity_in: string
  quantity_out: string
  running_quantity: string
  unit_cost: string | null
  total_cost: string | null
  notes: string | null
}

export type ProductLedgerReport = {
  product: {
    ulid: string
    product_number: string
    sku: string | null
    name: string
  }
  warehouse: {
    ulid: string
    code: string
    name: string
  }
  period: {
    from_date: string
    to_date: string
  }
  opening_quantity: string
  data: ProductLedgerRow[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
    totals: {
      quantity_in: string
      quantity_out: string
      net_quantity: string
    }
    closing_quantity: string
  }
}
