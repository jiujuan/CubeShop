import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getFaqCategoriesMock, getFaqArticlesMock, getFaqArticleMock, postFaqFeedbackMock,
  getCategoriesMock, getCartCountMock, getUnreadCountMock,
} = vi.hoisted(() => ({
  getFaqCategoriesMock: vi.fn(),
  getFaqArticlesMock: vi.fn(),
  getFaqArticleMock: vi.fn(),
  postFaqFeedbackMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getCartCountMock: vi.fn(),
  getUnreadCountMock: vi.fn(),
}))

vi.mock('@/api/cs', () => ({
  getFaqCategories: getFaqCategoriesMock,
  getFaqArticles: getFaqArticlesMock,
  getFaqArticle: getFaqArticleMock,
  postFaqFeedback: postFaqFeedbackMock,
}))
vi.mock('@/api/shop', () => ({ getCategories: getCategoriesMock, getProducts: vi.fn(), getProduct: vi.fn(), getHot: vi.fn() }))
vi.mock('@/api/user', () => ({ getCartCount: getCartCountMock, getProfile: vi.fn(), addToCart: vi.fn(), getCart: vi.fn(), uploadImage: vi.fn() }))
vi.mock('@/api/notification', () => ({ getNotifications: vi.fn(), getUnreadCount: getUnreadCountMock, markNotificationsRead: vi.fn() }))

import FaqCategoryView from '@/views/FaqCategoryView.vue'
import FaqDetailView from '@/views/FaqDetailView.vue'
import FaqListView from '@/views/FaqListView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter(path = '/service-center/faq/list') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/service-center', component: { template: '<div />' } },
      { path: '/service-center/faq', component: { template: '<div />' } },
      { path: '/service-center/faq/list', component: { template: '<div />' } },
      { path: '/service-center/faq/:id', component: { template: '<div />' } },
      { path: '/service-center/tickets/new', component: { template: '<div />' } },
    ],
  })
  return { router, path }
}

const article = (id: number, overrides: Record<string, unknown> = {}) => ({
  id, category_id: 1, category: { id: 1, name: '售后政策' }, title: `文章 ${id}`, summary: `摘要 ${id}`,
  content: '正文内容', status: 'published', sort: 0, is_hot: false,
  view_count: 1, helpful_count: 0, unhelpful_count: 0, created_at: '2026-09-17 10:00:00', ...overrides,
})

function setupAuth() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.token = 't'
  auth.user = { id: 1, username: 'u', nickname: '小明', avatar: null, phone: null, email: null }
  return pinia
}

async function renderAt(component: unknown, path: string, query: Record<string, string> = {}) {
  const pinia = setupAuth()
  const { router } = makeRouter(path)
  router.push({ path, query })
  await router.isReady()
  const utils = render(component as never, { global: { plugins: [pinia, router] } })
  return { utils, router }
}

beforeEach(() => {
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getUnreadCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getFaqCategoriesMock.mockResolvedValue({ data: { data: [
    { id: 1, name: '购物指南', sort: 1, published_count: 2 },
    { id: 2, name: '物流配送', sort: 2, published_count: 5 },
  ] } })
  getFaqArticlesMock.mockResolvedValue({ data: { data: { list: [article(1), article(2)], pagination: { page: 1, page_size: 10, total: 2, total_pages: 1 } } } })
  getFaqArticleMock.mockResolvedValue({ data: { data: { article: article(5, { content: '正文内容', helpful_count: 3, unhelpful_count: 1 }), related: [article(6), article(7)] } } })
  postFaqFeedbackMock.mockResolvedValue({ data: { data: { helpful_count: 4, unhelpful_count: 1 } } })
})

afterEach(() => vi.useRealTimers())

