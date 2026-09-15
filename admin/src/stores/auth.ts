import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { getMe, logout as logoutApi, type UserInfo } from '@/api/auth'

const TOKEN_KEY = 'cubeshop_admin_token'
const REMEMBER_KEY = 'cubeshop_admin_remember'

/**
 * 认证状态：Token 持久化 + 权限/角色（供动态菜单与 v-permission）
 */
export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY))
  const remember = ref(localStorage.getItem(REMEMBER_KEY) === '1')
  const user = ref<UserInfo | null>(null)

  const roles = computed(() => user.value?.roles ?? [])
  const permissions = computed(() => user.value?.permissions ?? [])
  const isSuperAdmin = computed(() => roles.value.includes('super_admin'))

  function setToken(value: string, persistRemember = false) {
    token.value = value
    localStorage.setItem(TOKEN_KEY, value)
    if (persistRemember) {
      remember.value = true
      localStorage.setItem(REMEMBER_KEY, '1')
    }
  }

  function clearToken() {
    token.value = null
    user.value = null
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(REMEMBER_KEY)
  }

  /** 拉取当前用户信息（登录后/刷新页面时调用） */
  async function fetchUser() {
    if (!token.value) return null
    const res = await getMe()
    user.value = res.data.data
    return user.value
  }

  /** 退出登录 */
  async function logout() {
    try {
      await logoutApi()
    } finally {
      clearToken()
    }
  }

  /** 是否拥有指定权限码（超管直通） */
  function hasPermission(code?: string | string[]): boolean {
    if (!code || isSuperAdmin.value) return true
    const codes = Array.isArray(code) ? code : [code]
    return codes.some((c) => permissions.value.includes(c))
  }

  return {
    token, remember, user, roles, permissions, isSuperAdmin,
    setToken, clearToken, fetchUser, logout, hasPermission,
  }
})
