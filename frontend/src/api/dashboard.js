import client from './client'

export const dashboardApi = {
  async getOverview() {
    const response = await client.get('/dashboard')
    return response.data
  },

  async getTransactions(params = {}) {
    const response = await client.get('/transactions', { params })
    return response.data
  },
}
