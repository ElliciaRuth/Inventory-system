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
}
