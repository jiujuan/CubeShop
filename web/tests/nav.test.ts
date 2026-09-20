import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const { getNavMock, getCategoriesMock } = vi.hoisted(() => ({
  getNavMock: vi.fn(),
  getCategoriesMock: vi.fn(),
}))

vi.mock('@/api/shop', () => ({
  getCategories: getCategoriesMock,
  getNav: getNavMock,
}))
vi.mock('@/api/user', () => ({
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
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

import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/** 分类引用型条目：url 与分类页路由 /category/{public_id} 同口径 */
function categoryItem(overrides: Record<string, unknown> = {}) {
  return {
    id: 3,
    type: 'category',
    title: '运动户外',
    url: '/category/ULID01',
    target: '_self',
    category_public_id: 'ULID01',
    ...overrides,
  }
}

function customItem(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    type: 'custom',
    title: '热销推荐',
    url: '/search?sort=sales_desc',
    target: '_self',
    ...overrides,
  }
}

function mockNav(items: unknown[] = [customItem(), categoryItem()]) {
  getNavMock.mockResolvedValue({ data: { data: items } })
  getCategoriesMock.mockResolvedValue({
    data: { data: [{ id: 'ULID01', name: '运动户外', children: [{ id: 'ULID02', name: '跑步鞋' }] }] },
  })
}

async function mountHeader(initialPath = '/') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/search', name: 'search', component: { template: '<div />' } },
      { path: '/category/:id', name: 'category', component: { template: '<div />' } },
    ],
  })
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().setToken('test-token')

  router.push(initialPath)
  await router.isReady()

  const utils = render(ShopHeader, { global: { plugins: [router, pinia] } })
  await waitFor(() => expect(getNavMock).toHaveBeenCalled())

  return { ...utils, router }
}

beforeEach(() => {
  vi.clearAllMocks()
  mockNav()
})

describe('前台顶部导航（后台编排）', () => {
  it('TC-NAV-W01 按接口返回顺序渲染，不再硬编码热销推荐', async () => {
    const { getByTestId } = await mountHeader()

    expect(getByTestId('nav-item-1').textContent).toContain('热销推荐')
    expect(getByTestId('nav-item-3').textContent).toContain('运动户外')

    const nav = getByTestId('category-nav')
    const texts = [...nav.querySelectorAll('[data-testid^="nav-item-"]')].map((n) => n.textContent?.trim())
    expect(texts).toEqual(['热销推荐', '运动户外'])
  })

  it('TC-NAV-W02 首页仍硬编码在最前', async () => {
    const { getByTestId } = await mountHeader()

    const children = [...getByTestId('category-nav').children]
    // 全部商品分类 → 首页 → 编排项
    expect(children[1].textContent).toContain('首页')
  })

  it('TC-NAV-W03 站内条目点击走 router.push', async () => {
    const { getByTestId, router } = await mountHeader()

    await fireEvent.click(getByTestId('nav-item-1'))

    // router.push 是异步的，必须等导航落地
    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/search?sort=sales_desc'))
  })

  it('TC-NAV-W04 分类页高亮所属一级分类（子分类页也高亮父级）', async () => {
    // 子分类 ULID02 属于 ULID01 → 应高亮「运动户外」
    const { getByTestId } = await mountHeader('/category/ULID02')

    expect(getByTestId('nav-item-3').className).toContain('text-[#1677ff]')
  })

  it('TC-NAV-W05 站外条目渲染为 <a target=_blank rel=noopener>', async () => {
    mockNav([
      customItem({ id: 2, title: '外部资讯', url: 'https://example.com/news', target: '_blank' }),
    ])
    const { getByTestId } = await mountHeader()

    const link = getByTestId('nav-item-2')
    expect(link.tagName).toBe('A')
    expect(link.getAttribute('href')).toBe('https://example.com/news')
    expect(link.getAttribute('target')).toBe('_blank')
    expect(link.getAttribute('rel')).toContain('noopener')
  })

  it('TC-NAV-W06 导航接口失败时只留首页，不牵连分类下拉', async () => {
    getNavMock.mockRejectedValue(new Error('boom'))

    const { getByTestId } = await mountHeader()

    await waitFor(() => expect(getByTestId('category-nav').textContent).toContain('首页'))
    expect(getByTestId('category-nav').querySelectorAll('[data-testid^="nav-item-"]')).toHaveLength(0)
    // 分类下拉仍可用（用的是 /products/categories）
    expect(getByTestId('category-nav').textContent).toContain('全部商品分类')
  })

  it('TC-NAV-W07 自定义条目按其路径高亮', async () => {
    mockNav([customItem({ id: 4, title: '搜索页', url: '/search?sort=sales_desc', target: '_self' })])
    const { getByTestId } = await mountHeader('/search')

    expect(getByTestId('nav-item-4').className).toContain('text-[#1677ff]')
  })
})
