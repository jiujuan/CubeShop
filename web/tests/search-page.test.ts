import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  searchProductsMock, getProductsMock, getCategoriesMock, getBrandsMock, getAttributesMock,
} = vi.hoisted(() => ({
  searchProductsMock: vi.fn(),
  getProductsMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getBrandsMock: vi.fn(),
  getAttributesMock: vi.fn(),
}))

vi.mock('@/api/search', () => ({
  searchProducts: searchProductsMock,
  getSuggest: vi.fn(),
  getHotKeywords: vi.fn(),
}))

vi.mock('@/api/shop', () => ({
  getProducts: getProductsMock,
  getCategories: getCategoriesMock,
  getProduct: vi.fn(),
  getHot: vi.fn(),
  getAttributes: getAttributesMock,
  getBrands: getBrandsMock,
}))
vi.mock('@/api/user', () => ({
  addToCart: vi.fn().mockResolvedValue({ data: { code: 0 } }),
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  getCart: vi.fn(),
}))
vi.mock('@/api/notification', () => ({
  getUnreadCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  getNotifications: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
  markNotificationsRead: vi.fn(),
}))
vi.mock('@/api/auth', () => ({ getMe: vi.fn(), logout: vi.fn() }))
vi.mock('@/api/announcement', () => ({
  getAnnouncements: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
}))

import BrowseView from '@/views/BrowseView.vue'

/**
 * /search 关键词模式（V1.2 S1-10）
 *
 * 钉三条：
 * 1. **keyword 模式走 /search**（带 meta），无关键词浏览仍走 /products（原语义不动）；
 * 2. **relaxed 提示** 与 **相关分类 chip**（点击收敛/清除，URL 驱动）；
 * 3. **零结果不白屏**：有推荐位时展示推荐网格，browse-empty 让位。
 */
const product = {
  id: 7,
  title: '北欧实木沙发',
  subtitle: null,
  main_image: null,
  price: '2999.00',
  sales_count: 500,
  category: { id: 3, name: '家具' },
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/search', component: { template: '<div />' } },
      { path: '/category/:id', component: { template: '<div />' } },
      { path: '/product/:id', component: { template: '<div />' } },
    ],
  })
}

async function renderAtSearch(keyword: string) {
  const router = makeRouter()
  await router.push({ path: '/search', query: { keyword } })
  await router.isReady()
  render(BrowseView, { global: { plugins: [router, createPinia()] } })
  return router
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({
    data: { data: [{ id: '3', name: '家具', children: [{ id: '31', name: '沙发' }] }] },
  })
  getBrandsMock.mockResolvedValue({ data: { data: [] } })
  getAttributesMock.mockResolvedValue({ data: { data: [] } })
  getProductsMock.mockResolvedValue({
    data: { data: { list: [product], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
  })
})

describe('搜索结果页（BrowseView keyword 模式，/search）', () => {
  it('keyword 模式走 /search 接口（带 keyword 参数），商品照常渲染', async () => {
    searchProductsMock.mockResolvedValue({
      data: {
        data: {
          list: [product],
          pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
          meta: { keyword: '沙发', related_categories: [] },
        },
      },
    })

    const router = await renderAtSearch('沙发')
    router.push('/search?keyword=沙发')
    await waitFor(() => expect(searchProductsMock).toHaveBeenCalled())

    expect(searchProductsMock.mock.calls[0][0]).toMatchObject({ keyword: '沙发', page: 1 })
    expect(getProductsMock).not.toHaveBeenCalled()
    await waitFor(() => expect(screen.getByText('北欧实木沙发')).toBeTruthy())
  })

  it('搜索有结果时同样隐藏品牌/排序/筛选控件与视图切换', async () => {
    // 品牌接口有数据也不展示（搜索页一律隐藏，验证的是显隐逻辑而非数据为空）
    getBrandsMock.mockResolvedValue({ data: { data: [{ id: 5, name: '安克' }] } })
    searchProductsMock.mockResolvedValue({
      data: {
        data: {
          list: [product],
          pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
          meta: { keyword: '沙发', related_categories: [] },
        },
      },
    })

    await renderAtSearch('沙发')
    await waitFor(() => expect(screen.getByText('北欧实木沙发')).toBeTruthy())

    expect(screen.queryByText('综合排序')).toBeNull()
    expect(screen.queryByTestId('price-filter-toggle')).toBeNull()
    expect(screen.queryByTestId('filter-toggle')).toBeNull()
    expect(screen.queryByTitle('网格视图')).toBeNull()
    expect(screen.queryByTestId('browse-heading-brands')).toBeNull()
  })

  it('meta.relaxed → 放宽匹配提示条', async () => {
    searchProductsMock.mockResolvedValue({
      data: {
        data: {
          list: [product],
          pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
          meta: { keyword: '北欧沙发', relaxed: true, related_categories: [] },
        },
      },
    })

    const router = makeRouter()
    await router.push({ path: '/search', query: { keyword: '北欧沙发' } })
    await router.isReady()
    render(BrowseView, { global: { plugins: [router, createPinia()] } })

    await waitFor(() => expect(screen.getByTestId('search-relaxed')).toBeTruthy())
    expect(screen.getByTestId('search-relaxed').textContent).toContain('放宽')
  })

  it('相关分类 chip：点击收敛到该分类，再点清除（URL 驱动）', async () => {
    searchProductsMock.mockResolvedValue({
      data: {
        data: {
          list: [product],
          pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
          meta: {
            keyword: '沙发',
            related_categories: [
              { id: '9', name: '客厅家具', count: 12 },
            ],
          },
        },
      },
    })

    const router = makeRouter()
    await router.push({ path: '/search', query: { keyword: '沙发' } })
    await router.isReady()
    render(BrowseView, { global: { plugins: [router, createPinia()] } })

    const chip = await waitFor(() => screen.getByTestId('search-related-category-9'))
    await fireEvent.click(chip)

    await waitFor(() => expect(router.currentRoute.value.query).toMatchObject({
      keyword: '沙发',
      category_id: '9',
    }))
  })

  it('零结果 → 推荐位网格展示，不出现空态占位', async () => {
    searchProductsMock.mockResolvedValue({
      data: {
        data: {
          list: [],
          pagination: { page: 1, page_size: 20, total: 0, total_pages: 1 },
          meta: {
            keyword: '手机壳',
            related_categories: [],
            recommendations: [product],
          },
        },
      },
    })

    const router = makeRouter()
    await router.push({ path: '/search', query: { keyword: '手机壳' } })
    await router.isReady()
    render(BrowseView, { global: { plugins: [router, createPinia()] } })

    await waitFor(() => expect(screen.getByTestId('search-recommendations')).toBeTruthy())
    expect(screen.getByTestId('search-recommendations').textContent).toContain('北欧实木沙发')
    expect(screen.queryByTestId('browse-empty')).toBeNull()
  })

  it('零结果且无推荐位 → 空态占位兜底', async () => {
    searchProductsMock.mockResolvedValue({
      data: {
        data: {
          list: [],
          pagination: { page: 1, page_size: 20, total: 0, total_pages: 1 },
          meta: { keyword: '手机壳', related_categories: [], recommendations: [] },
        },
      },
    })

    const router = makeRouter()
    await router.push({ path: '/search', query: { keyword: '手机壳' } })
    await router.isReady()
    render(BrowseView, { global: { plugins: [router, createPinia()] } })

    await waitFor(() => expect(screen.getByTestId('browse-empty')).toBeTruthy())
    expect(screen.queryByTestId('search-recommendations')).toBeNull()
  })
})
