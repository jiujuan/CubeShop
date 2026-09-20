import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getNewsChannelsMock, getNewsArticlesMock, getNewsDetailMock,
  getCmsPageMock, getCmsNavMock, getCategoriesMock, getCartCountMock, getUnreadCountMock, getAnnouncementsMock,
} = vi.hoisted(() => ({
  getNewsChannelsMock: vi.fn(),
  getNewsArticlesMock: vi.fn(),
  getNewsDetailMock: vi.fn(),
  getCmsPageMock: vi.fn(),
  getCmsNavMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getCartCountMock: vi.fn(),
  getUnreadCountMock: vi.fn(),
  getAnnouncementsMock: vi.fn(),
}))

vi.mock('@/api/news', () => ({ getNewsChannels: getNewsChannelsMock, getNewsArticles: getNewsArticlesMock, getNewsDetail: getNewsDetailMock }))
vi.mock('@/api/cms', () => ({ getCmsPage: getCmsPageMock, getCmsNav: getCmsNavMock }))
vi.mock('@/api/shop', () => ({ getCategories: getCategoriesMock, getProducts: vi.fn(), getProduct: vi.fn(), getHot: vi.fn() }))
vi.mock('@/api/user', () => ({ getCartCount: getCartCountMock, getProfile: vi.fn(), addToCart: vi.fn(), getCart: vi.fn(), uploadImage: vi.fn() }))
vi.mock('@/api/notification', () => ({ getNotifications: vi.fn(), getUnreadCount: getUnreadCountMock, markNotificationsRead: vi.fn() }))
vi.mock('@/api/announcement', () => ({ getAnnouncements: getAnnouncementsMock, getAnnouncement: vi.fn() }))

import NewsListView from '@/views/NewsListView.vue'
import NewsDetailView from '@/views/NewsDetailView.vue'
import { useAuthStore } from '@/stores/auth'

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.token = '' // 新闻公开，未登录即可看
  return pinia
}

function makeRouter(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/news', component: NewsListView },
      { path: '/news/:id', component: NewsDetailView },
      { path: '/p/:slug', component: { template: '<div />' } },
    ],
  })
  router.push(path)
  return router
}

const channelsPayload = () => ({
  data: { data: {
    root: { id: 100, name: '新闻中心', seo_title: null, seo_keywords: null, seo_description: null },
    channels: [
      { id: 1, name: '图文新闻', slug: 'news-graphic', list_style: 'card', published_count: 2 },
      { id: 2, name: '列表新闻', slug: 'news-list', list_style: 'list', published_count: 1 },
    ],
  } },
})

const cardItem = () => ({ id: 11, title: '卡片新闻', summary: '摘', cover_image: 'http://x/c.png', published_at: '2026-09-20 10:00:00', view_count: 9, channel_id: 1, channel_name: '图文新闻' })
const rowItem = () => ({ id: 12, title: '列表新闻', summary: '摘', cover_image: null, published_at: '2026-09-19 10:00:00', view_count: 4, channel_id: 2, channel_name: '列表新闻' })

beforeEach(() => {
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getUnreadCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getAnnouncementsMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 10, total: 0, total_pages: 1 } } } })
  getNewsChannelsMock.mockResolvedValue(channelsPayload())
  getNewsArticlesMock.mockImplementation((params: { channel_id?: number } = {}) => {
    const cid = params?.channel_id
    const list = cid === 1 ? [cardItem()] : cid === 2 ? [rowItem()] : [cardItem(), rowItem()]
    return Promise.resolve({ data: { data: { list, pagination: { page: 1, page_size: 10, total: null, total_pages: null, has_more: false } } } })
  })
})

