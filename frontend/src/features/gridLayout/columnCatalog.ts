export type GridColumnDef = {
  key: string
  label: string
  /** Shown by default before any saved preference */
  defaultVisible: boolean
  /** Cannot be hidden or reordered past locked peers */
  lockable?: boolean
  defaultLocked?: boolean
  width?: number
  align?: 'left' | 'right' | 'center'
  cellClass?: string
  /** Header shows % / Rs toggle; cell shows both values with one editable */
  dualPctRs?: boolean
}

export type GridColumnState = {
  key: string
  visible: boolean
  position: number
  width: number | null
  locked: boolean
}

export type ResolvedGridColumn = Omit<GridColumnDef, 'width'> & GridColumnState

export const PURCHASE_INVOICE_SCREEN = 'purchase.invoice.lines.v5'
export const SALES_INVOICE_SCREEN = 'sales.invoice.lines'

/**
 * Purchase invoice line catalog (Alphary-style).
 * %/Rs pairs are single dual columns. Removed legacy extras from chooser.
 */
export const PURCHASE_INVOICE_COLUMNS: GridColumnDef[] = [
  { key: 'line_no', label: '#', defaultVisible: true, defaultLocked: true, lockable: false, width: 40, align: 'center' },
  { key: 'item_code', label: 'Item Code', defaultVisible: true, width: 90 },
  { key: 'product', label: 'Item Name / Description', defaultVisible: true, defaultLocked: true, lockable: true, width: 220 },
  { key: 'batch', label: 'Batch No.', defaultVisible: true, width: 88 },
  { key: 'expiry', label: 'Exp. Date', defaultVisible: true, width: 96 },
  { key: 'quantity', label: 'Qty', defaultVisible: true, width: 72, align: 'right', cellClass: 'cell-yellow' },
  { key: 'uom', label: 'Unit', defaultVisible: true, width: 56 },
  { key: 'mrp_ex_gst', label: 'MRP (Without GST)', defaultVisible: false, width: 108, align: 'right' },
  { key: 'mrp_in_gst', label: 'MRP (Incl. GST)', defaultVisible: false, width: 100, align: 'right' },
  { key: 'trade_price', label: 'Trade Price', defaultVisible: true, width: 96, align: 'right', cellClass: 'cell-yellow' },
  {
    key: 'regular_disc',
    label: 'Regular Disc.',
    defaultVisible: true,
    width: 110,
    align: 'right',
    cellClass: 'cell-yellow',
    dualPctRs: true,
  },
  {
    key: 'special_disc',
    label: 'Special Disc.',
    defaultVisible: false,
    width: 110,
    align: 'right',
    cellClass: 'cell-yellow',
    dualPctRs: true,
  },
  {
    key: 'gst',
    label: 'GST',
    defaultVisible: true,
    width: 110,
    align: 'right',
    cellClass: 'cell-yellow',
    dualPctRs: true,
  },
  {
    key: 'disc_after_gst',
    label: 'Discount After GST',
    defaultVisible: false,
    width: 120,
    align: 'right',
    dualPctRs: true,
  },
  {
    key: 'further_tax',
    label: 'Further Tax',
    defaultVisible: false,
    width: 110,
    align: 'right',
    dualPctRs: true,
  },
  {
    key: 'advance_tax',
    label: 'Advance Tax',
    defaultVisible: false,
    width: 110,
    align: 'right',
    dualPctRs: true,
  },
  { key: 'cost_price', label: 'Cost Price', defaultVisible: false, width: 92, align: 'right', cellClass: 'cell-cyan' },
  { key: 'sale_price', label: 'Sale Price', defaultVisible: false, width: 92, align: 'right' },
  { key: 'margin', label: 'Margin', defaultVisible: false, width: 100, align: 'right' },
  { key: 'total_amount', label: 'Total', defaultVisible: true, width: 100, align: 'right', cellClass: 'cell-cyan' },
  { key: 'delete', label: 'Action', defaultVisible: true, defaultLocked: true, lockable: false, width: 56, align: 'center' },
]

