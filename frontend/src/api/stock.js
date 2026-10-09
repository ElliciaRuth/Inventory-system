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

  // Which batches a stock-out would use: { product_id, copy_id, quantity, type, reason_id }
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
