import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getFaqArticlesMock, getFaqCategoriesMock, getTicketsMock,
  getCategoriesMock, getCartCountMock, getUnreadCountMock,
} = vi.hoisted(() => ({
  getFaqArticlesMock: vi.fn(),
  getFaqCategoriesMock: vi.fn(),
  getTicketsMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getCartCountMock: vi.fn(),
  getUnreadCountMock: vi.fn(),
}))

vi.mock('@/api/cs', () => ({
  getFaqArticles: getFaqArticlesMock,
  getFaqCategories: getFaqCategoriesMock,
  getTickets: getTicketsMock,
}))
vi.mock('@/api/shop', () => ({ getCategories: getCategoriesMock, getProducts: vi.fn(), getProduct: vi.fn(), getHot: vi.fn() }))
vi.mock('@/api/user', () => ({ getCartCount: getCartCountMock, getProfile: vi.fn(), addToCart: vi.fn(), getCart: vi.fn(), uploadImage: vi.fn() }))
vi.mock('@/api/notification', () => ({ getNotifications: vi.fn(), getUnreadCount: getUnreadCountMock, markNotificationsRead: vi.fn() }))

import ServiceCenterView from '@/views/ServiceCenterView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/service-center', component: { template: '<div />' } },
      { path: '/service-center/faq', component: { template: '<div />' } },
      { path: '/service-center/faq/list', component: { template: '<div />' } },
      { path: '/service-center/faq/:id', component: { template: '<div />' } },
      { path: '/service-center/tickets', component: { template: '<div />' } },
      { path: '/service-center/tickets/new', component: { template: '<div />' } },
      { path: '/orders', component: { template: '<div />' } },
    ],
  })
}

const category = (id: number, name: string, count = 0) => ({ id, name, sort: id, published_count: count })
const article = (id: number, isHot = false) => ({
  id, category_id: 1, title: `问题 ${id}`, summary: `摘要 ${id}`, content: '正文', status: 'published',
  sort: 0, is_hot: isHot, view_count: 0, helpful_count: 0, unhelpful_count: 0, created_at: '2026-09-17 10:00:00',
})
const ticket = (id: number) => ({
  id, ticket_no: `TK202609170${id}`, type_id: 1, type_name: '物流问题', title: `工单 ${id}`, status: 'pending',
  status_label: '待处理', priority: 0, priority_label: '普通', contact: null, can_reply: true, can_close: true,
  message_count: 1, last_message_at: '2026-09-17 10:00:00', created_at: '2026-09-17 10:00:00', closed_at: null,
})

function setupAuth() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.token = 't'
  auth.user = { id: 1, username: 'u', nickname: '小明', avatar: null, phone: '13800000000', email: null }
  return pinia
}

beforeEach(() => {
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getUnreadCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getFaqCategoriesMock.mockResolvedValue({ data: { data: [category(1, '购物指南', 2), category(2, '物流配送', 3)] } })
  getFaqArticlesMock.mockResolvedValue({ data: { data: { list: [article(11, true), article(12, true)], pagination: { page: 1, page_size: 5, total: 2, total_pages: 1 } } } })
  getTicketsMock.mockResolvedValue({ data: { data: { list: [ticket(1), ticket(2), ticket(3)], pagination: { page: 1, page_size: 3, total: 3, total_pages: 1 } } } })
})

async function renderView() {
  const pinia = setupAuth()
  const router = makeRouter()
  router.push('/service-center')
  await router.isReady()
  const utils = render(ServiceCenterView, { global: { plugins: [pinia, router] } })
  await waitFor(() => expect(screen.getByTestId('quick-tools')).toBeTruthy())
  return { utils, router }
}

describe('服务中心首页 ServiceCenterView（CS-111）', () => {
  it('渲染分类与热门问题条数正确', async () => {
    await renderView()
    expect(screen.getByTestId('faq-category-1')).toBeTruthy()
    expect(screen.getByTestId('faq-category-2')).toBeTruthy()
    expect(screen.getByTestId('hot-faq-11')).toBeTruthy()
    expect(screen.getByTestId('hot-faq-12')).toBeTruthy()
    expect(screen.getByTestId('faq-category-1').textContent).toContain('购物指南')
    expect(screen.getByTestId('faq-category-2').textContent).toContain('3')
  })

  it('最近工单最多渲染 3 条，「查看全部」跳转正确', async () => {
    getTicketsMock.mockResolvedValue({
      data: { data: { list: [ticket(1), ticket(2), ticket(3), ticket(4), ticket(5)], pagination: { page: 1, page_size: 3, total: 5, total_pages: 2 } } },
    })
    const { router } = await renderView()
    expect(screen.getAllByTestId(/^recent-ticket-/).length).toBe(3)

    const push = vi.spyOn(router, 'push')
    await fireEvent.click(screen.getByTestId('view-all-tickets'))
    expect(push).toHaveBeenCalledWith('/service-center/tickets')
  })

  it('无工单时展示空态', async () => {
    getTicketsMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 3, total: 0, total_pages: 1 } } } })
    await renderView()
    expect(screen.getByTestId('ticket-empty')).toBeTruthy()
  })

  it('快捷工具点击跳转目标正确', async () => {
    const { router } = await renderView()
    const push = vi.spyOn(router, 'push')

    await fireEvent.click(screen.getByTestId('quick-logistics'))
    expect(push).toHaveBeenCalledWith('/orders')

    await fireEvent.click(screen.getByTestId('quick-refund'))
    expect(push).toHaveBeenCalledWith('/orders?tab=after_sale')

    await fireEvent.click(screen.getByTestId('quick-contact'))
    expect(push).toHaveBeenCalledWith('/service-center/tickets/new')
  })

  it('接口失败时区块降级不白屏（热门问题展示重试）', async () => {
    getFaqArticlesMock.mockRejectedValue(new Error('boom'))
    await renderView()
    expect(screen.getByTestId('quick-tools')).toBeTruthy()
    expect(screen.getByTestId('faq-error')).toBeTruthy()
  })
})
