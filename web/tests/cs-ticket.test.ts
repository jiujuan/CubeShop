import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  createTicketMock, getTicketTypesMock, uploadTicketImageMock,
  getTicketsMock, getTicketMock, addTicketMessageMock, closeTicketMock,
  getOrdersMock, getProfileMock, getCategoriesMock, getCartCountMock, getUnreadCountMock,
} = vi.hoisted(() => ({
  createTicketMock: vi.fn(),
  getTicketTypesMock: vi.fn(),
  uploadTicketImageMock: vi.fn(),
  getTicketsMock: vi.fn(),
  getTicketMock: vi.fn(),
  addTicketMessageMock: vi.fn(),
  closeTicketMock: vi.fn(),
  getOrdersMock: vi.fn(),
  getProfileMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getCartCountMock: vi.fn(),
  getUnreadCountMock: vi.fn(),
}))

vi.mock('@/api/cs', () => ({
  createTicket: createTicketMock,
  getTicketTypes: getTicketTypesMock,
  uploadTicketImage: uploadTicketImageMock,
  getTickets: getTicketsMock,
  getTicket: getTicketMock,
  addTicketMessage: addTicketMessageMock,
  closeTicket: closeTicketMock,
}))
vi.mock('@/api/order', () => ({ getOrders: getOrdersMock }))
vi.mock('@/api/shop', () => ({ getCategories: getCategoriesMock, getProducts: vi.fn(), getProduct: vi.fn(), getHot: vi.fn() }))
vi.mock('@/api/user', () => ({ getCartCount: getCartCountMock, getProfile: getProfileMock, addToCart: vi.fn(), getCart: vi.fn(), uploadImage: vi.fn() }))
vi.mock('@/api/notification', () => ({ getNotifications: vi.fn(), getUnreadCount: getUnreadCountMock, markNotificationsRead: vi.fn() }))

import TicketCreateView from '@/views/TicketCreateView.vue'
import TicketDetailView from '@/views/TicketDetailView.vue'
import TicketListView from '@/views/TicketListView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/service-center', component: { template: '<div />' } },
      { path: '/service-center/tickets', component: { template: '<div />' } },
      { path: '/service-center/tickets/new', component: { template: '<div />' } },
      { path: '/service-center/tickets/:id', component: { template: '<div />' } },
      { path: '/orders/:id', component: { template: '<div />' } },
    ],
  })
}

function setupAuth() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.token = 't'
  auth.user = { id: 1, username: 'u', nickname: '小明', avatar: null, phone: '13800000000', email: null }
  return pinia
}

async function renderAt(component: unknown, path: string) {
  const pinia = setupAuth()
  const router = makeRouter()
  router.push(path)
  await router.isReady()
  const utils = render(component as never, { global: { plugins: [pinia, router] } })
  return { utils, router }
}

const msg = (id: number, senderType: string, isInternal = false) => ({
  id, sender_type: senderType, sender_name: { user: '我', staff: '客服', system: '系统' }[senderType as 'user'] ?? senderType,
  content: `消息 ${id}`, images: [], is_internal: isInternal, created_at: '2026-09-17 10:00:00',
})

const detailTicket = (overrides: Record<string, unknown> = {}) => ({
  id: 9, ticket_no: 'TK20260917000009', type_id: 1, type_name: '物流问题', title: '物流太慢',
  status: 'processing', status_label: '处理中', priority: 0, priority_label: '普通', contact: null,
  can_reply: true, can_close: true, order_id: 100, last_message_at: '2026-09-17 10:00:00',
  created_at: '2026-09-17 10:00:00', closed_at: null,
  messages: [msg(1, 'user'), msg(2, 'staff'), msg(3, 'system')],
  ...overrides,
})

beforeEach(() => {
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getUnreadCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getProfileMock.mockResolvedValue({ data: { data: { phone: '13800000000' } } })
  getOrdersMock.mockResolvedValue({ data: { data: { list: [{ id: 100, order_no: 'CS202609170001' }], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } } })
  getTicketTypesMock.mockResolvedValue({ data: { data: [
    { id: 1, name: '物流问题', code: 'logistics', require_order: true },
    { id: 2, name: '其他问题', code: 'other', require_order: false },
  ] } })
  uploadTicketImageMock.mockResolvedValue({ data: { data: { url: 'https://cdn.example.com/cs.png' } } })
  createTicketMock.mockResolvedValue({ data: { data: { ticket: detailTicket() } } })
  getTicketsMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 10, total: 0, total_pages: 1 } } } })
  getTicketMock.mockResolvedValue({ data: { data: { ticket: detailTicket(), order: { order_no: 'CS202609170001', status: 'shipped', pay_amount: '100.00', product_image: null } } } })
  addTicketMessageMock.mockResolvedValue({ data: { data: { message: msg(4, 'user'), ticket: detailTicket({ messages: [msg(1, 'user'), msg(2, 'staff'), msg(3, 'system'), msg(4, 'user')] }) } } })
  closeTicketMock.mockResolvedValue({ data: { data: detailTicket({ status: 'closed', status_label: '已关闭', can_reply: false, can_close: false }) } })
})

