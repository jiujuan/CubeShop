import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getNotificationsMock,
  getUnreadCountMock,
  markNotificationsReadMock,
  getCategoriesMock,
  getCartCountMock,
} = vi.hoisted(() => ({
  getNotificationsMock: vi.fn(),
  getUnreadCountMock: vi.fn(),
  markNotificationsReadMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getCartCountMock: vi.fn(),
}))

vi.mock('@/api/notification', () => ({
  getNotifications: getNotificationsMock,
  getUnreadCount: getUnreadCountMock,
  markNotificationsRead: markNotificationsReadMock,
}))

vi.mock('@/api/user', () => ({
  getCartCount: getCartCountMock,
  addToCart: vi.fn(),
  getCart: vi.fn(),
  uploadImage: vi.fn(),
}))

vi.mock('@/api/shop', () => ({
  getCategories: getCategoriesMock,
  getProducts: vi.fn(),
  getProduct: vi.fn(),
  getHot: vi.fn(),
}))

import NotificationBell from '@/components/NotificationBell.vue'
import NotificationsView from '@/views/NotificationsView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/orders/:id', component: { template: '<div />' } },
      { path: '/notifications', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
    ],
  })
}

const notif = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  type: 'order_paid',
  title: '支付成功',
  content: '订单 CS100 支付成功',
  link: '/orders/100',
  is_read: false,
  read_at: null,
  created_at: '2026-09-16 10:00:00',
  ...overrides,
})

function setupAuth(login = true) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  if (login) {
    auth.token = 't'
    auth.user = { id: 1, username: 'u', nickname: '小明', avatar: null, phone: null, email: null }
  }
  return pinia
}

beforeEach(() => {
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getUnreadCountMock.mockResolvedValue({ data: { data: { count: 2 } } })
  getNotificationsMock.mockResolvedValue({
    data: { data: { list: [notif()], pagination: { page: 1, page_size: 15, total: 1, total_pages: 1 } } },
  })
 })

// ---------- 铃铛（T-019） ----------

describe('顶栏通知铃铛 NotificationBell', () => {
  async function renderBell(login = true) {
    const pinia = setupAuth(login)
    const router = makeRouter()
    router.push('/')
    await router.isReady()
    const utils = render(NotificationBell, { global: { plugins: [pinia, router] } })
    return { utils, router }
  }

  it('未登录不渲染铃铛', () => {
    render(NotificationBell, { global: { plugins: [setupAuth(false), makeRouter()] } })
    expect(screen.queryByTestId('notification-bell')).toBeNull()
  })

  it('登录后展示未读数角标', async () => {
    await renderBell()
    await waitFor(() => expect(screen.getByTestId('notification-badge')).toBeTruthy())
    expect(screen.getByTestId('notification-badge').textContent).toBe('2')
  })

  it('点击铃铛展开面板并展示最近通知', async () => {
    await renderBell()
    await fireEvent.click(screen.getByTestId('notification-bell'))

    await waitFor(() => expect(screen.getByTestId('notification-panel')).toBeTruthy())
    expect(screen.getByTestId('notification-item-1')).toBeTruthy()
    expect(screen.getByTestId('notification-item-1').textContent).toContain('支付成功')
  })

  it('点击通知条目先标记已读再跳转', async () => {
    markNotificationsReadMock.mockResolvedValue({ data: { data: { updated: 1 } } })
    const { router } = await renderBell()
    const push = vi.spyOn(router, 'push')

    await fireEvent.click(screen.getByTestId('notification-bell'))
    await waitFor(() => expect(screen.getByTestId('notification-item-1')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('notification-item-1'))

    await waitFor(() => expect(markNotificationsReadMock).toHaveBeenCalledWith([1]))
    expect(push).toHaveBeenCalledWith('/orders/100')
  })

  it('点击「查看全部」跳转通知中心', async () => {
    const { router } = await renderBell()
    const push = vi.spyOn(router, 'push')

    await fireEvent.click(screen.getByTestId('notification-bell'))
    await waitFor(() => expect(screen.getByTestId('notification-view-all')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('notification-view-all'))

    expect(push).toHaveBeenCalledWith('/notifications')
  })

  it('无消息时面板展示空态', async () => {
    getNotificationsMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 15, total: 0, total_pages: 1 } } },
    })
    await renderBell()
    await fireEvent.click(screen.getByTestId('notification-bell'))

    await waitFor(() => expect(screen.getByTestId('notification-panel-empty')).toBeTruthy())
  })
})

// ---------- 通知中心（T-019） ----------

describe('通知中心 NotificationsView', () => {
  async function renderCenter() {
    const pinia = setupAuth(true)
    const router = makeRouter()
    router.push('/notifications')
    await router.isReady()
    const utils = render(NotificationsView, { global: { plugins: [pinia, router] } })
    await waitFor(() => expect(screen.getByTestId('notification-tabs')).toBeTruthy())
    return { utils, router }
  }

  it('渲染通知列表', async () => {
    await renderCenter()
    expect(screen.getByTestId('notification-row-1')).toBeTruthy()
  })

  it('无消息时展示空态', async () => {
    getNotificationsMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 15, total: 0, total_pages: 1 } } },
    })
    await renderCenter()
    expect(screen.getByTestId('notification-empty').textContent).toBe('暂无消息')
  })

  it('切换「未读」Tab 携带 is_read=0 参数', async () => {
    await renderCenter()
    await fireEvent.click(screen.getByTestId('tab-unread'))

    await waitFor(() => {
      const last = getNotificationsMock.mock.calls.at(-1)!
      expect(last[0].is_read).toBe(0)
      expect(last[0].page).toBe(1)
    })
  })

  it('点击条目标记已读并跳转', async () => {
    markNotificationsReadMock.mockResolvedValue({ data: { data: { updated: 1 } } })
    const { router } = await renderCenter()
    const push = vi.spyOn(router, 'push')

    await fireEvent.click(screen.getByTestId('notification-row-1'))

    await waitFor(() => expect(markNotificationsReadMock).toHaveBeenCalledWith([1]))
    expect(push).toHaveBeenCalledWith('/orders/100')
  })

  it('一键全部已读', async () => {
    markNotificationsReadMock.mockResolvedValue({ data: { data: { updated: 2 } } })
    await renderCenter()

    await fireEvent.click(screen.getByTestId('mark-all'))

    await waitFor(() => expect(markNotificationsReadMock).toHaveBeenCalledWith([]))
  })
})
