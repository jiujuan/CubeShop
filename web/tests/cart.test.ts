import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

/**
 * 购物车链路回归（2026-09-19）
 *
 * 修复前三个问题：
 * 1. 购物车页增删商品后，顶栏购物车角标数量不变；
 * 2. 列表页/首页点「加入购物车」后角标不增加；
 * 3. 删除/改数量走整页 `load()`（loading 闪烁、运费重算、选中态丢失），且购物车不能勾选部分结算。
 *
 * 修复手法：角标收敛到 `stores/cart.ts`（单一真源）+ 增删改就地更新 + 勾选结算。
 * 本文件锁定这三点契约。
 */

const {
  getCartMock,
  getAddressesMock,
  getCartCountMock,
  addToCartMock,
  updateCartItemMock,
  removeCartItemMock,
  clearCartMock,
  estimateFreightMock,
  getProductMock,
} = vi.hoisted(() => ({
  getCartMock: vi.fn(),
  getAddressesMock: vi.fn(),
  getCartCountMock: vi.fn(),
  addToCartMock: vi.fn(),
  updateCartItemMock: vi.fn(),
  removeCartItemMock: vi.fn(),
  clearCartMock: vi.fn(),
  estimateFreightMock: vi.fn(),
  getProductMock: vi.fn(),
}))

vi.mock('@/api/user', () => ({
  getCart: getCartMock,
  getAddresses: getAddressesMock,
  getCartCount: getCartCountMock,
  addToCart: addToCartMock,
  updateCartItem: updateCartItemMock,
  removeCartItem: removeCartItemMock,
  clearCart: clearCartMock,
}))

vi.mock('@/api/order', () => ({
  estimateFreight: estimateFreightMock,
  previewFreight: vi.fn(),
  createOrder: vi.fn(),
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getProduct: getProductMock,
}))

vi.mock('@/api/announcement', () => ({
  getAnnouncements: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
}))

vi.mock('@/api/notification', () => ({
  getUnreadCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  getNotifications: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
  markNotificationsRead: vi.fn(),
}))

vi.mock('@/api/auth', () => ({ getMe: vi.fn(), logout: vi.fn() }))

import CartView from '@/views/CartView.vue'
import ProductCard from '@/components/ProductCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/cart', component: { template: '<div />' } },
      { path: '/checkout', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/account', component: { template: '<div />' } },
      { path: '/orders', component: { template: '<div />' } },
    ],
  })
}

/** 三个行：两个有效 + 一个失效（失效项不可勾选、不计入合计） */
const cartPayload = () => ({
  data: {
    data: {
      items: [
        { id: 1, sku_id: 'S1', product_id: 'P1', title: '耳机', specs: {}, image: null, price: '150.00', quantity: 1, stock: 10, valid: true, invalid_reason: null, subtotal: '150.00' },
        { id: 2, sku_id: 'S2', product_id: 'P2', title: '水杯', specs: {}, image: null, price: '20.00', quantity: 3, stock: 10, valid: true, invalid_reason: null, subtotal: '60.00' },
        { id: 3, sku_id: 'S3', product_id: 'P3', title: '下架品', specs: {}, image: null, price: '20.00', quantity: 2, stock: 0, valid: false, invalid_reason: '商品已下架', subtotal: '40.00' },
      ],
      total_amount: '210.00',
      total_quantity: 4,
    },
  },
})

async function mountCart() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.token = 'tk'
  const router = makeRouter()
  router.push('/cart')
  await router.isReady()
  const pushSpy = vi.spyOn(router, 'push')

  render(CartView, { global: { plugins: [router, pinia] } })
  await waitFor(() => expect(screen.getByTestId('cart-item-1')).toBeTruthy())
  return { router, pushSpy, pinia }
}

beforeEach(() => {
  vi.clearAllMocks()
  getCartMock.mockResolvedValue(cartPayload())
  getAddressesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 4 } } })
  estimateFreightMock.mockResolvedValue({ data: { data: { freight_amount: '0.00', free_shipping: true, not_support: false } } })
  updateCartItemMock.mockResolvedValue({ data: { data: null } })
  removeCartItemMock.mockResolvedValue({ data: { data: null } })
  clearCartMock.mockResolvedValue({ data: { data: null } })
  addToCartMock.mockResolvedValue({ data: { data: null } })
})

describe('购物车角标（cart store 单一真源）', () => {
  it('add() 后回源刷新角标', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().token = 'tk'
    const cart = useCartStore()

    getCartCountMock.mockResolvedValue({ data: { data: { count: 5 } } })
    await cart.refresh()
    expect(cart.count).toBe(5)

    getCartCountMock.mockResolvedValue({ data: { data: { count: 6 } } })
    await cart.add('01ABCDEF', 1)
    expect(addToCartMock).toHaveBeenCalledWith('01ABCDEF', 1)
    expect(cart.count).toBe(6)
  })

  it('未登录时不请求接口，角标为 0', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const cart = useCartStore()
    cart.count = 9

    await cart.refresh()
    expect(cart.count).toBe(0)
    expect(getCartCountMock).not.toHaveBeenCalled()
  })

  it('商品卡加购后角标同步（问题②：列表页/首页加购不涨数）', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    router.push('/')
    await router.isReady()

    getProductMock.mockResolvedValue({ data: { data: { skus: [{ id: 'SKU1', stock: 9 }] } } })
    getCartCountMock.mockResolvedValue({ data: { data: { count: 3 } } })

    render(ProductCard, {
      props: { product: { id: 'P1', title: '耳机', price: '150.00', subtitle: '', main_image: null, sales_count: 1 } as never, layout: 'home' },
      global: { plugins: [router, pinia] },
    })

    await fireEvent.click(screen.getByTitle('加入购物车'))
    await waitFor(() => expect(addToCartMock).toHaveBeenCalledWith('SKU1', 1))
    // 角标已回源刷新（问题②的修复点）
    await waitFor(() => expect(useCartStore().count).toBe(3))
    expect(getCartCountMock).toHaveBeenCalled()
  })
})

