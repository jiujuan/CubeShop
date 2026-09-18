import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const {
  getCsTicketsMock, getCsTicketMock, getCsTicketTypesMock, getCsQuickRepliesByTypeMock, replyCsTicketMock,
  changeCsTicketStatusMock, assignCsTicketMock, setCsTicketPriorityMock, batchAssignCsTicketsMock,
  getCsAssigneesMock, uploadImageMock,
} = vi.hoisted(() => ({
  getCsTicketsMock: vi.fn(),
  getCsTicketMock: vi.fn(),
  getCsTicketTypesMock: vi.fn(),
  getCsQuickRepliesByTypeMock: vi.fn(),
  replyCsTicketMock: vi.fn(),
  changeCsTicketStatusMock: vi.fn(),
  assignCsTicketMock: vi.fn(),
  setCsTicketPriorityMock: vi.fn(),
  batchAssignCsTicketsMock: vi.fn(),
  getCsAssigneesMock: vi.fn(),
  uploadImageMock: vi.fn(),
}))

vi.mock('@/api/cs', () => ({
  getCsTickets: getCsTicketsMock,
  getCsTicket: getCsTicketMock,
  getCsTicketTypes: getCsTicketTypesMock,
  getCsQuickRepliesByType: getCsQuickRepliesByTypeMock,
  replyCsTicket: replyCsTicketMock,
  changeCsTicketStatus: changeCsTicketStatusMock,
  assignCsTicket: assignCsTicketMock,
  setCsTicketPriority: setCsTicketPriorityMock,
  batchAssignCsTickets: batchAssignCsTicketsMock,
  getCsAssignees: getCsAssigneesMock,
}))
vi.mock('@/api/product', () => ({ uploadImage: uploadImageMock }))

import CsTicketView from '@/views/cs/CsTicketView.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

function freshPinia(permissions: string[] = ['cs.ticket.view', 'cs.ticket.handle']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'kefu', nickname: null, avatar: null, phone: null, email: null,
    roles: ['operator'], permissions,
  }
  return pinia
}

function makeRouter(component: unknown = CsTicketView) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/cs/tickets', component: component as never },
      { path: '/dashboard', component: { template: '<div />' } },
    ],
  })
  return router
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>, component?: unknown) => ({
  plugins: [pinia, makeRouter(component)],
  directives: { permission },
})

const row = (overrides: Record<string, unknown> = {}) => ({
  id: 1, ticket_no: 'TK20260917000001', type_id: 1, type: { id: 1, name: '物流问题' },
  user_id: 2, user: { id: 2, nickname: '小明', username: 'ming' }, order_id: null,
  title: '物流太慢', contact: '138****0000', status: 'pending', status_label: '待处理',
  priority: 0, priority_label: '普通', assignee_id: null, assignee: null,
  last_message_at: '2026-09-17 10:00:00', created_at: '2026-09-17 10:00:00', ...overrides,
})

const msg = (id: number, senderType: string, isInternal = false) => ({
  id, sender_type: senderType, sender_name: { user: '我', staff: '客服', system: '系统' }[senderType as 'user'] ?? senderType,
  content: `消息 ${id}`, images: [], is_internal: isInternal, created_at: '2026-09-17 10:00:00',
})

const detail = (overrides: Record<string, unknown> = {}) => ({
  ...row(),
  type_name: '物流问题',
  messages: [msg(1, 'user'), msg(2, 'staff', true), msg(3, 'system')],
  ...overrides,
})

