import client from './client'

export const stockoutApi = {
  // Products with stock available for a request
  async getAvailableItems() {
    const response = await client.get('/stockout')
    return response.data
  },

  // Current user's draft list
  async getDraft() {
    const response = await client.get('/stockout/temp')
    return response.data
  },

  // { product_id, copy_id, quantity, unit, description }
  async addToDraft(payload) {
    const response = await client.post('/stockout/add-temp', payload)
    return response.data
  },

  async editDraftItem(itemId, payload) {
    const response = await client.post(`/stockout/edit-temp/${itemId}`, payload)
    return response.data
  },

  async removeDraftItem(itemId) {
    const response = await client.post(`/stockout/remove-temp/${itemId}`)
    return response.data
  },

  async submitDraft() {
    const response = await client.post('/stockout/submit')
    return response.data
  },

  // Level 2+: approval queue
  async getPending() {
    const response = await client.get('/stockout/pending')
    return response.data
  },

  async approveItem(itemId) {
    const response = await client.post(`/stockout/approve-item/${itemId}`)
    return response.data
  },

  async approveAll(requestId) {
    const response = await client.post(`/stockout/approve-all/${requestId}`)
    return response.data
  },

  async rejectItem(itemId) {
    const response = await client.post(`/stockout/reject-item/${itemId}`)
    return response.data
  },

  async editPendingItem(itemId, quantity) {
    const response = await client.post(`/stockout/edit-pending/${itemId}`, { quantity })
    return response.data
  },
}
