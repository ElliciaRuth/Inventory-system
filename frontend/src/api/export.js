import client from './client'

export const exportApi = {
  async getStockcardOptions() {
    const res = await client.get('/export/stockcard/options')
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
