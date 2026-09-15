import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getMe, logout as apiLogout, type UserInfo } from '@/api/auth'

const TOKEN_KEY = 'web_token'

/** 用户端认证态：token 持久化 + 用户信息 */
export const useAuthStore = defineStore('web-auth', () => {
  const token = ref<string>(localStorage.getItem(TOKEN_KEY) ?? '')
  const user = ref<UserInfo | null>(null)

  function setToken(value: string) {
    token.value = value
    localStorage.setItem(TOKEN_KEY, value)
  }

  function clear() {
    token.value = ''
    user.value = null
    localStorage.removeItem(TOKEN_KEY)
  }

  async function fetchUser() {
    const { data } = await getMe()
    user.value = data.data
  }

  async function logout() {
    try {
      await apiLogout()
    } catch {
      // Token 失效也照常清理本地
    }
    clear()
  }

  return { token, user, setToken, clear, fetchUser, logout }
})
