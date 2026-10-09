import client from './client'

export const importApi = {
  // Upload an .xlsx of Appendix 58 stock cards; returns { token, plan } without writing anything
  async previewStockcards(file, fallbackOfficeId = 0) {
    const form = new FormData()
    form.append('file', file)
    if (fallbackOfficeId) form.append('fallback_office_id', String(fallbackOfficeId))

    const response = await client.post('/import/stockcards/preview', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000,
    })
    return response.data
  },

  // One card's header and rows, for correcting misinputs in the preview
  async getCard(token, card) {
    const response = await client.get('/import/stockcards/card', { params: { token, card } })
    return response.data
  },

  // edits: [{ card, row (null for header fields), field, value }]; returns { plan } checked again
  async reviseStockcards(token, edits, fallbackOfficeId = null) {
    const payload = { token, edits }
    if (fallbackOfficeId) payload.fallback_office_id = fallbackOfficeId
    const response = await client.post('/import/stockcards/revise', payload, { timeout: 120000 })
    return response.data
  },

  // payload: { token, mode: 'replace' | 'append', confirm, types: { productKey: type } }
  async commitStockcards(payload) {
    const response = await client.post('/import/stockcards/commit', payload, { timeout: 300000 })
    return response.data
  },
}
