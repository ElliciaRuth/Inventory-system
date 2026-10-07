import client from './client'

// Reference data (entity_table, unit_table, reference_table, type_of_product,
// office_table), users and user_office_table. Which types are available
// depends on the user's level — GET /settings returns the allowed definitions.
export const settingsApi = {
  async getAll() {
    const response = await client.get('/settings')
    return response.data
  },

  async getRecord(type, id) {
    const response = await client.get(`/settings/${type}/${id}`)
    return response.data
  },

  // id = 0 creates a new record
  async saveRecord(type, data) {
    const response = await client.post(`/settings/${type}`, data)
    return response.data
  },

  async deleteRecord(type, id) {
    const response = await client.delete(`/settings/${type}/${id}`)
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

  async getSystemSettings() {
    const response = await client.get('/settings/system')
    return response.data
  },

  async saveSystemSettings(expiryWarningDays, expiryDangerDays) {
    const response = await client.post('/settings/system', {
      expiry_warning_days: expiryWarningDays,
      expiry_danger_days: expiryDangerDays,
    })
    return response.data
  },
}
