/**
 * Future BluePOS Print Bridge / native helper contract.
 * Browser/PWA cannot enumerate installed Windows printers.
 */

export type PrinterInfo = {
  id: string
  name: string
  isDefault: boolean
}

export type PrinterProvider = {
  listPrinters: () => Promise<PrinterInfo[]>
  isNativeBridgeAvailable: () => boolean
}

export const browserPrintDialogPrinter: PrinterInfo = {
  id: 'system-print-dialog',
  name: 'System Print Dialog',
  isDefault: true,
}

export const browserPrinterProvider: PrinterProvider = {
  isNativeBridgeAvailable: () => false,
  async listPrinters() {
    return [browserPrintDialogPrinter]
  },
}
