import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getCouponCenterMock,
  receiveCouponMock,
  getCategoriesMock,
  getCartCountMock,
} = vi.hoisted(() => ({
  getCouponCenterMock: vi.fn(),
  receiveCouponMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getCartCountMock: vi.fn(),
}))

vi.mock('@/api/coupon', () => ({
  getCouponCenter: getCouponCenterMock,
  receiveCoupon: receiveCouponMock,
  getMyCoupons: vi.fn(),
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

import CouponCenterView from '@/views/CouponCenterView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/coupons/center', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
    ],
  })
}

/** 券工厂（对齐 CouponService::receivableCoupons 输出） */
const coupon = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  name: '新人满减券',
  type: 'fixed',
  type_label: '满减券',
  amount: 20,
  percent: null,
  max_discount: null,
  min_spend: 100,
  scope: 'all',
  scope_label: '全场通用',
  valid_type: 'absolute',
  valid_to: '2026-12-31 23:59:59',
  valid_days: null,
  total_count: 1000,
  remaining: 500,
  received_by_me: 0,
  can_receive: true,
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

async function renderCenter(login = true) {
  const pinia = setupAuth(login)
  const router = makeRouter()
  router.push('/coupons/center')
  await router.isReady()
  render(CouponCenterView, { global: { plugins: [pinia, router] } })
  await waitFor(() =>
    expect(document.querySelector('[data-testid^="coupon-card-"]') ?? screen.queryByTestId('center-empty')).toBeTruthy(),
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getCouponCenterMock.mockResolvedValue({
    data: { data: { list: [coupon()] } },
  })
})

describe('领券中心 CouponCenterView', () => {
  it('渲染券卡片（面额/门槛/范围/剩余）', async () => {
    await renderCenter()

    expect(screen.getByTestId('coupon-card-1')).toBeTruthy()
    expect(screen.getByTestId('coupon-value-1').textContent).toContain('¥20')
    expect(screen.getByTestId('coupon-value-1').textContent).toContain('满100可用')
    expect(screen.getByTestId('coupon-card-1').textContent).toContain('新人满减券')
    expect(screen.getByTestId('coupon-card-1').textContent).toContain('全场通用')
    expect(screen.getByTestId('coupon-receive-1')).toBeTruthy()
  })

  it('折扣券展示折数文案', async () => {
    getCouponCenterMock.mockResolvedValue({
      data: { data: { list: [coupon({ id: 2, type: 'percent', percent: 85, amount: null, max_discount: 30, name: '会员折扣券' })] } },
    })
    await renderCenter()

    expect(screen.getByTestId('coupon-value-2').textContent).toContain('8.5折')
  })

  it('余量为 0 展示「已抢光」且无领取按钮', async () => {
    getCouponCenterMock.mockResolvedValue({
      data: { data: { list: [coupon({ remaining: 0 })] } },
    })
    await renderCenter()

    expect(screen.getByTestId('coupon-soldout-1')).toBeTruthy()
    expect(screen.queryByTestId('coupon-receive-1')).toBeNull()
  })

  it('达到限领展示「已领取」且无领取按钮', async () => {
    getCouponCenterMock.mockResolvedValue({
      data: { data: { list: [coupon({ received_by_me: 1, can_receive: false })] } },
    })
    await renderCenter()

    expect(screen.getByTestId('coupon-received-1')).toBeTruthy()
    expect(screen.queryByTestId('coupon-receive-1')).toBeNull()
  })

  it('点击领取：调用接口 + 展示成功 Toast', async () => {
    receiveCouponMock.mockResolvedValue({
      data: { data: { user_coupon_id: 9, coupon_id: 1, expire_at: '2026-12-31 23:59:59' } },
    })
    await renderCenter()

    await fireEvent.click(screen.getByTestId('coupon-receive-1'))

    await waitFor(() => expect(receiveCouponMock).toHaveBeenCalledWith(1))
    await waitFor(() => expect(screen.getByTestId('center-flash').textContent).toContain('领取成功'))
  })

  it('领取成功后刷新列表同步状态（can_receive=false → 已领取）', async () => {
    receiveCouponMock.mockResolvedValue({
      data: { data: { user_coupon_id: 9, coupon_id: 1, expire_at: '2026-12-31 23:59:59' } },
    })
    await renderCenter()
    // 第二次拉取（领取后刷新）：已达到限领
    getCouponCenterMock.mockResolvedValue({
      data: { data: { list: [coupon({ received_by_me: 1, can_receive: false })] } },
    })

    await fireEvent.click(screen.getByTestId('coupon-receive-1'))

    await waitFor(() => expect(getCouponCenterMock).toHaveBeenCalledTimes(2))
    await waitFor(() => expect(screen.getByTestId('coupon-received-1')).toBeTruthy())
  })

  it('领取失败展示错误 Toast（后端冲突文案）', async () => {
    receiveCouponMock.mockRejectedValue(new Error('已达每人限领数量'))
    await renderCenter()

    await fireEvent.click(screen.getByTestId('coupon-receive-1'))

    await waitFor(() => expect(screen.getByTestId('center-flash').textContent).toContain('已达每人限领数量'))
    // 失败后按钮恢复可点
    await waitFor(() => expect(screen.getByTestId('coupon-receive-1')).toBeTruthy())
  })

  it('无券时展示空态', async () => {
    getCouponCenterMock.mockResolvedValue({ data: { data: { list: [] } } })
    await renderCenter()

    expect(screen.getByTestId('center-empty').textContent).toBe('暂无可领优惠券')
  })

  it('未登录展示登录提示，券面可浏览但领取跳登录', async () => {
    const pinia = setupAuth(false)
    const router = makeRouter()
    router.push('/coupons/center')
    await router.isReady()
    const push = vi.spyOn(router, 'push')
    render(CouponCenterView, { global: { plugins: [pinia, router] } })

    await waitFor(() => expect(screen.getByTestId('center-login-tip')).toBeTruthy())
    // 券面仍展示（后端对未登录返回 can_receive=true）
    await waitFor(() => expect(screen.getByTestId('coupon-receive-1')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('coupon-receive-1'))
    await waitFor(() => expect(push).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/coupons/center' } }))
    expect(receiveCouponMock).not.toHaveBeenCalled()
  })
})