describe('购物车勾选结算（问题④）', () => {
  it('默认全选有效项，合计只算有效项、失效项不可勾选', async () => {
    await mountCart()

    expect((screen.getByTestId('cart-check-1') as HTMLInputElement).checked).toBe(true)
    expect((screen.getByTestId('cart-check-2') as HTMLInputElement).checked).toBe(true)
    // 失效项无勾选框
    expect(screen.queryByTestId('cart-check-3')).toBeNull()
    // 150 + 20×3 = 210，失效行 40 不计入
    expect(screen.getByTestId('cart-total-amount').textContent).toContain('210.00')
    expect(screen.getByTestId('cart-selected-quantity').textContent).toBe('4')
  })

  it('取消勾选某行后，已选件数与合计同步减少', async () => {
    await mountCart()

    await fireEvent.click(screen.getByTestId('cart-check-2'))
    await waitFor(() => expect(screen.getByTestId('cart-total-amount').textContent).toContain('150.00'))
    expect(screen.getByTestId('cart-selected-quantity').textContent).toBe('1')
  })

  it('全选可一键取消 / 恢复', async () => {
    await mountCart()

    await fireEvent.click(screen.getByTestId('cart-select-all'))
    await waitFor(() => expect(screen.getByTestId('cart-selected-quantity').textContent).toBe('0'))
    // 全部取消后去结算应禁用
    expect((screen.getByTestId('cart-checkout') as HTMLButtonElement).disabled).toBe(true)

    await fireEvent.click(screen.getByTestId('cart-select-all'))
    await waitFor(() => expect(screen.getByTestId('cart-selected-quantity').textContent).toBe('4'))
  })

  it('去结算只带勾选行的 cart_item_ids', async () => {
    const { pushSpy } = await mountCart()

    await fireEvent.click(screen.getByTestId('cart-check-2'))
    await fireEvent.click(screen.getByTestId('cart-checkout'))

    expect(pushSpy).toHaveBeenCalledWith({ path: '/checkout', query: { cart_item_ids: '1' } })
  })
})

describe('购物车增删改：就地更新（问题③，不整页重载）', () => {
  it('删除商品后行消失、合计重算，且不重新拉取购物车（无 loading 闪烁）', async () => {
    await mountCart()
    expect(getCartMock).toHaveBeenCalledTimes(1)

    await fireEvent.click(screen.getByTestId('cart-remove-1'))

    await waitFor(() => expect(screen.queryByTestId('cart-item-1')).toBeNull())
    expect(removeCartItemMock).toHaveBeenCalledWith(1)
    // 不整页重载
    expect(getCartMock).toHaveBeenCalledTimes(1)
    // 剩余勾选项：仅 20×3 = 60
    await waitFor(() => expect(screen.getByTestId('cart-total-amount').textContent).toContain('60.00'))
    // 角标同步（问题①的修复点）
    expect(getCartCountMock).toHaveBeenCalled()
  })

  it('改数量后就地重算小计与合计，且不重新拉取购物车', async () => {
    await mountCart()

    await fireEvent.click(screen.getByTestId('cart-item-2').querySelectorAll('button')[1])

    await waitFor(() => expect(updateCartItemMock).toHaveBeenCalledWith(2, 4))
    await waitFor(() => expect(screen.getByTestId('cart-subtotal-2').textContent).toContain('80.00'))
    // 150 + 20×4 = 230
    await waitFor(() => expect(screen.getByTestId('cart-total-amount').textContent).toContain('230.00'))
    expect(getCartMock).toHaveBeenCalledTimes(1)
  })

  it('删除失败时给出提示且不动本地行', async () => {
    removeCartItemMock.mockRejectedValue(new Error('删除失败：网络异常'))
    await mountCart()

    await fireEvent.click(screen.getByTestId('cart-remove-1'))

    await waitFor(() => expect(screen.getByText('删除失败：网络异常')).toBeTruthy())
    expect(screen.getByTestId('cart-item-1')).toBeTruthy()
  })
})

describe('购物车金额口径', () => {
  it('按「分」累加，规避浮点误差（0.1×3 类场景）', async () => {
    getCartMock.mockResolvedValue({
      data: {
        data: {
          items: [
            { id: 1, sku_id: 'S1', product_id: 'P1', title: 'A', specs: {}, image: null, price: '0.10', quantity: 3, stock: 9, valid: true, invalid_reason: null, subtotal: '0.30' },
            { id: 2, sku_id: 'S2', product_id: 'P2', title: 'B', specs: {}, image: null, price: '0.20', quantity: 3, stock: 9, valid: true, invalid_reason: null, subtotal: '0.60' },
          ],
          total_amount: '0.90',
          total_quantity: 6,
        },
      },
    })
    await mountCart()

    // 0.1×3 + 0.2×3 = 0.9（浮点直加会得到 0.8999999999999999）
    expect(screen.getByTestId('cart-total-amount').textContent).toContain('0.90')
  })
})
