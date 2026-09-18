import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getAddressesMock,
  getCartMock,
  createOrderMock,
  previewFreightMock,
  getAvailableCouponsMock,
  getPromotionPreviewMock,
} = vi.hoisted(() => ({
  getAddressesMock: vi.fn(),
  getCartMock: vi.fn(),
  createOrderMock: vi.fn(),
  previewFreightMock: vi.fn(),
  getAvailableCouponsMock: vi.fn(),
  getPromotionPreviewMock: vi.fn(),
}))

vi.mock('@/api/user', () => ({
  getAddresses: getAddressesMock,
  getCart: getCartMock,
  getRegions: vi.fn().mockResolvedValue({ data: { data: { regions: [] } } }),
  parseAddress: vi.fn(),
  createAddress: vi.fn(),
  updateAddress: vi.fn(),
  deleteAddress: vi.fn(),
  setDefaultAddress: vi.fn(),
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  addToCart: vi.fn(),
  changePassword: vi.fn(),
  getProfile: vi.fn(),
  updateProfile: vi.fn(),
  uploadImage: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  createOrder: createOrderMock,
  previewFreight: previewFreightMock,
}))

vi.mock('@/api/coupon', () => ({
  getAvailableCoupons: getAvailableCouponsMock,
  getPromotionPreview: getPromotionPreviewMock,
  getCouponCenter: vi.fn(),
  receiveCoupon: vi.fn(),
  getMyCoupons: vi.fn(),
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getProducts: vi.fn(),
  getProduct: vi.fn(),
  getHot: vi.fn(),
}))

vi.mock('@/api/auth', () => ({ getMe: vi.fn(), logout: vi.fn() }))

import CheckoutView from '@/views/CheckoutView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/checkout', component: { template: '<div />' } },
      { path: '/orders/:id/pay', component: { template: '<div />' } },
    ],
  })
}

const address = {
  id: 1, contact_name: '张三', contact_phone: '13800001111',
  province: '广东省', city: '深圳市', district: '南山区',
  detail_address: '科技路 1 号', label: null, is_default: true,
}

const cartItem = { id: 1, valid: true, title: '耳机', specs: {}, price: '150.00', quantity: 1, subtotal: '150.00', image: null, product_id: '01M2V91KBWXB2WV1MVCAB4TZKB' }

/** 可用券工厂（对齐 availableFor 的 usable 项） */
const usableCoupon = (o: Record<string, unknown> = {}) => ({
  user_coupon_id: 101,
  coupon_id: 1,
  name: '新人立减券',
  type: 'fixed',
  type_label: '满减券',
  amount: 10,
  percent: null,
  max_discount: null,
  min_spend: 50,
  scope: 'all',
  scope_label: '全场通用',
  expire_at: '2026-12-31 23:59:59',
  near_expiry: false,
  discount: 10,
  ...o,
})

const orderResult = (o: Record<string, unknown> = {}) => ({
  order_id: 900, order_no: 'CS900', total_amount: '150.00',
  freight_amount: '0.00', pay_amount: '140.00', status: 'pending_payment',
  discount_amount: '10.00', promotion_discount: '0', coupon_id: 1,
  amount_details: { v: 1 }, ...o,
})

/** 渲染结算页（默认 150 元商品 + 一张满 50 减 10 券 + 无满减活动） */
async function renderCheckout(over: { coupons?: unknown; promo?: unknown; cart?: unknown } = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.token = 'tk'
  const router = makeRouter()
  router.push('/checkout')
  await router.isReady()

  getAddressesMock.mockResolvedValue({ data: { data: [address] } })
  getCartMock.mockResolvedValue({ data: { data: { items: over.cart ?? [cartItem] } } })
  if ('coupons' in over) {
    getAvailableCouponsMock.mockResolvedValue({ data: { data: over.coupons } })
  }
  if ('promo' in over) {
    getPromotionPreviewMock.mockResolvedValue({ data: { data: { promotion: over.promo } } })
  }

  render(CheckoutView, { global: { plugins: [pinia, router] } })
  await waitFor(() => expect(screen.getByTestId('checkout-coupon-entry')).toBeTruthy())
  return { router }
}