/** CS-202 订单快照（后端 order_snapshot 节点） */
const orderSnapshot = (overrides: Record<string, unknown> = {}) => ({
  order_id: 9,
  order_no: 'CS20260917000001',
  status: 'shipped',
  status_label: '已发货',
  pay_amount: '258.00',
  created_at: '2026-09-17 09:00:00',
  item_count: 3,
  items: [
    { product_id: 1, sku_id: 1, title: '蓝牙耳机', specs: { 颜色: '黑' }, image: '/storage/sku/a.png', price: '129.00', quantity: 2, total_amount: '258.00', payable_amount: 258 },
    { product_id: 2, sku_id: 2, title: '数据线', specs: null, image: null, price: '29.00', quantity: 1, total_amount: '29.00', payable_amount: 29 },
  ],
  address: { contact_name: '张三', phone_masked: '138****1234', full_address: '广东省深圳市南山区科技园 1 号' },
  shipping: {
    company_code: 'SF', company_name: '顺丰速运', tracking_no: 'SF1234567890',
    trace_status: 'in_transit', shipped_at: '2026-09-15 10:00:00', delivered_at: null,
    latest_trace: { context: '派送中', occurred_at: '2026-09-17 08:00:00' },
  },
  refunds: [
    { refund_no: 'RF2026001', amount: '20.00', status: 'pending', created_at: '2026-09-16 10:00:00', admin_remark: '已核实凭证' },
  ],
  jump: { order_id: 9, order_no: 'CS20260917000001', latest_refund_no: 'RF2026001' },
  ...overrides,
})

