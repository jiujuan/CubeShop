import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getMyCouponsMock,
  getCategoriesMock,
  getCartCountMock,
} = vi.hoisted(() => ({
  getMyCouponsMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getCartCountMock: vi.fn(),
}))

vi.mock('@/api/coupon', () => ({
  getMyCoupons: getMyCouponsMock,
  getCouponCenter: vi.fn(),
  receiveCoupon: vi.fn(),
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

import MyCouponsView from '@/views/MyCouponsView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/coupons/mine', component: { template: '<div />' } },
      { path: '/orders/:id', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
    ],
  })
}

/** 用户券工厂（对齐 StorefrontCouponController::formatUserCoupon 输出） */
const uc = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  coupon_id: 1,
  name: '新人满减券',
  type: 'fixed',
  type_label: '满减券',
  amount: 20,
  percent: null,
  max_discount: null,
  min_spend: 100,
  scope: 'all',
  scope_label: '全场通用',
  status: 'unused',
  status_label: '未使用',
  expire_at: '2026-12-31 23:59:59',
  near_expiry: false,
  used_order_id: null,
  used_at: null,
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

async function renderMine(login = true) {
  const pinia = setupAuth(login)
  const router = makeRouter()
  router.push('/coupons/mine')
  await router.isReady()
  render(MyCouponsView, { global: { plugins: [pinia, router] } })
  await waitFor(() => expect(screen.getByTestId('mycoupon-tabs')).toBeTruthy())
  return { router }
}

const page1 = {
  pagination: { page: 1, page_size: 10, total: 2, total_pages: 1 },
}

beforeEach(() => {
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getMyCouponsMock.mockResolvedValue({
    data: {
      data: {
        list: [
          uc(),
          uc({ id: 2, status: 'used', status_label: '已使用', used_order_id: 66, used_at: '2026-09-15 12:00:00', expire_at: '2026-09-30 23:59:59' }),
        ],
        pagination: page1.pagination,
      },
    },
  })
})

describe('我的优惠券 MyCouponsView', () => {
  it('渲染券列表（未使用/已使用两种状态卡片）', async () => {
    await renderMine()

    expect(screen.getByTestId('mycoupon-card-1')).toBeTruthy()
    expect(screen.getByTestId('mycoupon-card-2')).toBeTruthy()
    expect(screen.getByTestId('mycoupon-status-1').textContent).toBe('未使用')
    expect(screen.getByTestId('mycoupon-status-2').textContent).toBe('已使用')
    expect(screen.getByTestId('mycoupon-card-1').textContent).toContain('新人满减券')
    expect(screen.getByTestId('mycoupon-card-1').textContent).toContain('¥20')
  })

  it('未使用券展示「去使用」，已使用券展示「查看订单」并可跳转', async () => {
    const { router } = await renderMine()
    const push = vi.spyOn(router, 'push')

    expect(screen.getByTestId('mycoupon-use-1')).toBeTruthy()
    await fireEvent.click(screen.getByTestId('mycoupon-order-2'))

    await waitFor(() => expect(push).toHaveBeenCalledWith('/orders/66'))
  })

  it('临近过期标红提示', async () => {
    getMyCouponsMock.mockResolvedValue({
      data: {
        data: {
          list: [uc({ id: 3, near_expiry: true, expire_at: '2026-09-18 23:59:59' })],
          pagination: page1.pagination,
        },
      },
    })
    await renderMine()

    const expire = screen.getByTestId('mycoupon-expire-3')
    expect(expire.textContent).toContain('即将过期')
    expect(expire.className).toContain('text-[#ff4d4f]')
  })

  it('切换「未使用」Tab 携带 status=unused 并重置页码', async () => {
    await renderMine()

    await fireEvent.click(screen.getByTestId('mycoupon-tab-unused'))

    await waitFor(() => {
      const last = getMyCouponsMock.mock.calls.at(-1)![0]
      expect(last.status).toBe('unused')
      expect(last.page).toBe(1)
    })
  })

  it('切换「已过期」Tab 携带 status=expired', async () => {
    await renderMine()

    await fireEvent.click(screen.getByTestId('mycoupon-tab-expired'))

    await waitFor(() => {
      const last = getMyCouponsMock.mock.calls.at(-1)![0]
      expect(last.status).toBe('expired')
    })
  })

  it('「全部」Tab status 为 null', async () => {
    await renderMine()

    await fireEvent.click(screen.getByTestId('mycoupon-tab-all'))

    await waitFor(() => {
      const last = getMyCouponsMock.mock.calls.at(-1)![0]
      expect(last.status).toBeNull()
    })
  })

  it('分页：下一页翻页并携带 page=2，末页禁用', async () => {
    getMyCouponsMock.mockResolvedValue({
      data: {
        data: {
          list: [uc({ id: 11 })],
          pagination: { page: 1, page_size: 10, total: 11, total_pages: 2 },
        },
      },
    })
    await renderMine()

    expect(screen.getByTestId('pager-prev').hasAttribute('disabled')).toBe(true)
    await fireEvent.click(screen.getByTestId('pager-next'))

    await waitFor(() => {
      const last = getMyCouponsMock.mock.calls.at(-1)![0]
      expect(last.page).toBe(2)
    })
    await waitFor(() => expect(screen.getByTestId('pager-next').hasAttribute('disabled')).toBe(true))
  })

  it('无券时展示空态', async () => {
    getMyCouponsMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 10, total: 0, total_pages: 1 } } },
    })
    await renderMine()

    expect(screen.getByTestId('mycoupon-empty').textContent).toBe('暂无优惠券')
  })

  it('未登录展示「请先登录」', async () => {
    const pinia = setupAuth(false)
    const router = makeRouter()
    router.push('/coupons/mine')
    await router.isReady()
    render(MyCouponsView, { global: { plugins: [pinia, router] } })

    await waitFor(() => expect(screen.getByText('请先登录')).toBeTruthy())
    expect(getMyCouponsMock).not.toHaveBeenCalled()
  })
})