beforeEach(() => {
  vi.clearAllMocks()
  // 默认：150 元商品 + 一张满 50 减 10 券 + 无满减活动
  getAddressesMock.mockResolvedValue({ data: { data: [address] } })
  getCartMock.mockResolvedValue({ data: { data: { items: [cartItem] } } })
  // 运费预览（T-053）：默认包邮，与旧「满 99 免运费」场景一致
  previewFreightMock.mockResolvedValue({
    data: { data: { freight_amount: '0.00', free_shipping: true, free_shipping_gap: null, not_support: false, detail: [] } },
  })
  getAvailableCouponsMock.mockResolvedValue({
    data: { data: { usable: [usableCoupon()], unusable: [] } },
  })
  getPromotionPreviewMock.mockResolvedValue({ data: { data: { promotion: null } } })
})

describe('结算页用券与金额明细（T-039）', () => {
  it('① 默认选中最优券（discount 最大者）并展示在明细区', async () => {
    getAvailableCouponsMock.mockResolvedValue({
      data: {
        data: {
          usable: [usableCoupon(), usableCoupon({ user_coupon_id: 102, name: '大额券', discount: 20 })],
          unusable: [],
        },
      },
    })
    await renderCheckout()

    // 默认选中 discount=20 的大额券 → 明细展示 −¥20
    await waitFor(() => expect(screen.getByTestId('checkout-coupon-amount').textContent).toContain('20.00'))
    expect(screen.getByTestId('checkout-coupon-row').textContent).toContain('大额券')
  })

  it('② 切换券后明细金额变化正确（固定数据断言）', async () => {
    getAvailableCouponsMock.mockResolvedValue({
      data: {
        data: {
          usable: [usableCoupon(), usableCoupon({ user_coupon_id: 102, name: '大额券', discount: 20 })],
          unusable: [],
        },
      },
    })
    const { router } = await renderCheckout()
    await waitFor(() => expect(screen.getByTestId('checkout-pay-amount').textContent).toContain('130.00'))

    // 打开弹层换成 ¥10 券
    await fireEvent.click(screen.getByTestId('checkout-coupon-entry'))
    await waitFor(() => expect(screen.getByTestId('checkout-coupon-dialog')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('coupon-option-101'))

    await waitFor(() => expect(screen.getByTestId('checkout-pay-amount').textContent).toContain('140.00'))
    expect(screen.getByTestId('checkout-coupon-row').textContent).toContain('新人立减券')

    // 「不使用优惠券」→ 优惠行隐藏，回退 150
    await fireEvent.click(screen.getByTestId('checkout-coupon-entry'))
    await waitFor(() => expect(screen.getByTestId('coupon-option-none')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('coupon-option-none'))
    await waitFor(() => expect(screen.getByTestId('checkout-pay-amount').textContent).toContain('150.00'))
    expect(screen.queryByTestId('checkout-coupon-row')).toBeNull()
    expect(router).toBeTruthy()
  })

  it('③ 不可用券分组展示原因', async () => {
    getAvailableCouponsMock.mockResolvedValue({
      data: {
        data: {
          usable: [usableCoupon()],
          unusable: [
            { user_coupon_id: 201, coupon_id: 2, name: '高门槛券', min_spend: 500, expire_at: '2026-12-31 23:59:59', reason: '未满使用门槛' },
            { user_coupon_id: 202, coupon_id: 3, name: '旧券', min_spend: 0, expire_at: '2026-01-01 00:00:00', reason: '已过期' },
          ],
        },
      },
    })
    await renderCheckout()

    await fireEvent.click(screen.getByTestId('checkout-coupon-entry'))
    await waitFor(() => expect(screen.getByTestId('checkout-coupon-dialog')).toBeTruthy())

    expect(screen.getByTestId('coupon-unusable-201').textContent).toContain('未满使用门槛')
    expect(screen.getByTestId('coupon-unusable-202').textContent).toContain('已过期')
  })

  it('④ 无券可领时不选券则隐藏优惠行（与 V1.0 一致）', async () => {
    getAvailableCouponsMock.mockResolvedValue({ data: { data: { usable: [], unusable: [] } } })
    getPromotionPreviewMock.mockResolvedValue({ data: { data: { promotion: null } } })
    await renderCheckout()

    expect(screen.queryByTestId('checkout-promo-row')).toBeNull()
    expect(screen.queryByTestId('checkout-coupon-row')).toBeNull()
    expect(screen.getByTestId('checkout-pay-amount').textContent).toContain('150.00')
    expect(screen.getByTestId('checkout-coupon-entry').textContent).toContain('暂无可用券')
  })

  it('⑤ 服务端金额与前端预览不一致时弹窗提示且以服务端为准', async () => {
    // 预览 140（150−10），服务端核算 135（模拟封顶差异）
    createOrderMock.mockResolvedValue({ data: { data: orderResult({ pay_amount: '135.00' }) } })
    const { router } = await renderCheckout()
    const push = vi.spyOn(router, 'replace')

    await fireEvent.click(screen.getByTestId('checkout-submit'))

    await waitFor(() => expect(screen.getByTestId('confirm-dialog')).toBeTruthy())
    expect(screen.getByTestId('confirm-dialog').textContent).toContain('¥135.00')
    expect(push).not.toHaveBeenCalled()

    // 确认后以服务端金额继续跳转支付页
    await fireEvent.click(screen.getByTestId('confirm-dialog-ok'))
    await waitFor(() => expect(push).toHaveBeenCalledWith('/orders/900/pay'))
  })

  it('⑥ 券失效异常态展示原因并提供「不使用优惠券继续下单」', async () => {
    createOrderMock
      .mockRejectedValueOnce(new Error('优惠券已被其他订单使用'))
      .mockResolvedValueOnce({ data: { data: orderResult({ pay_amount: '150.00', coupon_id: null }) } })
    const { router } = await renderCheckout()
    const push = vi.spyOn(router, 'replace')

    // 首次提交（带券）→ 券失效提示
    await fireEvent.click(screen.getByTestId('checkout-submit'))
    await waitFor(() => expect(screen.getByTestId('checkout-coupon-failed').textContent).toContain('优惠券已被其他订单使用'))
    expect(createOrderMock).toHaveBeenLastCalledWith(expect.objectContaining({ user_coupon_id: 101 }))

    // 降级：不带券重新提交成功
    await fireEvent.click(screen.getByTestId('checkout-continue-without-coupon'))
    await waitFor(() => expect(createOrderMock).toHaveBeenLastCalledWith(expect.not.objectContaining({ user_coupon_id: 101 })))
    await waitFor(() => expect(push).toHaveBeenCalledWith('/orders/900/pay'))
  })

  it('下单请求携带默认选中的 user_coupon_id（正常路径）', async () => {
    createOrderMock.mockResolvedValue({ data: { data: orderResult() } })
    const { router } = await renderCheckout()
    const push = vi.spyOn(router, 'replace')

    await fireEvent.click(screen.getByTestId('checkout-submit'))

    await waitFor(() => expect(createOrderMock).toHaveBeenCalledWith(expect.objectContaining({ user_coupon_id: 101 })))
    await waitFor(() => expect(push).toHaveBeenCalledWith('/orders/900/pay'))
  })

  it('满减与券同时生效时明细金额正确（150 − 满减10 − 券10 + 运费）', async () => {
    getPromotionPreviewMock.mockResolvedValue({
      data: {
        data: {
          promotion: {
            promotion_id: 1, name: '暑期满减', scope: 'all', scope_refs: [],
            base_amount: 150, current_tier: { min: 100, discount: 10 },
            discount: 10, next_tier: { min: 200, discount: 25 }, gap_to_next: 50,
          },
        },
      },
    })
    getCartMock.mockResolvedValue({ data: { data: { items: [{ ...cartItem, price: '150.00', quantity: 1, subtotal: '150.00' }] } } })
    getAvailableCouponsMock.mockResolvedValue({
      data: { data: { usable: [usableCoupon({ min_spend: 0, discount: 10 })], unusable: [] } },
    })
    await renderCheckout()

    // 150 商品（≥99 包邮）− 10 满减 − 10 券 = 130
    await waitFor(() => expect(screen.getByTestId('checkout-promo-amount').textContent).toContain('10.00'))
    expect(screen.getByTestId('checkout-coupon-amount').textContent).toContain('10.00')
    expect(screen.getByTestId('checkout-pay-amount').textContent).toContain('130.00')
  })

  it('⑦ 拉取券/满减预览时 items.product_id 传商品 public_id 字符串（P2-11，避免后端 422）', async () => {
    await renderCheckout()

    // 商品 id 必须是购物车出口的 public_id 原样字符串，不做 Number() 转换
    await waitFor(() => expect(getPromotionPreviewMock).toHaveBeenCalled())
    const promoArg = getPromotionPreviewMock.mock.calls[0][0] as { items: Array<{ product_id: unknown }> }
    expect(promoArg.items[0].product_id).toBe(cartItem.product_id)
    expect(typeof promoArg.items[0].product_id).toBe('string')

    const couponArg = getAvailableCouponsMock.mock.calls[0][0] as {
      amount: number
      items: Array<{ product_id: unknown }>
    }
    expect(couponArg.items[0].product_id).toBe(cartItem.product_id)
    expect(couponArg.amount).toBe(150)
  })
})