describe('新闻中心列表 NewsListView', () => {
  async function renderList() {
    const router = makeRouter('/news')
    render(NewsListView, { global: { plugins: [freshPinia(), router] } })
    await waitFor(() => expect(screen.queryByTestId('news-tabs')).toBeTruthy())
    // 等文章列表加载完（list 行 / 卡片 / 空态 任一出现）
    await waitFor(() => expect(
      screen.queryByTestId('news-list-rows') ?? screen.queryByTestId('news-card-grid') ?? screen.queryByTestId('news-empty'),
    ).toBeTruthy())
    return router
  }

  it('渲染「全部 + 子栏目」tab，全部默认按列表行渲染', async () => {
    await renderList()
    expect(screen.getByTestId('news-tab-all')).toBeTruthy()
    expect(screen.getByTestId('news-tab-1')).toBeTruthy()
    expect(screen.getByTestId('news-tab-2')).toBeTruthy()
    // 默认「全部」= list 形态
    expect(screen.getByTestId('news-list-rows')).toBeTruthy()
  })

  it('切到图文新闻 tab → 切换为卡片网格形态', async () => {
    await renderList()
    await screen.getByTestId('news-tab-1').click()
    await waitFor(() => expect(screen.queryByTestId('news-card-grid')).toBeTruthy())
    expect(screen.getByTestId('news-card-11')).toBeTruthy()
  })

  it('切 tab 时按 channel_id 重新拉取列表', async () => {
    await renderList()
    await screen.getByTestId('news-tab-2').click()
    await waitFor(() => expect(getNewsArticlesMock).toHaveBeenLastCalledWith(expect.objectContaining({ channel_id: 2 })))
  })

  it('点击列表项跳转到详情页', async () => {
    const router = await renderList()
    await waitFor(() => expect(screen.queryByTestId('news-row-12')).toBeTruthy())

    await screen.getByTestId('news-row-12').click()
    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/news/12'))
  })

  it('无新闻时显示空态', async () => {
    getNewsArticlesMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 10, total: null, total_pages: null, has_more: false } } } })
    await renderList()
    await waitFor(() => expect(screen.queryByTestId('news-empty')).toBeTruthy())
  })
})

describe('新闻详情 NewsDetailView', () => {
  const detailPayload = () => ({
    data: { data: {
      article: {
        id: 11, category_id: 1, title: '卡片新闻', summary: '摘要内容', content: '<p>正文段落</p>',
        cover_image: 'http://x/c.png', view_count: 9, published_at: '2026-09-20 10:00:00',
        is_hot: false, sort: 0, created_at: '2026-09-20 10:00:00', updated_at: '2026-09-20 10:00:00',
        category: { id: 1, name: '图文新闻' },
      },
      related: [{ id: 12, title: '相关新闻', summary: null, cover_image: null, published_at: '2026-09-19 10:00:00', view_count: 4, channel_id: 1, channel_name: '图文新闻' }],
      prev: { id: 10, title: '上一篇标题' },
      next: { id: 13, title: '下一篇标题' },
    } },
  })

  beforeEach(() => { getNewsDetailMock.mockResolvedValue(detailPayload()) })

  async function renderDetail(id = '11') {
    const router = makeRouter(`/news/${id}`)
    render(NewsDetailView, { global: { plugins: [freshPinia(), router] } })
    await waitFor(() => expect(screen.queryByTestId('news-article')).toBeTruthy())
    return router
  }

  it('渲染标题、封面、正文、相关与上一篇/下一篇', async () => {
    await renderDetail()
    expect(screen.getByTestId('news-title').textContent).toBe('卡片新闻')
    expect(screen.getByTestId('news-cover').getAttribute('src')).toContain('c.png')
    expect(screen.getByTestId('news-content').innerHTML).toContain('正文段落')
    expect(screen.getByTestId('news-related-12')).toBeTruthy()
    expect(screen.getByTestId('news-prev-link').textContent).toContain('上一篇标题')
    expect(screen.getByTestId('news-next-link').textContent).toContain('下一篇标题')
  })

  it('注入 Article JSON-LD 结构化数据（D8）', async () => {
    await renderDetail()
    const ld = document.querySelector('script[type="application/ld+json"]')
    expect(ld).toBeTruthy()
    expect((ld as HTMLScriptElement).textContent ?? '').toContain('"@type":"Article"')
    expect((ld as HTMLScriptElement).textContent ?? '').toContain('卡片新闻')
  })

  it('点击相关新闻跳转到对应详情', async () => {
    const router = await renderDetail()
    await screen.getByTestId('news-related-12').click()
    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/news/12'))
  })

  it('文章不存在时显示未找到', async () => {
    getNewsDetailMock.mockRejectedValue(new Error('not found'))
    const router = makeRouter('/news/999')
    render(NewsDetailView, { global: { plugins: [freshPinia(), router] } })
    await waitFor(() => expect(screen.queryByTestId('news-not-found')).toBeTruthy())
  })
})
