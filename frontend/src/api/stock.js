import client from './client'

export const stockApi = {
  async getStockcard(params = {}) {
    const response = await client.get('/stockcard', { params })
    return response.data
  },

  async getOptions() {
    const response = await client.get('/stock/options')
    return response.data
  },

  async addStock(payload) {
    const response = await client.post('/stock/add', payload)
    return response.data
  },

  async editTransaction(payload) {
    const response = await client.post('/stock/edit-transaction', payload)
    return response.data
  },

  async deleteTransaction(payload) {
    const response = await client.post('/stock/delete-transaction', payload)
    return response.data
  },

  // Inline edits from the Stock Card / Summary Report, saved all or nothing:
  // { edits: [{ transaction_ids, qty?, unit_cost?, reference?, office?, date?, expiration_date?, type? }], deletes: [ids[]] }
  async saveLedgerEdits(payload) {
    const response = await client.post('/stock/ledger-edits', payload)
    return response.data
  },

  async editReportCost(transactionId, newCost) {
    const response = await client.post('/stock/edit-report-cost', {
      transaction_id: transactionId,
      new_cost: newCost,
    })
    return response.data
  },

  async getCopies(productId) {
    const response = await client.get(`/stock/copies/${productId}`)
    return response.data
  },

  // Which batches a stock-out would use: { product_id, copy_id, quantity, type }
  async getBatchPlan(params) {
    const response = await client.get('/stock/batch-plan', { params })
    return response.data
  },

  // Borrow records: { product_id?, status? = open | outstanding | partial | returned }
  async getBorrows(params = {}) {
    const response = await client.get('/stock/borrows', { params })
    return response.data
  },

  // Physical count: counts = [{ product_id, counted_qty }]
  async submitCount(counts, note = '') {
    const response = await client.post('/stock/count', { counts, note })
    return response.data
  },
}
