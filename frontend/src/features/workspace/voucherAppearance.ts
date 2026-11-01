export function voucherAppearance(path?: string): { type: string; badge: string } | null {
  if (!path || path.split('?')[0] !== '/daily/vouchers') return null
  const type = new URLSearchParams(path.split('?')[1] ?? '').get('type')
  const badges: Record<string, string> = { opening: 'OB', payment: 'CP', receiving: 'CR', journal: 'JV' }
  return type && Object.prototype.hasOwnProperty.call(badges, type) ? { type, badge: badges[type] } : null
}
