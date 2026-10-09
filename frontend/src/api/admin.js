import client from './client'

// User and user-office management for the Technical Staff dashboard.
// Backed by the /settings endpoints (see api/settings.js for the full set).
export const adminApi = {
  async getAdminData() {
    const response = await client.get('/settings')
    return response.data
  },

  async activateUser(userId) {
    const response = await client.post(`/settings/users/${userId}/activate`)
    return response.data
  },

  async deactivateUser(userId) {
    const response = await client.post(`/settings/users/${userId}/deactivate`)
    return response.data
  },

  async deleteRecord(type, id) {
    const response = await client.delete(`/settings/${type}/${id}`)
    return response.data
  },

  async saveRecord(type, data) {
    const response = await client.post(`/settings/${type}`, data)
    return response.data
  },
}
