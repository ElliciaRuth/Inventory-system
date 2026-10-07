import client from './client'

export const reportsApi = {
  // { search, show_empty: 0|1 }
  async getBatches(params = {}) {
    const response = await client.get('/reports/batches', { params })
    return response.data
  },

  // { search, year, month, type_id }
  async getBatchList(params = {}) {
    const response = await client.get('/reports/batchlist', { params })
    return response.data
  },
}

export const barcodesApi = {
  // Image URLs — usable directly in <img :src>
  productImageUrl: (productId) => `${client.defaults.baseURL}/barcode/product/${productId}`,
  batchImageUrl: (batchId) => `${client.defaults.baseURL}/barcode/batch/${batchId}`,

  async lookup(value) {
    const response = await client.get('/barcode/lookup', { params: { value } })
    return response.data
  },

  async getFinishedProducts(params = {}) {
    const response = await client.get('/products/barcodes', { params })
    return response.data
  },

  async generateFinishedProductBarcode(productId) {
    const response = await client.post('/products/barcodes/generate', { product_id: productId })
    return response.data
  },
}
