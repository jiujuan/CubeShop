import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const api = vi.hoisted(() => ({
  getProfile: vi.fn(),
  updateProfile: vi.fn(),
  changePassword: vi.fn(),
  uploadImage: vi.fn(),
  getUnreadCount: vi.fn(),
  getBalance: vi.fn(),
  getRecharges: vi.fn(),
  getBalanceLogs: vi.fn(),
  getCheckinStatus: vi.fn(),
  postCheckin: vi.fn(),
}))

vi.mock('@/api/points', () => ({
  getCheckinStatus: api.getCheckinStatus,
  postCheckin: api.postCheckin,
}))

vi.mock('@/api/balance', () => ({
  getBalance: api.getBalance,
  createRecharge: vi.fn(),
  getRecharges: api.getRecharges,
  getBalanceLogs: api.getBalanceLogs,
}))

vi.mock('@/api/user', () => ({
  getProfile: api.getProfile,
  updateProfile: api.updateProfile,
  changePassword: api.changePassword,
  uploadImage: api.uploadImage,
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  addToCart: vi.fn(),
  getCart: vi.fn(),
  getAddresses: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getRegions: vi.fn().mockResolvedValue({ data: { data: { regions: [] } } }),
  parseAddress: vi.fn(),
  createAddress: vi.fn(),
  updateAddress: vi.fn(),
  deleteAddress: vi.fn(),
  setDefaultAddress: vi.fn(),
}))

vi.mock('@/api/notification', () => ({
  getUnreadCount: api.getUnreadCount,
  getNotifications: vi.fn(),
  markRead: vi.fn(),
  markAllRead: vi.fn(),
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getProducts: vi.fn(),
  getProduct: vi.fn(),
  getHot: vi.fn(),
  getAttributes: vi.fn(),
  getBrands: vi.fn(),
}))

vi.mock('@/api/auth', () => ({
  getMe: vi.fn(),
  logout: vi.fn().mockResolvedValue(undefined),
}))

import AccountCenterView from '@/views/AccountCenterView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/orders', component: { template: '<div />' } },
      { path: '/account', name: 'account', component: { template: '<div />' } },
      { path: '/account/favorites', component: { template: '<div />' } },
      { path: '/account/histories', component: { template: '<div />' } },
      { path: '/account/addresses', component: { template: '<div />' } },
      { path: '/notifications', component: { template: '<div />' } },
      { path: '/balance/recharge', name: 'balance-recharge', component: { template: '<div />' } },
    ],
  })
}

const profile = {
  id: 1, username: 'buyer01', nickname: '小明', avatar: null,
  phone: '13800001111', email: null, roles: ['customer'],
}

const baseStatus = {
  date: '2026-09-27',
  checked: false,
  streak: 3,
  today_points: 11,
  next_points: 13,
  total_days: 20,
  balance: 150,
  milestone_days: [7],
  available: true,
}

describe('账户中心签到卡片（S2）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    api.getProfile.mockResolvedValue({ data: { data: profile } })
    api.getUnreadCount.mockResolvedValue({ data: { data: { count: 0 } } })
    api.getBalance.mockResolvedValue({ data: { data: { balance: '0.00', total_recharge: '0.00', total_consume: '0.00' } } })
    api.getRecharges.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 5, total: 0, total_pages: 1 } } } })
    api.getBalanceLogs.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 5, total: 0, total_pages: 1 } } } })
    api.getCheckinStatus.mockResolvedValue({ data: { data: { ...baseStatus } } })
  })

  function mountCenter() {
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    router.push('/account')
    return render(AccountCenterView, { global: { plugins: [router] } })
  }

  it('展示连续天数、今日/明日可得、累计天数与可用积分', async () => {
    mountCenter()
    await waitFor(() => expect(screen.getByTestId('checkin-card')).toBeTruthy())

    expect(screen.getByTestId('checkin-streak').textContent).toContain('已连续 3 天')
    const cardText = screen.getByTestId('checkin-card').textContent
    expect(cardText).toContain('+11')
    expect(cardText).toContain('+13')
    expect(cardText).toContain('累计 20 天')
    expect(cardText).toContain('可用积分 150')
  })

  it('点击签到调用接口并刷新为「今日已签」状态', async () => {
    api.postCheckin.mockResolvedValue({
      data: {
        data: {
          points: 11, streak: 4, date: '2026-09-27',
          checked: true, today_points: 11, next_points: 13,
          total_days: 21, balance: 161, milestone_days: [7], available: true,
        },
      },
    })
    mountCenter()
    await waitFor(() => expect(screen.getByTestId('checkin-btn')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('checkin-btn'))
    await waitFor(() => expect(api.postCheckin).toHaveBeenCalled())

    // 实发积分提示
    await waitFor(() => expect(screen.getByTestId('account-tip').textContent).toContain('签到成功'))
    // 卡片刷新为已签
    await waitFor(() => expect(screen.getByTestId('checkin-btn').textContent).toContain('今日已签'))
    expect(screen.getByTestId('checkin-streak').textContent).toContain('已连续 4 天')
    expect(screen.getByTestId('checkin-card').textContent).toContain('可用积分 161')
  })

  it('签到功能未开启时展示禁用提示', async () => {
    api.getCheckinStatus.mockResolvedValue({ data: { data: { ...baseStatus, available: false } } })
    mountCenter()
    await waitFor(() => expect(screen.getByTestId('checkin-disabled')).toBeTruthy())
    expect(screen.getByTestId('checkin-disabled').textContent).toContain('暂未开放')
  })

  it('重复签到（冲突）刷新卡片并提示', async () => {
    api.postCheckin.mockRejectedValue(new Error('该日期已签到'))
    api.getCheckinStatus.mockResolvedValueOnce({ data: { data: { ...baseStatus } } })
    // 冲突后回拉状态：变为已签
    api.getCheckinStatus.mockResolvedValueOnce({ data: { data: { ...baseStatus, checked: true, streak: 3 } } })

    mountCenter()
    await waitFor(() => expect(screen.getByTestId('checkin-btn')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('checkin-btn'))

    await waitFor(() => expect(screen.getByTestId('account-tip').textContent).toContain('该日期已签到'))
    await waitFor(() => expect(screen.getByTestId('checkin-btn').textContent).toContain('今日已签'))
  })
})