function mockDetail(
  overrides: Record<string, unknown> = {},
  actions = { can_reply: true, can_complete: true, can_close: true },
  order: unknown = null,
) {
  getCsTicketMock.mockResolvedValue({
    data: { data: {
      ticket: detail(overrides),
      user_summary: { id: 2, nickname: '小明', phone_masked: '138****0000', registered_at: '2026-09-01', ticket_count: 2 },
      order_snapshot: order,
      order: null,
      actions,
    } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getCsTicketsMock.mockResolvedValue({ data: { data: {
    list: [row()], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 }, meta: { pending_count: 3 },
  } } })
  getCsTicketTypesMock.mockResolvedValue({ data: { data: [{ id: 1, name: '物流问题', code: 'logistics', require_order: true }] } })
  getCsQuickRepliesByTypeMock.mockResolvedValue({ data: { data: [] } })
  getCsAssigneesMock.mockResolvedValue({ data: { data: [{ id: 5, username: 'kefu2', nickname: '客服小美' }] } })
  mockDetail()
  assignCsTicketMock.mockResolvedValue({ data: { data: detail({ assignee_id: 5 }) } })
  changeCsTicketStatusMock.mockResolvedValue({ data: { data: detail({ status: 'processing' }) } })
})

async function mountView(permissions?: string[]) {
  const wrapper = mount(CsTicketView, { global: globalCfg(freshPinia(permissions)) })
  await flushPromises()
  return wrapper
}

describe('客服工单工作台 CsTicketView（CS-114）', () => {
  it('列表渲染与待处理红点数字与接口一致', async () => {
    const wrapper = await mountView()
    expect(wrapper.find('[data-testid="cs-pending-count"]').text()).toBe('3')
    expect(wrapper.find('[data-testid="cs-ticket-row-1"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('TK20260917000001')
  })

  it('筛选条件变化触发列表重新请求且参数正确', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-status-filter"]').setValue('pending')
    await flushPromises()
    expect(getCsTicketsMock.mock.calls.at(-1)![0].status).toBe('pending')

    await wrapper.find('[data-testid="cs-keyword-input"]').setValue('138')
    await wrapper.find('[data-testid="cs-search-btn"]').trigger('click')
    await flushPromises()
    expect(getCsTicketsMock.mock.calls.at(-1)![0].keyword).toBe('138')
    expect(getCsTicketsMock.mock.calls.at(-1)![0].page).toBe(1)
  })

  it('详情：消息流区分发送方，内部备注带「仅客服可见」标记', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-msg-1"]').attributes('data-sender')).toBe('user')
    expect(wrapper.find('[data-testid="cs-msg-2"]').attributes('data-sender')).toBe('staff')
    expect(wrapper.find('[data-testid="cs-msg-3"]').attributes('data-sender')).toBe('system')
    expect(wrapper.find('[data-testid="cs-internal-tag"]').text()).toBe('仅客服可见')
  })

  it('状态变更按钮按状态矩阵给出可选目标', async () => {
    mockDetail({ status: 'pending' })
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-status-btn-processing"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cs-status-btn-closed"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cs-status-btn-completed"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="cs-status-btn-waiting_user"]').exists()).toBe(false)
  })

  it('无 cs.ticket.handle 权限时隐藏回复框与操作按钮', async () => {
    const wrapper = await mountView(['cs.ticket.view'])
    await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-reply-box"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="cs-actions"]').exists()).toBe(false)
  })

  it('有 handle 权限时展示回复框，可发送回复', async () => {
    replyCsTicketMock.mockResolvedValue({ data: { data: { message: msg(4, 'staff'), ticket: detail() } } })
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-reply-box"]').exists()).toBe(true)
    await wrapper.find('[data-testid="cs-reply-input"]').setValue('已为您催单')
    await wrapper.find('[data-testid="cs-reply-send"]').trigger('click')
    await flushPromises()

    expect(replyCsTicketMock).toHaveBeenCalledWith(1, expect.objectContaining({ content: '已为您催单' }))
  })

  it('转交客服调用接口并刷新列表', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
    await flushPromises()

    // 转交名单来自 /admin/cs/assignees（按 cs.ticket.view 过滤），不再依赖 account.manage 的账号接口
    expect(getCsAssigneesMock).toHaveBeenCalled()
    const options = wrapper.findAll('[data-testid="cs-assign-select"] option')
    expect(options.map((o) => o.text())).toEqual(['未分配', '客服小美'])

    const before = getCsTicketsMock.mock.calls.length
    await wrapper.find('[data-testid="cs-assign-select"]').setValue('5')
    await flushPromises()

    expect(assignCsTicketMock).toHaveBeenCalledWith(1, 5)
    expect(getCsTicketsMock.mock.calls.length).toBeGreaterThan(before)
  })

  it('CS-202：订单卡消费 order_snapshot，展示商品清单/脱敏收货/最新物流/退款记录', async () => {
    mockDetail({}, { can_reply: true, can_complete: true, can_close: true }, orderSnapshot())
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
    await flushPromises()

    const card = wrapper.find('[data-testid="cs-order-card"]')
    expect(card.exists()).toBe(true)

    // 订单头：单号 / 状态 / 实付 / 件数
    expect(wrapper.find('[data-testid="cs-order-no"]').text()).toBe('CS20260917000001')
    expect(wrapper.find('[data-testid="cs-order-status"]').text()).toBe('已发货')
    expect(card.text()).toContain('¥258.00')
    expect(card.text()).toContain('3 件')

    // 商品清单逐行
    const items = wrapper.findAll('[data-testid="cs-order-items"] li')
    expect(items).toHaveLength(2)
    expect(items[0].text()).toContain('蓝牙耳机')
    expect(items[0].text()).toContain('×2')
    expect(items[1].text()).toContain('数据线')

    // 收货：手机号脱敏（明文不出现）
    const address = wrapper.find('[data-testid="cs-order-address"]')
    expect(address.text()).toContain('张三')
    expect(address.text()).toContain('138****1234')
    expect(address.text()).not.toContain('13800001234')

    // 物流：最新一条轨迹
    const shipping = wrapper.find('[data-testid="cs-order-shipping"]')
    expect(shipping.text()).toContain('顺丰速运')
    expect(shipping.text()).toContain('SF1234567890')
    expect(shipping.text()).toContain('派送中')

    // 退款记录（状态转中文文案）
    const refunds = wrapper.findAll('[data-testid="cs-order-refunds"] li')
    expect(refunds).toHaveLength(1)
    expect(refunds[0].text()).toContain('RF2026001')
    expect(refunds[0].text()).toContain('待审核')
    expect(refunds[0].text()).toContain('¥20.00')

    // 一键跳订单详情
    expect(wrapper.find('[data-testid="cs-order-jump"]').attributes('href')).toBe('/orders/9')
  })

  it('CS-204：快捷回复下拉按工单类型取用，选中后插入到文本域光标处并完成变量替换', async () => {
    // 后台按当前工单类型（type_id=1）返回「通用 + 专属」模板
    getCsQuickRepliesByTypeMock.mockImplementation((typeId: number | null) => {
      const all = [
        { id: 1, type_id: null, type_name: null, title: '通用开场', content: '您好{user_nickname}，工单{ticket_no}已受理', sort: 1, created_by: 1, updated_by: null, created_at: '', updated_at: '' },
        { id: 2, type_id: 1, type_name: '物流问题', title: '物流专属', content: '订单{order_no}正在派送', sort: 2, created_by: 1, updated_by: null, created_at: '', updated_at: '' },
        { id: 3, type_id: 99, type_name: '其他', title: '其他类型', content: '其他', sort: 3, created_by: 1, updated_by: null, created_at: '', updated_at: '' },
      ]
      const data = typeId == null ? all : all.filter((r) => r.type_id === null || r.type_id === typeId)
      return Promise.resolve({ data: { data } })
    })

    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
    await flushPromises()

    // 打开快捷回复下拉
    await wrapper.find('[data-testid="cs-quick-reply-toggle"]').trigger('click')
    await flushPromises()

    // 下拉只含通用 + 本类型（排除 type_id=99）
    expect(getCsQuickRepliesByTypeMock).toHaveBeenCalledWith(1)
    const items = wrapper.findAll('[data-testid="cs-quick-reply-item"]')
    expect(items).toHaveLength(2)
    expect(items[0].text()).toContain('通用开场')
    expect(items[1].text()).toContain('物流专属')

    // 选中「通用开场」→ 插入到光标处 + 变量替换
    await wrapper.find('[data-testid="cs-quick-reply-item"]').trigger('click')
    await flushPromises()
    const input = wrapper.find('[data-testid="cs-reply-input"]')
    // 工单详情 row() 的 user.nickname=小明、ticket_no=TK20260917000001
    expect((input.element as HTMLTextAreaElement).value).toBe('您好小明，工单TK20260917000001已受理')

    // 发送时携带替换后的内容
    replyCsTicketMock.mockResolvedValue({ data: { data: { message: msg(4, 'staff'), ticket: detail() } } })
    await wrapper.find('[data-testid="cs-reply-send"]').trigger('click')
    await flushPromises()
    expect(replyCsTicketMock).toHaveBeenCalledWith(1, expect.objectContaining({
      content: '您好小明，工单TK20260917000001已受理',
    }))
  })

  it('CS-204：无模板时下拉展示空态与「去添加」入口', async () => {
    getCsQuickRepliesByTypeMock.mockResolvedValue({ data: { data: [] } })
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="cs-quick-reply-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-quick-reply-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cs-quick-reply-goto"]').attributes('href')).toBe('/cs/quick-replies')
  })

  it('CS-204：快捷回复面板向上展开，内容过多时面板内部滚动', async () => {
    getCsQuickRepliesByTypeMock.mockResolvedValue({
      data: {
        data: [
          { id: 1, type_id: null, type_name: null, title: '通用开场', content: '您好{user_nickname}', sort: 1, created_by: 1, updated_by: null, created_at: '', updated_at: '' },
        ],
      },
    })
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="cs-quick-reply-toggle"]').trigger('click')
    await flushPromises()

    const panel = wrapper.find('[data-testid="cs-quick-reply-panel"]')
    expect(panel.exists()).toBe(true)

    const cls = panel.classes()
    // 向上展开：底边贴住触发按钮顶边（bottom-full），不再是向下展开的 mt-1
    expect(cls).toContain('bottom-full')
    expect(cls).toContain('mb-1')
    expect(cls).not.toContain('mt-1')
    // 模板很多时由面板自身限高 + 纵向滚动，而不是撑出页面去滚整页
    expect(cls).toContain('max-h-64')
    expect(cls).toContain('overflow-y-auto')
  })

  it('CS-204：面板展开时箭头翻转；点击面板外部收起、面板内部不收起', async () => {
    getCsQuickRepliesByTypeMock.mockResolvedValue({
      data: {
        data: [
          { id: 1, type_id: null, type_name: null, title: '通用开场', content: '您好', sort: 1, created_by: 1, updated_by: null, created_at: '', updated_at: '' },
        ],
      },
    })
    // attachTo document：让面板内部的 mousedown 能真实冒泡到 document 上的全局监听器
    const wrapper = mount(CsTicketView, { attachTo: document.body, global: globalCfg(freshPinia()) })
    await flushPromises()
    try {
      await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
      await flushPromises()

      const toggle = () => wrapper.find('[data-testid="cs-quick-reply-toggle"]')
      const panel = () => wrapper.find('[data-testid="cs-quick-reply-panel"]')

      // 打开：面板出现，箭头翻转（面板向上展开，箭头指向上）
      await toggle().trigger('click')
      await flushPromises()
      expect(panel().exists()).toBe(true)
      expect(toggle().element.querySelector('svg')?.classList.contains('rotate-180')).toBe(true)

      // 点击面板外部（页面其它区域）→ 收起
      document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
      await flushPromises()
      expect(panel().exists()).toBe(false)

      // 重新打开后，点击面板内部（mousedown 冒泡到 document）→ 不收起
      await toggle().trigger('click')
      await flushPromises()
      expect(panel().exists()).toBe(true)
      panel().element.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
      await flushPromises()
      expect(panel().exists()).toBe(true)

      // 再点一次「快捷回复」按钮仍可收起（toggle 行为不变）
      await toggle().trigger('click')
      await flushPromises()
      expect(panel().exists()).toBe(false)

      // 关抽屉不应遗留面板展开态：遮罩 click.self 直接关抽屉（不经 mousedown，外部点击 handler 不会兜底），
      // closeDrawer 内重置 quickReplyOpen 后，再开详情面板应为收起
      await toggle().trigger('click')
      await flushPromises()
      expect(panel().exists()).toBe(true)
      await wrapper.find('[data-testid="cs-ticket-drawer"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-testid="cs-ticket-drawer"]').exists()).toBe(false)
      await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-testid="cs-quick-reply-panel"]').exists()).toBe(false)
    } finally {
      wrapper.unmount()
    }
  })

  it('CS-202：无关联订单时订单卡不渲染', async () => {
    mockDetail({}, { can_reply: true, can_complete: true, can_close: true }, null)
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-detail-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-order-card"]').exists()).toBe(false)
  })
})

