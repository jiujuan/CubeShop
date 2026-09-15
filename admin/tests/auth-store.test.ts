import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'

// Mock API 层
vi.mock('@/api/auth', () => ({
  getMe: vi.fn(),
  logout: vi.fn(),
}))

import { getMe, logout as logoutApi } from '@/api/auth'

describe('admin auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('setToken 写入并持久化 Token', () => {
    const auth = useAuthStore()
    auth.setToken('tok-1', true)

    expect(auth.token).toBe('tok-1')
    expect(localStorage.getItem('cubeshop_admin_token')).toBe('tok-1')
    expect(localStorage.getItem('cubeshop_admin_remember')).toBe('1')
  })

  it('clearToken 清空内存与本地存储', () => {
    const auth = useAuthStore()
    auth.setToken('tok-2', true)
    auth.user = { id: 1, username: 'a', nickname: null, avatar: null, phone: null, email: null, roles: [] }

    auth.clearToken()

    expect(auth.token).toBeNull()
    expect(auth.user).toBeNull()
    expect(localStorage.getItem('cubeshop_admin_token')).toBeNull()
  })

  it('super_admin 拥有任意权限（直通）', () => {
    const auth = useAuthStore()
    auth.user = {
      id: 1, username: 'admin', nickname: null, avatar: null, phone: null, email: null,
      roles: ['super_admin'], permissions: [],
    }

    expect(auth.isSuperAdmin).toBe(true)
    expect(auth.hasPermission('order.ship')).toBe(true)
    expect(auth.hasPermission(['order.ship', 'order.export'])).toBe(true)
  })

  it('普通用户按权限码判定', () => {
    const auth = useAuthStore()
    auth.user = {
      id: 2, username: 'op', nickname: null, avatar: null, phone: null, email: null,
      roles: ['operator'], permissions: ['order.view'],
    }

    expect(auth.hasPermission('order.view')).toBe(true)
    expect(auth.hasPermission('order.ship')).toBe(false)
    expect(auth.hasPermission(['order.ship', 'order.view'])).toBe(true) // 任一满足
    expect(auth.hasPermission(undefined)).toBe(true) // 未指定 = 不限制
  })

  it('fetchUser 拉取用户信息', async () => {
    const auth = useAuthStore()
    auth.setToken('tok-3')

    vi.mocked(getMe).mockResolvedValue({
      data: {
        code: 0, message: 'ok',
        data: { id: 9, username: 'u9', nickname: null, avatar: null, phone: null, email: null, roles: ['operator'], permissions: ['product.view'] },
      },
    } as never)

    const user = await auth.fetchUser()
    expect(user?.id).toBe(9)
    expect(auth.permissions).toContain('product.view')
  })

  it('无 Token 时 fetchUser 直接返回 null', async () => {
    const auth = useAuthStore()
    expect(await auth.fetchUser()).toBeNull()
    expect(getMe).not.toHaveBeenCalled()
  })

  it('logout 即使 API 失败也清理本地态', async () => {
    const auth = useAuthStore()
    auth.setToken('tok-4')
    vi.mocked(logoutApi).mockRejectedValue(new Error('network'))

    await expect(auth.logout()).rejects.toThrow('network')

    expect(auth.token).toBeNull()
    expect(localStorage.getItem('cubeshop_admin_token')).toBeNull()
  })
})
