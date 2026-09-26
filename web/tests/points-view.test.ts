import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const api = vi.hoisted(() => ({
  getMyPoints: vi.fn(),
  getMyPointLogs: vi.fn(),
  getCheckinStatus: vi.fn(),
  postCheckin: vi.fn(),
}))

vi.mock('@/api/points', () => ({
  getMyPoints: api.getMyPoints,
  getMyPointLogs: api.getMyPointLogs,
  getCheckinStatus: api.getCheckinStatus,
  postCheckin: api.postCheckin,
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getNav: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getProducts: vi.fn(),
  getProduct: vi.fn(),
  getHot: vi.fn(),
  getAttributes: vi.fn(),
  getBrands: vi.fn(),
}))

vi.mock('@/api/search', () => ({
  getSuggest: vi.fn(),
}))

vi.mock('@/api/announcement', () => ({
  getAnnouncements: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
}))

vi.mock('@/api/notification', () => ({
  getUnreadCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  getNotifications: vi.fn(),
  markRead: vi.fn(),
  markAllRead: vi.fn(),
}))

vi.mock('@/api/auth', () => ({
  getMe: vi.fn(),
  logout: vi.fn().mockResolvedValue(undefined),
}))

import PointsView from '@/views/PointsView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/points', component: { template: '<div />' } },
    ],
  })
}

const overview = {
  enabled: true,
  name: '积分',
  account: { balance: 128, frozen: 0, total: 128, total_earn: 200, total_spend: 72 },
  logs: [],
}

const logsPage1 = {
  list: [
    { id: 2, type: 'earn', type_label: '消费返积分', points: 3, frozen_points: 0, balance_before: 125, balance_after: 128, remark: '订单 CS-2 消费返积分', created_at: '2026-09-27 09:00:00' },
    { id: 1, type: 'signin', type_label: '签到奖励', points: 5, frozen_points: 0, balance_before: 120, balance_after: 125, remark: null, created_at: '2026-09-27 08:00:00' },
  ],
  pagination: { total: 12, per_page: 10, current_page: 1, last_page: 2 },
}

const logsPage2 = {
  list: [
    { id: 0, type: 'admin_adjust', type_label: '后台调整', points: -10, frozen_points: 0, balance_before: 130, balance_after: 120, remark: '测试扣减', created_at: '2026-09-26 10:00:00' },
  ],
  pagination: { total: 12, per_page: 10, current_page: 2, last_page: 2 },
}

describe('我的积分页（S3）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    api.getMyPoints.mockResolvedValue({ data: { data: overview } })
    api.getMyPointLogs.mockResolvedValue({ data: { data: logsPage1 } })
  })

  function mountView() {
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    router.push('/points')
    return render(PointsView, { global: { plugins: [router] } })
  }

  it('展示可用/累计获得/累计消耗与流水列表', async () => {
    mountView()
    await waitFor(() => expect(screen.getByTestId('points-summary')).toBeTruthy())

    expect(screen.getByTestId('points-balance').textContent).toBe('128')
    expect(screen.getByTestId('points-total-earn').textContent).toBe('200')
    expect(screen.getByTestId('points-total-spend').textContent).toBe('72')

    const rows = screen.getByTestId('points-logs').textContent
    expect(rows).toContain('消费返积分')
    expect(rows).toContain('+3')
    expect(rows).toContain('签到奖励')
    expect(rows).toContain('+5')
  })

  it('点击下一页加载第二页流水', async () => {
    api.getMyPointLogs.mockResolvedValueOnce({ data: { data: logsPage1 } })
    api.getMyPointLogs.mockResolvedValueOnce({ data: { data: logsPage2 } })

    mountView()
    await waitFor(() => expect(screen.getByTestId('points-logs-prev')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('points-logs-next'))

    await waitFor(() => expect(api.getMyPointLogs).toHaveBeenCalledWith(2, 10))
    await waitFor(() => expect(screen.getByTestId('points-logs').textContent).toContain('后台调整'))
    expect(screen.getByTestId('points-logs').textContent).toContain('-10')
  })

  it('积分功能未开启时展示禁用提示且不拉流水', async () => {
    api.getMyPoints.mockResolvedValue({
      data: { data: { ...overview, enabled: false } },
    })

    mountView()
    await waitFor(() => expect(screen.getByTestId('points-disabled')).toBeTruthy())
    expect(screen.getByTestId('points-disabled').textContent).toContain('未开启')
    expect(api.getMyPointLogs).not.toHaveBeenCalled()
  })
})
