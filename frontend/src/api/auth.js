import client from './client'

export const authApi = {
  async login(username, password) {
    const response = await client.post('/auth/login', { username, password })
    return response.data
  },

  async me() {
    const response = await client.get('/auth/me')
    return response.data
  },

  async logout() {
    const response = await client.post('/auth/logout')
    return response.data
  },

  async getRegisterOptions() {
    const response = await client.get('/auth/register-options')
    return response.data
  },

  async register(payload) {
    const response = await client.post('/auth/register', payload)
    return response.data
  },

  // currentPassword is not needed for the forced first-login change
  async changePassword(password, confirmPassword, currentPassword = '') {
    const response = await client.post('/auth/change-password', {
      current_password: currentPassword,
      password,
      confirm_password: confirmPassword,
    })
    return response.data
  },

  async setupSmtp(smtpEmail, smtpPassword) {
    const response = await client.post('/auth/setup-smtp', {
      smtp_email: smtpEmail,
      smtp_password: smtpPassword,
    })
    return response.data
  },

  async setupRecoveryEmail(recoveryEmail) {
    const response = await client.post('/auth/setup-recovery-email', { recovery_email: recoveryEmail })
    return response.data
  },

  async forgotPassword(email) {
    const response = await client.post('/auth/forgot-password', { email })
    return response.data
  },

  async resetPassword(payload) {
    const response = await client.post('/auth/reset-password', payload)
    return response.data
  },
}
