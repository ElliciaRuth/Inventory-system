import client from './client'

export const exportApi = {
  async getStockcardOptions() {
    const res = await client.get('/export/stockcard/options')
    return res.data
  },

  // The stock card's styles for the editor, linked as a stylesheet (the server's CSP blocks inline styles)
  stockcardEditorStyleUrl() {
    return `${client.defaults.baseURL}/export/stockcard/editor-style`
  },

  // The stock card pages (Appendix 58), for editing before download
  async previewStockcard(payload) {
    const res = await client.post('/export/stockcard', { ...payload, format: 'preview' })
    return res.data
  },

  async downloadStockcard(payload) {
    const res = await client.post('/export/stockcard', payload, {
      responseType: 'blob',
    })
    return res
  },

  async getSummaryOptions() {
    const res = await client.get('/export/summary/options')
    return res.data
  },

  // The report's styles for the editor, linked as a stylesheet (the server's CSP blocks inline styles)
  summaryEditorStyleUrl() {
    return `${client.defaults.baseURL}/export/summary/editor-style`
  },

  // The report pages and their styles, for editing before download
  async previewSummary(payload) {
    const res = await client.post('/export/summary', { ...payload, format: 'preview' })
    return res.data
  },

  async downloadSummary(payload) {
    const res = await client.post('/export/summary', payload, {
      responseType: 'blob',
    })
    return res
  },
}

export function triggerBlobDownload(blob, defaultFilename) {
  const url = window.URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = defaultFilename
  document.body.appendChild(a)
  a.click()
  window.URL.revokeObjectURL(url)
  a.remove()
}
