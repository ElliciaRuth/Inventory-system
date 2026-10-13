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

  async updateProfile(payload) {
    const response = await client.post('/auth/update-profile', payload)
    return response.data
  },

  // ── Forgot password: the system SMTP account sends the OTP — no app-key needed ──
  async forgotPassword(email) {
    const response = await client.post('/auth/forgot-password', { email })
    return response.data
  },

  async verifyResetCode(email, code) {
    const response = await client.post('/auth/verify-reset-code', { email, code })
    return response.data
  },

  async resetPassword(payload) {
    const response = await client.post('/auth/reset-password', payload)
    return response.data
  },

  // ── First-login setup: SMTP account (admin_tech only) ──
  async setupSmtp(smtpEmail, smtpPassword) {
    const response = await client.post('/auth/setup-smtp', {
      smtp_email: smtpEmail,
      smtp_password: smtpPassword,
    })
    return response.data
  },

  // ── First-login setup: recovery email (all users) ──
  async requestRecoveryEmail(recoveryEmail) {
    const response = await client.post('/auth/setup-recovery-email/request', {
      recovery_email: recoveryEmail,
    })
    return response.data
  },

  async confirmRecoveryEmail(code) {
    const response = await client.post('/auth/setup-recovery-email/confirm', { code })
    return response.data
  },
}
