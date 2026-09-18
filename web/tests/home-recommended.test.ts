import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const { getHotMock, getRecommendedMock, getCategoriesMock, getAnnouncementsMock, getBannersMock } = vi.hoisted(() => ({
  getHotMock: vi.fn(),
  getRecommendedMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getAnnouncementsMock: vi.fn(),
  getBannersMock: vi.fn(),
}))

vi.mock('@/api/shop', () => ({
  getCategories: getCategoriesMock,
  getHot: getHotMock,
  getRecommended: getRecommendedMock,
  getProduct: vi.fn(),
}))

vi.mock('@/api/announcement', () => ({ getAnnouncements: getAnnouncementsMock }))
vi.mock('@/api/banner', () => ({ getBanners: getBannersMock }))
vi.mock('@/api/user', () => ({ addToCart: vi.fn(), getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }) }))

// 页头/页脚依赖较多接口，用空壳替身隔离，只验证首页主体
vi.mock('@/components/ShopHeader.vue', () => ({ default: { template: '<div data-testid="shop-header" />' } }))
vi.mock('@/components/ShopFooter.vue', () => ({ default: { template: '<div data-testid="shop-footer" />' } }))

import HomeView from '@/views/HomeView.vue'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/search', component: { template: '<div />' } },
      { path: '/product/:id', component: { template: '<div />' } },
      { path: '/orders', component: { template: '<div />' } },
      { path: '/category/:id', component: { template: '<div />' } },
      { path: '/announcements', component: { template: '<div />' } },
      { path: '/announcements/:id', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/register', component: { template: '<div />' } },
    ],
  })
}

/** 构造 ProductBrief */
function brief(id: string, title: string) {
  return {
    id,
    title,
    subtitle: '副标题',
    main_image: null,
    price: '99.00',
    sales_count: 10,
  }
}

const RECOMMENDED = Array.from({ length: 12 }, (_, i) => brief(`rec-${i + 1}`, `推荐商品${i + 1}`))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()

  getHotMock.mockResolvedValue({
    data: { data: { hot: [brief('h1', '热销一')], newest: [brief('n1', '新品一')] } },
  })
  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getAnnouncementsMock.mockResolvedValue({ data: { data: { list: [], pagination: {} } } })
  getBannersMock.mockResolvedValue({ data: { data: { banners: { banner: [], promo: [], bottom: [] } } } })
  getRecommendedMock.mockResolvedValue({ data: { data: { list: RECOMMENDED } } })
})

describe('首页「产品推荐」栏（P-HomeRecommend）', () => {
  it('TC-HOME-REC-01 渲染推荐栏，展示 12 个商品且顺序与接口一致', async () => {
    const router = makeRouter()
    router.push('/')
    await router.isReady()

    render(HomeView, { global: { plugins: [router] } })

    await waitFor(() => expect(screen.getByTestId('home-recommended-grid')).toBeTruthy())

    // 与「热销推荐」同款栅格：2 行 × 6 件
    expect(screen.getByTestId('home-recommended-grid').children.length).toBe(12)
    // 标题「产品推荐」存在，且与热销推荐同时展示
    expect(screen.getByText('产品推荐')).toBeTruthy()
    expect(screen.getByText('热销推荐')).toBeTruthy()

    const titles = Array.from(screen.getByTestId('home-recommended-grid').children)
      .map((el) => el.textContent?.trim() ?? '')
    expect(titles[0]).toContain('推荐商品1')
    expect(titles[11]).toContain('推荐商品12')

    // 请求带首页每屏数量
    expect(getRecommendedMock).toHaveBeenCalledWith(12)
  })

  it('TC-HOME-REC-02 后台未勾选任何商品时整栏不展示', async () => {
    getRecommendedMock.mockResolvedValue({ data: { data: { list: [] } } })

    const router = makeRouter()
    router.push('/')
    await router.isReady()

    render(HomeView, { global: { plugins: [router] } })

    await waitFor(() => expect(screen.getByText('热销推荐')).toBeTruthy())

    expect(screen.queryByTestId('home-recommended-grid')).toBeNull()
    expect(screen.queryByText('产品推荐')).toBeNull()
  })

  it('TC-HOME-REC-03 推荐接口异常只丢该栏，不影响首页其它区块', async () => {
    getRecommendedMock.mockRejectedValue(new Error('boom'))

    const router = makeRouter()
    router.push('/')
    await router.isReady()

    render(HomeView, { global: { plugins: [router] } })

    await waitFor(() => expect(screen.getByText('热销推荐')).toBeTruthy())

    expect(screen.queryByTestId('home-recommended-grid')).toBeNull()
  })
})
