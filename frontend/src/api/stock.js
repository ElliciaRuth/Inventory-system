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
}
