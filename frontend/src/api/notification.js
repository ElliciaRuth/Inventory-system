import client from './client'

export const notificationApi = {
  async getNotifications() {
    const response = await client.get('/notifications')
    return response.data
  },
}
