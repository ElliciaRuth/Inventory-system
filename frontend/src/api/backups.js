import client from './client'

// A backup to inspect/restore is either a saved one ({ backupId }) or a chosen file ({ file })
function sourceForm(source, extra = {}) {
  const form = new FormData()
  if (source.backupId) form.append('backup_id', String(source.backupId))
  if (source.file) form.append('file', source.file)
  for (const [key, value] of Object.entries(extra)) {
    if (Array.isArray(value)) value.forEach((v) => form.append(`${key}[]`, v))
    else if (value !== undefined && value !== null && value !== '') form.append(key, value)
  }
  return form
}

export const backupsApi = {
  // Saved backups, what a backup can contain, and the storage/schedule settings
  async list() {
    const response = await client.get('/backups')
    return response.data
  },

  // Create a backup package: sections = ['setup', 'inventory', 'users', 'settings'], password optional
  async run({ sections = [], password = '' } = {}) {
    const response = await client.post('/backups/run', { sections, password }, { timeout: 300000 })
    return response.data
  },

  // Runs a backup only if one is due for the user's office
  async auto() {
    const response = await client.post('/backups/auto')
    return response.data
  },

  async download(backupId) {
    return client.get(`/backups/${backupId}/download`, { responseType: 'blob', timeout: 300000 })
  },

  // What a backup contains and whether it is intact, before restoring
  async inspect(source, password = '') {
    const response = await client.post('/backups/inspect', sourceForm(source, { password }), {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 300000,
    })
    return response.data
  },

  async restore(source, { sections = [], password = '' } = {}) {
    const response = await client.post('/backups/restore', sourceForm(source, { sections, password, confirm: 'yes' }), {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 600000,
    })
    return response.data
  },

  async saveConfig(config) {
    const response = await client.post('/backups/config', config)
    return response.data
  },
}