describe('侧栏客服菜单权限显隐（CS-114 / R7）', () => {
  async function mountLayout(permissions: string[]) {
    const pinia = freshPinia(permissions)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: AdminLayout, children: [{ path: '', component: { template: '<div />' } }] }],
    })
    router.push('/')
    await router.isReady()
    const wrapper = mount(AdminLayout, { global: { plugins: [pinia, router] } })
    await flushPromises()
    return wrapper
  }

  it('无 cs.ticket.view / cs.faq.manage 时不渲染客服菜单', async () => {
    const wrapper = await mountLayout(['review.manage'])
    expect(wrapper.text()).not.toContain('服务工单')
    expect(wrapper.text()).not.toContain('帮助中心')
  })

  it('有权限时渲染「服务工单」「帮助中心」菜单', async () => {
    const wrapper = await mountLayout(['cs.ticket.view', 'cs.faq.manage'])
    expect(wrapper.text()).toContain('服务工单')
    expect(wrapper.text()).toContain('帮助中心')
  })

  it('客服视角不显示工作台入口（工作台含经营数据，客服进不去）', async () => {
    // 客服角色：只有 cs.*，没有 dashboard.view
    const wrapper = await mountLayout(['cs.ticket.view', 'cs.ticket.handle', 'cs.faq.manage'])
    expect(wrapper.text()).not.toContain('工作台')
    expect(wrapper.text()).toContain('服务工单')

    // 有 dashboard.view 的角色照常显示
    const withDashboard = await mountLayout(['dashboard.view', 'cs.ticket.view'])
    expect(withDashboard.text()).toContain('工作台')
  })
})
