import client from './client'

export const auditApi = {
  async getLogs(params = {}) {
    const response = await client.get('/audit-logs', { params })
    return response.data
  },
}
