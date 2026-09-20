import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const { getCmsPageMock, getCmsNavMock, getCategoriesMock, getCartCountMock, getUnreadCountMock, getAnnouncementsMock } = vi.hoisted(() => ({
  getCmsPageMock: vi.fn(),
  getCmsNavMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getCartCountMock: vi.fn(),
  getUnreadCountMock: vi.fn(),
  getAnnouncementsMock: vi.fn(),
}))

vi.mock('@/api/cms', () => ({ getCmsPage: getCmsPageMock, getCmsNav: getCmsNavMock }))
vi.mock('@/api/shop', () => ({ getCategories: getCategoriesMock, getProducts: vi.fn(), getProduct: vi.fn(), getHot: vi.fn() }))
vi.mock('@/api/user', () => ({ getCartCount: getCartCountMock, getProfile: vi.fn(), addToCart: vi.fn(), getCart: vi.fn(), uploadImage: vi.fn() }))
vi.mock('@/api/notification', () => ({ getNotifications: vi.fn(), getUnreadCount: getUnreadCountMock, markNotificationsRead: vi.fn() }))
vi.mock('@/api/announcement', () => ({ getAnnouncements: getAnnouncementsMock, getAnnouncement: vi.fn() }))

import PageView from '@/views/PageView.vue'
import { applySeo, resetSeo } from '@/composables/useSeo'
import { useAuthStore } from '@/stores/auth'

/** 读某个 meta 标签的 content（不存在返回 null） */
function metaContent(name: string): string | null {
  return document.head.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)?.getAttribute('content') ?? null
}

function mountAt(path: string, component: unknown) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().token = ''

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/p/:slug', component: PageView },
    ],
  })
  router.push(path)

  return router.isReady().then(() =>
    render(component as never, { global: { plugins: [pinia, router] } }),
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  document.title = ''
  resetSeo()

  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getUnreadCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getAnnouncementsMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 10, total: 0, total_pages: 1 } } } })
})

describe('页面 SEO（CMS-202）', () => {
  it('applySeo 写入 title 与 description/keywords', () => {
    applySeo({ title: '关于我们 · CubeShop', description: '了解我们的团队', keywords: '电商,正品' })

    expect(document.title).toBe('关于我们 · CubeShop') // 站点名默认即 CubeShop
    expect(metaContent('description')).toBe('了解我们的团队')
    expect(metaContent('keywords')).toBe('电商,正品')
  })

  it('空值移除 meta 标签（不留 content=""）', () => {
    applySeo({ description: '先写一段', keywords: 'a' })
    expect(metaContent('description')).toBe('先写一段')

    applySeo({ description: '', keywords: '' })

    expect(metaContent('description')).toBeNull()
    expect(metaContent('keywords')).toBeNull()
  })

  it('resetSeo 清掉上一页残留的 meta', () => {
    applySeo({ title: '上一页', description: '上一页描述', keywords: 'x' })

    resetSeo()

    expect(metaContent('description')).toBeNull()
    expect(metaContent('keywords')).toBeNull()
  })

  it('单页用后端 seo 覆盖标题与 meta', async () => {
    getCmsPageMock.mockResolvedValue({ data: { data: {
      name: '关于我们', slug: 'about', template: 'about',
      fields: {}, html: {}, updated_at: null,
      seo: { title: '关于我们 | 品质电商', keywords: '电商', description: '专注品质的一站式商城' },
    } } })

    await mountAt('/p/about', PageView)

    await waitFor(() => expect(document.title).toBe('关于我们 | 品质电商'))
    expect(metaContent('description')).toBe('专注品质的一站式商城')
    expect(metaContent('keywords')).toBe('电商')
  })

  it('后端未下发 seo 时保持路由标题，不写入空 meta', async () => {
    getCmsPageMock.mockResolvedValue({ data: { data: {
      name: '关于我们', slug: 'about', template: 'about',
      fields: {}, html: {}, updated_at: null,
    } } })

    await mountAt('/p/about', PageView)

    await waitFor(() => expect(document.title).toContain('关于我们'))
    expect(metaContent('description')).toBeNull()
  })

  it('路由 afterEach 调用 resetSeo（防止 meta 跨页残留）', () => {
    // 与前端的其他守卫测试同体例：读源文件断言接线，而不是再造一个假路由去"验证自己"
    const src = readFileSync(resolve(process.cwd(), 'src/router/index.ts'), 'utf-8')
    const afterEach = src.slice(src.indexOf('router.afterEach'))

    expect(afterEach).toContain('resetSeo()')
  })
})
