import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

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
import ShopFooter from '@/components/ShopFooter.vue'
import { useAuthStore } from '@/stores/auth'

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  // 未登录：单页是公开内容（决策 D4），不应受影响
  auth.token = ''
  return pinia
}

function makeRouter(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/register', component: { template: '<div />' } },
      { path: '/cart', component: { template: '<div />' } },
      { path: '/p/:slug', component: PageView },
      { path: '/service-center/faq', component: { template: '<div />' } },
    ],
  })
  return { router, path }
}

async function renderPage(path = '/p/about') {
  const pinia = freshPinia()
  const { router } = makeRouter(path)
  router.push(path)
  await router.isReady()
  return render(PageView, { global: { plugins: [pinia, router] } })
}

beforeEach(() => {
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getUnreadCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getAnnouncementsMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 10, total: 0, total_pages: 1 } } } })
})

describe('CMS 站点单页 PageView（CMS-112）', () => {
  it('about 模板：渲染标题、简介、发展历程与企业价值观', async () => {
    getCmsPageMock.mockResolvedValue({ data: { data: {
      name: '关于我们', slug: 'about', template: 'about',
      fields: {
        banner: '', intro: '我们是一家公司',
        milestones: [{ year: '2020', event: '成立' }, { year: '2024', event: '上市' }],
        values: [{ title: '客户第一', desc: '用心服务' }],
      },
      html: { intro: '<p>我们是一家公司</p>' },
      updated_at: '2026-09-20 10:00:00',
    } } })

    await renderPage('/p/about')

    await waitFor(() => expect(screen.getByTestId('page-about')).toBeTruthy())
    expect(screen.getByTestId('page-about').textContent).toContain('关于我们')
    // markdown 字段以 HTML 渲染（不是字面量）
    expect(screen.getByTestId('page-about-intro').querySelector('p')?.textContent).toBe('我们是一家公司')
    expect(screen.getByTestId('page-about-milestones').querySelectorAll('li')).toHaveLength(2)
    expect(screen.getByTestId('page-about-values').textContent).toContain('客户第一')
  })

  it('contact 模板：只渲染填了值的联系方式', async () => {
    getCmsPageMock.mockResolvedValue({ data: { data: {
      name: '联系我们', slug: 'contact', template: 'contact',
      fields: { address: '北京市朝阳区', phone: '400-000-0000', email: '', work_time: '9:00-18:00', map_image: '', intro: '' },
      html: { intro: '' },
      updated_at: null,
    } } })

    await renderPage('/p/contact')

    await waitFor(() => expect(screen.getByTestId('page-contact')).toBeTruthy())
    expect(screen.getByTestId('page-contact-address').textContent).toContain('北京市朝阳区')
    expect(screen.getByTestId('page-contact-phone').textContent).toContain('400-000-0000')
    // email 为空 → 不渲染该卡片
    expect(screen.queryByTestId('page-contact-email')).toBeNull()
  })

  it('未知模板走 404 分支（不渲染空壳）', async () => {
    getCmsPageMock.mockResolvedValue({ data: { data: {
      name: '神秘页', slug: 'mystery', template: 'not-registered',
      fields: {}, html: {}, updated_at: null,
    } } })

    await renderPage('/p/mystery')

    await waitFor(() => expect(screen.getByTestId('cms-page-not-found')).toBeTruthy())
    expect(screen.getByTestId('cms-page-back-home')).toBeTruthy()
  })

  it('接口 404 时展示未找到提示与返回首页入口', async () => {
    getCmsPageMock.mockRejectedValue(new Error('页面不存在或已下线'))

    await renderPage('/p/not-exist')

    await waitFor(() => expect(screen.getByTestId('cms-page-not-found')).toBeTruthy())
    expect(screen.getByTestId('cms-page-not-found').textContent).toContain('页面不存在或已下线')
  })

  it('页脚三个入口指向真实路由（不再是死链）', async () => {
    const pinia = freshPinia()
    const { router } = makeRouter('/p/about')
    router.push('/p/about')
    await router.isReady()

    render(ShopFooter, { global: { plugins: [pinia, router] } })

    expect(screen.getByTestId('footer-about').getAttribute('href')).toBe('/p/about')
    expect(screen.getByTestId('footer-help').getAttribute('href')).toBe('/service-center/faq')
    expect(screen.getByTestId('footer-contact').getAttribute('href')).toBe('/p/contact')
  })
})