describe('提交工单 TicketCreateView（CS-113）', () => {
  it('必填订单类型未选订单时提交被拦截并提示', async () => {
    await renderAt(TicketCreateView, '/service-center/tickets/new')
    await waitFor(() => expect(screen.getByTestId('ticket-type-select')).toBeTruthy())

    await fireEvent.update(screen.getByTestId('ticket-type-select'), '1')
    await fireEvent.update(screen.getByTestId('ticket-title-input'), '物流太慢')
    await fireEvent.update(screen.getByTestId('ticket-content-input'), '三天没到')
    await fireEvent.click(screen.getByTestId('ticket-submit'))

    await waitFor(() => expect(screen.getByTestId('form-error')).toBeTruthy())
    expect(screen.getByTestId('form-error').textContent).toContain('必须关联订单')
    expect(createTicketMock).not.toHaveBeenCalled()
  })

  it('凭证选择超过 9 张被拦截', async () => {
    await renderAt(TicketCreateView, '/service-center/tickets/new')
    await waitFor(() => expect(screen.getByTestId('ticket-images-input')).toBeTruthy())

    const input = screen.getByTestId('ticket-images-input') as HTMLInputElement
    const files = Array.from({ length: 10 }, (_, i) => new File(['x'], `f${i}.png`, { type: 'image/png' }))
    Object.defineProperty(input, 'files', { value: files })
    await fireEvent.change(input)

    await waitFor(() => expect(screen.getByTestId('form-error')).toBeTruthy())
    expect(screen.getByTestId('form-error').textContent).toContain('9')
    expect(uploadTicketImageMock).toHaveBeenCalledTimes(9)
  })

  it('上传失败提示且不阻塞提交流程', async () => {
    uploadTicketImageMock.mockRejectedValue(new Error('上传超时'))
    await renderAt(TicketCreateView, '/service-center/tickets/new')
    await waitFor(() => expect(screen.getByTestId('ticket-images-input')).toBeTruthy())

    await fireEvent.update(screen.getByTestId('ticket-type-select'), '2')
    await fireEvent.update(screen.getByTestId('ticket-title-input'), '其他问题')
    await fireEvent.update(screen.getByTestId('ticket-content-input'), '描述')

    const input = screen.getByTestId('ticket-images-input') as HTMLInputElement
    Object.defineProperty(input, 'files', { value: [new File(['x'], 'x.png', { type: 'image/png' })] })
    await fireEvent.change(input)
    await waitFor(() => expect(screen.getByTestId('form-error')).toBeTruthy())

    // 上传失败仍可提交
    await fireEvent.click(screen.getByTestId('ticket-submit'))
    await waitFor(() => expect(createTicketMock).toHaveBeenCalledTimes(1))
  })
})

describe('我的工单列表 TicketListView（CS-113）', () => {
  it('状态 Tab 切换请求参数正确', async () => {
    await renderAt(TicketListView, '/service-center/tickets')
    await waitFor(() => expect(screen.getByTestId('ticket-tab-closed')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('ticket-tab-closed'))
    await waitFor(() => {
      const last = getTicketsMock.mock.calls.at(-1)!
      expect(last[0].status).toBe('closed')
      expect(last[0].page).toBe(1)
    })
  })
})

describe('工单详情 TicketDetailView（CS-113）', () => {
  it('消息流区分 我/客服/系统 三种发送方', async () => {
    await renderAt(TicketDetailView, '/service-center/tickets/9')
    await waitFor(() => expect(screen.getByTestId('msg-1')).toBeTruthy())
    expect(screen.getByTestId('msg-1').getAttribute('data-sender')).toBe('user')
    expect(screen.getByTestId('msg-2').getAttribute('data-sender')).toBe('staff')
    expect(screen.getByTestId('msg-3').getAttribute('data-sender')).toBe('system')
  })

  it('内部备注即使返回也不渲染（前端兜底）', async () => {
    getTicketMock.mockResolvedValue({ data: { data: {
      ticket: detailTicket({ messages: [msg(1, 'user'), msg(4, 'staff', true)] }),
      order: null,
    } } })
    await renderAt(TicketDetailView, '/service-center/tickets/9')
    await waitFor(() => expect(screen.getByTestId('msg-1')).toBeTruthy())
    expect(screen.queryByTestId('msg-4')).toBeNull()
  })

  it('已关闭工单隐藏输入区与关闭按钮', async () => {
    getTicketMock.mockResolvedValue({ data: { data: {
      ticket: detailTicket({ status: 'closed', status_label: '已关闭', can_reply: false, can_close: false }),
      order: null,
    } } })
    await renderAt(TicketDetailView, '/service-center/tickets/9')
    await waitFor(() => expect(screen.getByTestId('ticket-closed-tip')).toBeTruthy())
    expect(screen.queryByTestId('ticket-reply')).toBeNull()
    expect(screen.queryByTestId('ticket-close')).toBeNull()
  })
})
