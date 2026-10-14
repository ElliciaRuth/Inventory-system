// One place for how each ledger movement is named, coloured and signed.

// Movements that add to stock; every other type takes away
export const STOCK_IN_TYPES = ['receipt', 'return', 'adjust_in']

const LABELS = {
  receipt: 'Receipt',
  issue: 'Issue',
  adjust_out: 'Adjust Out',
  adjust_in: 'Adjust In',
  borrow: 'Lent',
  return: 'Returned',
}

const BADGES = {
  receipt: 'badge-success',
  return: 'badge-success',
  adjust_in: 'badge-info',
  issue: 'badge-info',
  borrow: 'badge-warning',
  adjust_out: 'badge-danger',
}

export const isStockIn = (type) => STOCK_IN_TYPES.includes(String(type || '').toLowerCase())

export const typeLabel = (type) => LABELS[String(type || '').toLowerCase()] || String(type || '')

export const typeBadge = (type) => BADGES[String(type || '').toLowerCase()] || 'badge-neutral'

// "+5" for stock coming in, "-5" for stock going out
export const signedQty = (type, qty) => `${isStockIn(type) ? '+' : '-'}${qty}`

// A stock card row can stand for several ledger entries (a stock-out taken from several
// batches); edits and deletes must name all of them
export const rowTransactionIds = (row) =>
  String(row?.transaction_ids || row?.transaction_id || '')
    .split(',')
    .map(Number)
    .filter((id) => id > 0)