/** Full sales invoice line catalog (POS PLUS parity). */
export const SALES_INVOICE_COLUMNS: GridColumnDef[] = [
  { key: 'selector', label: '', defaultVisible: true, defaultLocked: true, lockable: false, width: 28 },
  { key: 'product', label: 'ITEM / PRODUCT DESCRIPTION', defaultVisible: true, defaultLocked: true, lockable: true, width: 260 },
  { key: 'in_stock', label: 'In Stock', defaultVisible: true, width: 72, align: 'right' },
  { key: 'sales_qty', label: 'Sales Qty', defaultVisible: true, width: 72, align: 'right', cellClass: 'cell-yellow' },
  { key: 'price', label: 'Price', defaultVisible: true, width: 72, align: 'right', cellClass: 'cell-yellow' },
  { key: 'amt', label: 'AMT', defaultVisible: true, width: 80, align: 'right' },
  { key: 'disc_pct', label: 'Disc%', defaultVisible: true, width: 64, align: 'right' },
  { key: 'disc_rs', label: 'Disc-Rs', defaultVisible: true, width: 72, align: 'right' },
  { key: 'net_amt', label: 'Net Amt', defaultVisible: true, width: 90, align: 'right' },
  { key: 'at', label: 'A.T', defaultVisible: false, width: 56, align: 'right' },
  { key: 'at_amt', label: 'AT Amt', defaultVisible: false, width: 72, align: 'right' },
  { key: 'avgrate', label: 'AVGRATE', defaultVisible: false, width: 72, align: 'right' },
  { key: 'barcode', label: 'Barcode', defaultVisible: false, width: 110 },
  { key: 'batch', label: 'Batch', defaultVisible: false, width: 90 },
  { key: 'carton', label: 'Carton', defaultVisible: false, width: 64, align: 'right' },
  { key: 'com_pct', label: 'Com-%', defaultVisible: false, width: 64, align: 'right' },
  { key: 'com_rs', label: 'Com-Rs', defaultVisible: false, width: 72, align: 'right' },
  { key: 'cost_amt', label: 'Cost Amt', defaultVisible: false, width: 80, align: 'right' },
  { key: 'cost_rate', label: 'Cost Rate', defaultVisible: false, width: 72, align: 'right' },
  { key: 'desc', label: 'DESC.', defaultVisible: false, width: 90 },
  { key: 'dis_pct_amt', label: 'Dis % Amt', defaultVisible: false, width: 80, align: 'right' },
  { key: 'dis2_amt', label: 'Dis2Amt', defaultVisible: false, width: 72, align: 'right' },
  { key: 'dis_diff', label: 'Dis-Diff', defaultVisible: false, width: 72, align: 'right' },
  { key: 'expiry', label: 'Expiry', defaultVisible: false, width: 96 },
  { key: 'mrp', label: 'M.R.P', defaultVisible: false, width: 72, align: 'right' },
  { key: 'pcs', label: 'PCs', defaultVisible: false, width: 56, align: 'right' },
  { key: 'pl', label: 'PL', defaultVisible: false, width: 48, align: 'center' },
  { key: 'project', label: 'Project', defaultVisible: false, width: 90 },
  { key: 'rate_uom', label: 'Rate (UOM)', defaultVisible: false, width: 80, align: 'right' },
  { key: 'remarks', label: 'Remarks', defaultVisible: false, width: 120 },
  { key: 'retail', label: 'Retail', defaultVisible: false, width: 72, align: 'right' },
  { key: 'return', label: 'Return', defaultVisible: false, width: 64, align: 'right' },
  { key: 's_tax_pct', label: 'S. Tax %', defaultVisible: false, width: 72, align: 'right' },
  { key: 'single_wt', label: 'SingleWt', defaultVisible: false, width: 72, align: 'right' },
  { key: 'store', label: 'Store', defaultVisible: false, width: 80 },
  { key: 'tray_pcs', label: 'Tray / PCs', defaultVisible: false, width: 80, align: 'right' },
  { key: 'tray_wt', label: 'TrayWt', defaultVisible: false, width: 72, align: 'right' },
  { key: 'uom', label: 'UOM', defaultVisible: false, width: 56 },
  { key: 'weight', label: 'Weight', defaultVisible: false, width: 72, align: 'right' },
  { key: 'wt_diff', label: 'Wt Diff', defaultVisible: false, width: 72, align: 'right' },
  { key: 'delete', label: '−', defaultVisible: true, defaultLocked: true, lockable: false, width: 36, align: 'center' },
]

export function defaultColumnStates(defs: GridColumnDef[]): GridColumnState[] {
  return defs.map((def, index) => ({
    key: def.key,
    visible: def.defaultVisible,
    position: index,
    width: def.width ?? null,
    locked: Boolean(def.defaultLocked),
  }))
}

export function mergeColumnLayout(
  defs: GridColumnDef[],
  saved: GridColumnState[] | null | undefined,
): ResolvedGridColumn[] {
  const byKey = new Map((saved ?? []).map((row) => [row.key, row]))
  const unknowns = (saved ?? []).filter((row) => !defs.some((d) => d.key === row.key))

  const merged: ResolvedGridColumn[] = defs.map((def, index) => {
    const pref = byKey.get(def.key)
    const locked = pref ? Boolean(pref.locked || def.defaultLocked) : Boolean(def.defaultLocked)
    const visible = locked ? true : (pref?.visible ?? def.defaultVisible)
    return {
      ...def,
      visible,
      position: pref?.position ?? index,
      width: pref?.width ?? def.width ?? null,
      locked,
    }
  })

  merged.sort((a, b) => a.position - b.position || a.key.localeCompare(b.key))
  merged.forEach((col, index) => {
    col.position = index
  })

  void unknowns
  return merged
}

export function toPersistedColumns(columns: ResolvedGridColumn[]): GridColumnState[] {
  return columns.map((col, index) => ({
    key: col.key,
    visible: col.visible,
    position: index,
    width: col.width,
    locked: col.locked,
  }))
}
