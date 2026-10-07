import client from './client'

export const backupsApi = {
  async list() {
    const response = await client.get('/backups')
    return response.data
  },

  async run() {
    const response = await client.post('/backups/run')
    return response.data
  },

  // Runs a backup only if one is due for the user's office
  async auto() {
    const response = await client.post('/backups/auto')
    return response.data
  },

  async download(backupId) {
    return client.get(`/backups/${backupId}/download`, { responseType: 'blob' })
  },

  async restoreFromBackup(backupId) {
    const response = await client.post('/backups/restore', { backup_id: backupId })
    return response.data
  },

  async restoreFromFile(file) {
    const form = new FormData()
    form.append('sql_file', file)
    const response = await client.post('/backups/restore', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },

  async saveConfig(config) {
    const response = await client.post('/backups/config', config)
    return response.data
  },
}
