import client from './client'

export const adminApi = {
  async getAdminData() {
    const response = await client.get('/dashboard/admin')
    return response.data
  },

  async activateUser(userId) {
    const response = await client.post(`/admin/users/activate/${userId}`)
    return response.data
  },

  async deactivateUser(userId) {
    const response = await client.post(`/admin/users/deactivate/${userId}`)
    return response.data
  },

  async deleteRecord(type, id) {
    const response = await client.delete(`/admin/records/${type}/${id}`)
    return response.data
  },

  async saveRecord(type, data) {
    const response = await client.post(`/admin/save/${type}`, data)
    return response.data
  },
}
