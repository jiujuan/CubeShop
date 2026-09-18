import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getFavoritesMock,
  batchRemoveFavoritesMock,
  getHistoriesMock,
  clearHistoriesMock,
  favoriteProductMock,
  unfavoriteProductMock,
  trackProductMock,
  getProductMock,
} = vi.hoisted(() => ({
  getFavoritesMock: vi.fn(),
  batchRemoveFavoritesMock: vi.fn(),
  getHistoriesMock: vi.fn(),
  clearHistoriesMock: vi.fn(),
  favoriteProductMock: vi.fn(),
  unfavoriteProductMock: vi.fn(),
  trackProductMock: vi.fn(),
  getProductMock: vi.fn(),
}))

vi.mock('@/api/favorite', () => ({
  getFavorites: getFavoritesMock,
  batchRemoveFavorites: batchRemoveFavoritesMock,
  getHistories: getHistoriesMock,
  clearHistories: clearHistoriesMock,
  favoriteProduct: favoriteProductMock,
  unfavoriteProduct: unfavoriteProductMock,
  trackProduct: trackProductMock,
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getProducts: vi.fn(),
  getProduct: getProductMock,
  getHot: vi.fn(),
}))

vi.mock('@/api/review', () => ({
  getProductReviews: vi.fn().mockResolvedValue({ data: { data: { summary: { total: 0, avg: 0, star_counts: {}, good_rate: 0 }, list: [], pagination: {} } } }),
  submitReview: vi.fn(),
  updateReview: vi.fn(),
  getMyReviews: vi.fn(),
}))

vi.mock('@/api/user', () => ({
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
  changePassword: vi.fn(),
  getProfile: vi.fn(),
  updateProfile: vi.fn(),
  uploadImage: vi.fn(),
}))

vi.mock('@/api/notification', () => ({
  getUnreadCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  getNotifications: vi.fn().mockResolvedValue({ data: { data: { list: [], pagination: {} } } }),
  markRead: vi.fn(),
  markAllRead: vi.fn(),
}))

vi.mock('@/api/auth', () => ({
  getMe: vi.fn(),
  logout: vi.fn(),
}))

import FavoriteView from '@/views/FavoriteView.vue'
import HistoryView from '@/views/HistoryView.vue'
import DetailView from '@/views/DetailView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/product/:id', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/account/favorites', component: { template: '<div />' } },
      { path: '/account/histories', component: { template: '<div />' } },
    ],
  })
}

function login() {
  const auth = useAuthStore()
  auth.token = 'tk'
  return auth
}

const favItem = (over: Partial<Record<string, unknown>> = {}) => ({
  id: 1,
  title: '无线耳机',
  subtitle: null,
  main_image: null,
  price: '199.00',
  sales_count: 12,
  status: 1,
  is_available: true,
  unavailable_reason: null,
  ...over,
})

describe('我的收藏（FavoriteView / T-025）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('渲染收藏列表，失效商品灰显并展示原因', async () => {
    getFavoritesMock.mockResolvedValue({
      data: {
        data: {
          list: [
            favItem({ id: 1, title: '在售商品' }),
            favItem({ id: 2, title: '下架商品', is_available: false, unavailable_reason: '已下架' }),
          ],
          pagination: { page: 1, page_size: 20, total: 2, total_pages: 1 },
        },
      },
    })
    login()
    render(FavoriteView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByTestId('fav-item-2')).toBeTruthy())
    expect(screen.getByTestId('fav-unavailable-2').textContent).toContain('已下架')
    expect(screen.getByTestId('fav-item-1')).toBeTruthy()
  })

  it('勾选后批量取消收藏，调用接口并刷新', async () => {
    getFavoritesMock.mockResolvedValue({
      data: { data: { list: [favItem({ id: 5 }), favItem({ id: 6 })], pagination: { page: 1, page_size: 20, total: 2, total_pages: 1 } } },
    })
    batchRemoveFavoritesMock.mockResolvedValue({ data: { data: { removed: 2 } } })
    login()
    render(FavoriteView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByTestId('fav-check-5')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('fav-check-5'))
    await fireEvent.click(screen.getByTestId('fav-check-6'))
    await waitFor(() => expect(screen.getByTestId('batch-remove-btn')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('batch-remove-btn'))
    await waitFor(() => expect(screen.getByTestId('confirm-dialog-ok')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('confirm-dialog-ok'))

    await waitFor(() => expect(batchRemoveFavoritesMock).toHaveBeenCalledWith([5, 6]))
    await waitFor(() => expect(screen.getByTestId('fav-tip').textContent).toContain('已取消收藏 2'))
  })

  it('无收藏时展示空态', async () => {
    getFavoritesMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 20, total: 0, total_pages: 1 } } },
    })
    login()
    render(FavoriteView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByTestId('fav-empty')).toBeTruthy())
  })
})

