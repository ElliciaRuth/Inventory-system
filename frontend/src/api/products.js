import client from './client'

export const productsApi = {
  async getProducts(params = {}) {
    const response = await client.get('/products', { params })
    return response.data
  },

  async getMeta() {
    const response = await client.get('/products/meta')
    return response.data
  },

  async getProduct(id) {
    const response = await client.get(`/products/${id}`)
    return response.data
  },

  async createProduct(payload) {
    const response = await client.post('/products', payload)
    return response.data
  },

  async updateProduct(id, payload) {
    const response = await client.put(`/products/${id}`, payload)
    return response.data
  },

  async deleteProduct(id) {
    const response = await client.delete(`/products/${id}`)
    return response.data
  },

  // Hide a product that is no longer used; its records are kept
  async archiveProduct(id, reason = '') {
    const response = await client.post(`/products/${id}/archive`, { reason })
    return response.data
  },

  async restoreProduct(id) {
    const response = await client.post(`/products/${id}/restore`)
    return response.data
  },
}
