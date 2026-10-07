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
}