describe('帮助中心（CS-112）', () => {
  it('分类列表渲染与计数正确', async () => {
    await renderAt(FaqCategoryView, '/service-center/faq')
    await waitFor(() => expect(screen.getByTestId('category-item-1')).toBeTruthy())
    expect(screen.getByTestId('category-item-1').textContent).toContain('购物指南')
    expect(screen.getByTestId('category-item-1').textContent).toContain('2')
    expect(screen.getByTestId('category-item-2').textContent).toContain('5')
  })

  it('搜索防抖：连续输入只触发一次请求', async () => {
    vi.useFakeTimers()
    await renderAt(FaqListView, '/service-center/faq/list')
    await flushPromises()
    const initialCalls = getFaqArticlesMock.mock.calls.length

    const input = screen.getByTestId('faq-search-input')
    await fireEvent.input(input, { target: { value: '退' } })
    await fireEvent.input(input, { target: { value: '退款' } })
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(getFaqArticlesMock.mock.calls.length).toBe(initialCalls + 1)
  })

  it('关键词高亮渲染（<mark> 数量正确）', async () => {
    getFaqArticlesMock.mockResolvedValue({ data: { data: { list: [
      { ...article(1), title: '如何申请退款', summary: '退款流程说明' },
    ], pagination: { page: 1, page_size: 10, total: 1, total_pages: 1 } } } })
    const { utils } = await renderAt(FaqListView, '/service-center/faq/list', { keyword: '退款' })
    await waitFor(() => expect(screen.getByTestId('faq-article-1')).toBeTruthy())
    // 标题 1 处 + 摘要 1 处
    expect(utils.container.querySelectorAll('mark').length).toBe(2)
  })

  it('空结果展示引导与「联系客服」按钮', async () => {
    getFaqArticlesMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 10, total: 0, total_pages: 1 } } } })
    const { router } = await renderAt(FaqListView, '/service-center/faq/list', { keyword: '不存在的词' })
    await waitFor(() => expect(screen.getByTestId('faq-empty')).toBeTruthy())

    const push = vi.spyOn(router, 'push')
    await fireEvent.click(screen.getByTestId('faq-contact-service'))
    expect(push).toHaveBeenCalledWith({ path: '/service-center/tickets/new', query: { title: '不存在的词' } })
  })

  it('「是否有帮助」提交后置灰且只提交一次', async () => {
    await renderAt(FaqDetailView, '/service-center/faq/5')
    await waitFor(() => expect(screen.getByTestId('feedback-helpful')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('feedback-helpful'))
    await waitFor(() => expect(postFaqFeedbackMock).toHaveBeenCalledTimes(1))
    expect(postFaqFeedbackMock).toHaveBeenCalledWith('5', true)

    // 已提交，按钮禁用，再次点击不重复提交
    expect((screen.getByTestId('feedback-helpful') as HTMLButtonElement).disabled).toBe(true)
    await fireEvent.click(screen.getByTestId('feedback-helpful'))
    expect(postFaqFeedbackMock).toHaveBeenCalledTimes(1)
  })

  it('相关推荐不含当前文章', async () => {
    getFaqArticleMock.mockResolvedValue({ data: { data: { article: article(5), related: [article(5), article(6), article(7)] } } })
    await renderAt(FaqDetailView, '/service-center/faq/5')
    await waitFor(() => expect(screen.getByTestId('faq-related')).toBeTruthy())
    expect(screen.queryByTestId('related-5')).toBeNull()
    expect(screen.getByTestId('related-6')).toBeTruthy()
    expect(screen.getByTestId('related-7')).toBeTruthy()
  })

  it('正文按富文本渲染：HTML 标签成为元素而不是字面量文本', async () => {
    getFaqArticleMock.mockResolvedValue({ data: { data: {
      article: article(5, {
        content: '<h2 id="steps">退款步骤</h2><p>进入订单页申请</p><ul><li>商品完好</li><li>配件齐全</li></ul>'
          + '<table><tbody><tr><td>时效</td><td>7 天</td></tr></tbody></table>'
          + '<img src="/storage/uploads/a.png" alt="示意图">'
          + '<a href="/service-center">联系客服</a>',
      }),
      related: [],
    } } })
    await renderAt(FaqDetailView, '/service-center/faq/5')
    await waitFor(() => expect(screen.getByTestId('faq-content')).toBeTruthy())

    const body = screen.getByTestId('faq-content')

    // 结构真的被解析成元素（缺陷 #1 的表现就是这些标签对用户可见）
    expect(body.querySelector('h2#steps')?.textContent).toBe('退款步骤')
    expect(body.querySelectorAll('p')).toHaveLength(1)
    expect(body.querySelectorAll('ul > li')).toHaveLength(2)
    expect(body.querySelector('table td')?.textContent).toBe('时效')
    expect(body.querySelector('img')?.getAttribute('src')).toBe('/storage/uploads/a.png')
    expect(body.querySelector('a')?.getAttribute('href')).toBe('/service-center')
    expect(body.textContent).not.toContain('<p>')
  })

  it('正文里的纯文本换行保留（不会被 HTML 折叠成一行）', async () => {
    // 后端净化器已把「无标签的纯文本」转成 转义 + <br>
    getFaqArticleMock.mockResolvedValue({ data: { data: {
      article: article(5, { content: '第一行<br>\n第二行' }),
      related: [],
    } } })
    await renderAt(FaqDetailView, '/service-center/faq/5')
    await waitFor(() => expect(screen.getByTestId('faq-content')).toBeTruthy())

    expect(screen.getByTestId('faq-content').querySelectorAll('br')).toHaveLength(1)
  })
})