function localStr(msOffsetFromMidnight: number) {
  const now = new Date()
  const dayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
  const d = new Date(dayStart + msOffsetFromMidnight)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

describe('浏览足迹（HistoryView / T-025）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('按今天/昨天/更早分组渲染', async () => {
    getHistoriesMock.mockResolvedValue({
      data: {
        data: {
          list: [
            { ...favItem({ id: 1 }), browsed_at: localStr(3600000) }, // 今天 01:00
            { ...favItem({ id: 2 }), browsed_at: localStr(-3600000) }, // 昨天 23:00
            { ...favItem({ id: 3 }), browsed_at: localStr(-86400000 * 3) }, // 3 天前
          ],
          pagination: { page: 1, page_size: 90, total: 3, total_pages: 1 },
        },
      },
    })
    login()
    render(HistoryView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByTestId('history-group-today')).toBeTruthy())
    expect(screen.getByTestId('history-group-yesterday')).toBeTruthy()
    expect(screen.getByTestId('history-group-earlier')).toBeTruthy()
  })

  it('清空足迹需二次确认', async () => {
    getHistoriesMock.mockResolvedValue({
      data: { data: { list: [{ ...favItem({ id: 9 }), browsed_at: localStr(0) }], pagination: { page: 1, page_size: 90, total: 1, total_pages: 1 } } },
    })
    clearHistoriesMock.mockResolvedValue({ data: { data: { removed: 1 } } })
    login()
    render(HistoryView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByTestId('clear-history-btn')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('clear-history-btn'))
    await waitFor(() => expect(screen.getByTestId('confirm-dialog-ok')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('confirm-dialog-ok'))

    await waitFor(() => expect(clearHistoriesMock).toHaveBeenCalled())
    await waitFor(() => expect(screen.getByTestId('history-empty')).toBeTruthy())
  })

  it('无足迹时展示空态', async () => {
    getHistoriesMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 90, total: 0, total_pages: 1 } } },
    })
    login()
    render(HistoryView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByTestId('history-empty')).toBeTruthy())
  })
})

const detailProduct = {
  id: 7,
  title: '无线耳机',
  subtitle: null,
  main_image: null,
  images: [],
  description: null,
  price: '199.00',
  sales_count: 3,
  status: 1,
  category: null,
  total_stock: 5,
  skus: [{ id: 70, sku_code: 'S7', specs: { 颜色: '黑' }, price: '199.00', stock: 5, status: 1 }],
  is_favorited: false,
}

describe('商品详情收藏（DetailView / T-025）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    getProductMock.mockResolvedValue({ data: { data: detailProduct } })
    favoriteProductMock.mockResolvedValue({ data: { data: { favorited: true } } })
    unfavoriteProductMock.mockResolvedValue({ data: { data: { favorited: false } } })
    trackProductMock.mockResolvedValue({ data: { data: null } })
  })

  it('未收藏点击后调用收藏接口并切换为已收藏', async () => {
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    await router.push('/product/7')
    await router.isReady()
    render(DetailView, { global: { plugins: [router] } })

    await waitFor(() => expect(screen.getByTestId('favorite-btn')).toBeTruthy())
    expect(screen.getByTestId('favorite-btn').textContent).toContain('收藏')
    await fireEvent.click(screen.getByTestId('favorite-btn'))

    await waitFor(() => expect(favoriteProductMock).toHaveBeenCalledWith(7))
    await waitFor(() => expect(screen.getByTestId('favorite-btn').textContent).toContain('已收藏'))
    // 已登录应上报浏览足迹
    await waitFor(() => expect(trackProductMock).toHaveBeenCalledWith(7))
  })

  it('未登录点击收藏跳转登录并带 redirect', async () => {
    // 未设置 token
    const router = makeRouter()
    await router.push('/product/7')
    await router.isReady()
    render(DetailView, { global: { plugins: [router] } })

    await waitFor(() => expect(screen.getByTestId('favorite-btn')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('favorite-btn'))

    await waitFor(() => expect(router.currentRoute.value.path).toBe('/login'))
    expect(router.currentRoute.value.query.redirect).toBe('/product/7')
    expect(favoriteProductMock).not.toHaveBeenCalled()
  })
})
