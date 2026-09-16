import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/api/auth', () => ({
  getMe: vi.fn(),
  logout: vi.fn(),
}))

import { getMe, logout as apiLogout } from '@/api/auth'

describe('web auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('setToken 持久化 Token', () => {
    const auth = useAuthStore()
    auth.setToken('web-tok-1')

    expect(auth.token).toBe('web-tok-1')
    expect(localStorage.getItem('web_token')).toBe('web-tok-1')
  })

  it('clear 清空 Token 与用户', () => {
    const auth = useAuthStore()
    auth.setToken('web-tok-2')
    auth.user = { id: 1, username: 'u', nickname: null, roles: [] }

    auth.clear()

    expect(auth.token).toBe('')
    expect(auth.user).toBeNull()
    expect(localStorage.getItem('web_token')).toBeNull()
  })

  it('fetchUser 写入用户信息', async () => {
    const auth = useAuthStore()
    auth.setToken('web-tok-3')

    vi.mocked(getMe).mockResolvedValue({
      data: { code: 0, message: 'ok', data: { id: 7, username: 'u7', nickname: null, roles: ['customer'] } },
    } as never)

    await auth.fetchUser()
    expect(auth.user?.id).toBe(7)
  })

  it('logout 即使 API 失败也清理本地态', async () => {
    const auth = useAuthStore()
    auth.setToken('web-tok-4')
    vi.mocked(apiLogout).mockRejectedValue(new Error('down'))

    await auth.logout()

    expect(auth.token).toBe('')
    expect(auth.user).toBeNull()
    expect(localStorage.getItem('web_token')).toBeNull()
  })
})

describe('ORDER_STATUS_LABELS 状态映射', () => {
  it('与后端 Order::STATUS_LABELS 对齐', async () => {
    const { ORDER_STATUS_LABELS } = await import('@/api/order')

    expect(ORDER_STATUS_LABELS.pending_payment).toBe('待支付')
    expect(ORDER_STATUS_LABELS.paid).toBe('已支付')
    expect(ORDER_STATUS_LABELS.pending_ship).toBe('待发货')
    expect(ORDER_STATUS_LABELS.shipped).toBe('已发货')
    expect(ORDER_STATUS_LABELS.completed).toBe('已完成')
    expect(ORDER_STATUS_LABELS.cancelled).toBe('已取消')
    expect(ORDER_STATUS_LABELS.refunding).toBe('退款中')
    expect(ORDER_STATUS_LABELS.refunded).toBe('已退款')
    expect(Object.keys(ORDER_STATUS_LABELS)).toHaveLength(8)
  })
})
