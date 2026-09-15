import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * 后台支付管理 / 支付日志 / 订单流水（Vitest）
 * 覆盖：统计卡与列表渲染、筛选参数、关闭二次确认、日志 JSON 详情、空态、订单号预填。
 */
const {
  getPaymentsMock, getPaymentMock, closePaymentMock,
  getPaymentLogsMock, getPaymentLogMock, getOrderLogsMock,
} = vi.hoisted(() => ({
  getPaymentsMock: vi.fn(),
  getPaymentMock: vi.fn(),
  closePaymentMock: vi.fn(),
  getPaymentLogsMock: vi.fn(),
  getPaymentLogMock: vi.fn(),
  getOrderLogsMock: vi.fn(),
}))

vi.mock('@/api/payment', () => ({
  getPayments: getPaymentsMock,
  getPayment: getPaymentMock,
  closePayment: closePaymentMock,
  getPaymentLogs: getPaymentLogsMock,
  getPaymentLog: getPaymentLogMock,
  exportPayments: vi.fn(),
  PAYMENT_CHANNEL_LABELS: { wechat: '微信支付', alipay: '支付宝' },
  PAYMENT_STATUS_LABELS: { pending: '待支付', success: '支付成功', failed: '支付失败', closed: '已关闭' },
  PAYMENT_STATUS_CLASS: {
    pending: 'bg-orange-100 text-orange-500',
    success: 'bg-green-100 text-green-600',
    failed: 'bg-red-100 text-red-500',
    closed: 'bg-slate-100 text-slate-500',
  },
  PAYMENT_EVENT_LABELS: { create: '创建支付单', callback: '渠道回调', notify: '异步通知', close: '后台关闭' },
}))

vi.mock('@/api/order', () => ({
  getOrderLogs: getOrderLogsMock,
  OPERATOR_TYPE_LABELS: { user: '用户', admin: '管理员', system: '系统' },
  ORDER_STATUS_LABELS: {
    pending_payment: '待支付', paid: '已支付', shipped: '已发货', completed: '已完成',
    cancelled: '已取消', refunding: '退款中', refunded: '已退款',
  },
}))

import PaymentView from '@/views/order/PaymentView.vue'
import PaymentLogView from '@/views/order/PaymentLogView.vue'
import OrderLogView from '@/views/order/OrderLogView.vue'

function freshPinia(permissions: string[] = [], roles: string[] = ['super_admin']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'admin', nickname: null, avatar: null, phone: null, email: null,
    roles, permissions,
  }
  return pinia
}

function makeRouter(query: Record<string, string> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
  void query
  return router
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>, router = makeRouter()) => ({
  plugins: [pinia, router],
  directives: { permission },
})

const paymentFixture = {
  id: 11,
  payment_no: 'PAY20260916001',
  order_id: 5,
  order_no: 'CS20260916001',
  user_id: 3,
  channel: 'wechat' as const,
  channel_label: '微信支付',
  amount: '1234.50',
  status: 'pending' as const,
  status_label: '待支付',
  channel_trade_no: null,
  paid_at: null,
  created_at: '2026-09-16 10:00:00',
  log_count: 1,
}

const listResult = {
  list: [paymentFixture],
  pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
  summary: { total: 1, success_count: 0, success_amount: '0.00', pending_count: 1, failed_count: 0 },
}

function mockPayments() {
  getPaymentsMock.mockResolvedValue({ data: { data: listResult } })
  getPaymentMock.mockResolvedValue({
    data: {
      data: {
        ...paymentFixture,
        order: {
          id: 5, order_no: 'CS20260916001', status: 'pending_payment',
          status_label: '待支付', pay_amount: '1234.50', created_at: '2026-09-16 09:59:00',
        },
        logs: [
          {
            id: 7, payment_id: 11, payment_no: 'PAY20260916001', event: 'create',
            event_label: '创建支付单', request_preview: '{"channel":"wechat"}',
            response_preview: null, created_at: '2026-09-16 10:00:00',
          },
        ],
      },
    },
  })
  closePaymentMock.mockResolvedValue({ data: { data: { ...paymentFixture, status: 'closed' } } })
}

describe('支付管理 PaymentView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockPayments()
    freshPinia()
  })

  it('渲染汇总统计卡与列表行', async () => {
    const wrapper = mount(PaymentView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="summary-total"]').text()).toBe('1')
    expect(wrapper.find('[data-testid="summary-pending"]').text()).toBe('1')
    expect(wrapper.find('[data-testid="summary-success-amount"]').text()).toContain('0.00')
    expect(wrapper.text()).toContain('PAY20260916001')
    expect(wrapper.text()).toContain('微信支付')
    expect(wrapper.text()).toContain('¥1,234.50')
  })

  it('搜索携带筛选参数并回到第一页', async () => {
    const wrapper = mount(PaymentView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="filter-payment-no"]').setValue('PAY001')
    await wrapper.find('[data-testid="filter-channel"]').setValue('wechat')
    await wrapper.find('[data-testid="filter-status"]').setValue('pending')
    await wrapper.find('[data-testid="filter-search"]').trigger('click')
    await flushPromises()

    const params = getPaymentsMock.mock.calls.at(-1)?.[0] as Record<string, unknown>
    expect(params.payment_no).toBe('PAY001')
    expect(params.channel).toBe('wechat')
    expect(params.status).toBe('pending')
    expect(params.page).toBe(1)
  })

  it('状态胶囊快捷筛选直接触发查询', async () => {
    const wrapper = mount(PaymentView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="status-tab-success"]').trigger('click')
    await flushPromises()

    expect((getPaymentsMock.mock.calls.at(-1)?.[0] as Record<string, unknown>).status).toBe('success')
  })

  it('详情弹窗展示订单摘要与支付日志', async () => {
    const wrapper = mount(PaymentView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="detail-11"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="payment-detail"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="detail-order"]').text()).toContain('CS20260916001')
    expect(wrapper.find('[data-testid="detail-logs"]').text()).toContain('创建支付单')
  })

  it('关闭需二次确认且提交原因', async () => {
    const wrapper = mount(PaymentView, { global: globalCfg(freshPinia(['payment.manage'])) })
    await flushPromises()

    await wrapper.find('[data-testid="close-11"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="close-dialog"]').exists()).toBe(true)

    await wrapper.find('[data-testid="close-reason"]').setValue('用户放弃支付')
    await wrapper.find('[data-testid="close-submit"]').trigger('click')
    await flushPromises()

    expect(closePaymentMock).toHaveBeenCalledWith(11, '用户放弃支付')
    expect(getPaymentsMock).toHaveBeenCalledTimes(2) // 初始加载 + 关闭后刷新
  })

  it('无 payment.manage 权限时不显示关闭按钮（运营角色）', async () => {
    const wrapper = mount(PaymentView, { global: globalCfg(freshPinia(['payment.view'], ['operator'])) })
    await flushPromises()

    expect(wrapper.find('[data-testid="close-11"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="detail-11"]').exists()).toBe(true)
  })

  it('列表为空展示空态', async () => {
    getPaymentsMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 20, total: 0, total_pages: 1 }, summary: listResult.summary } },
    })
    const wrapper = mount(PaymentView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="empty-state"]').text()).toContain('暂时无数据')
  })
})

describe('支付日志 PaymentLogView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getPaymentLogsMock.mockResolvedValue({
      data: {
        data: {
          list: [
            {
              id: 7, payment_id: 11, payment_no: 'PAY20260916001', event: 'create',
              event_label: '创建支付单', request_preview: '{"channel":"wechat"}',
              response_preview: '{"ok":true}', created_at: '2026-09-16 10:00:00',
            },
          ],
          pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
        },
      },
    })
    getPaymentLogMock.mockResolvedValue({
      data: {
        data: {
          id: 7, payment_id: 11, payment_no: 'PAY20260916001', event: 'create',
          event_label: '创建支付单', request_preview: null, response_preview: null,
          created_at: '2026-09-16 10:00:00',
          request_data: { channel: 'wechat', amount: '70.00' },
          response_data: { ok: true },
        },
      },
    })
    freshPinia()
  })

  it('渲染列表摘要', async () => {
    const wrapper = mount(PaymentLogView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.text()).toContain('PAY20260916001')
    expect(wrapper.text()).toContain('创建支付单')
    expect(wrapper.text()).toContain('{"channel":"wechat"}')
  })

  it('按事件筛选', async () => {
    const wrapper = mount(PaymentLogView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="filter-event"]').setValue('callback')
    await wrapper.find('[data-testid="filter-search"]').trigger('click')
    await flushPromises()

    expect((getPaymentLogsMock.mock.calls.at(-1)?.[0] as Record<string, unknown>).event).toBe('callback')
  })

  it('详情展示完整请求与响应 JSON', async () => {
    const wrapper = mount(PaymentLogView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="detail-7"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="detail-request"]').text()).toContain('"channel": "wechat"')
    expect(wrapper.find('[data-testid="detail-response"]').text()).toContain('"ok": true')
  })
})

describe('订单流水 OrderLogView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getOrderLogsMock.mockResolvedValue({
      data: {
        data: {
          list: [
            {
              id: 3, order_id: 5, order_no: 'CS20260916001',
              from_status: 'paid', from_status_label: '已支付',
              to_status: 'shipped', to_status_label: '已发货',
              operator_type: 'admin', operator_type_label: '管理员',
              operator_id: 1, operator_name: '超级管理员',
              remark: '顺丰 SF123', created_at: '2026-09-16 12:00:00',
            },
            {
              id: 2, order_id: 5, order_no: 'CS20260916001',
              from_status: 'pending_payment', from_status_label: '待支付',
              to_status: 'paid', to_status_label: '已支付',
              operator_type: 'system', operator_type_label: '系统',
              operator_id: null, operator_name: null,
              remark: null, created_at: '2026-09-16 11:00:00',
            },
          ],
          pagination: { page: 1, page_size: 20, total: 2, total_pages: 1 },
        },
      },
    })
    freshPinia()
  })

  it('渲染状态流转与操作人', async () => {
    const wrapper = mount(OrderLogView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    const flow = wrapper.findAll('[data-testid="flow"]')
    expect(flow[0].text()).toContain('已支付')
    expect(flow[0].text()).toContain('已发货')
    expect(wrapper.text()).toContain('管理员#1（超级管理员）')
    expect(wrapper.text()).toContain('顺丰 SF123')
    expect(wrapper.text()).toContain('系统')
  })

  it('按订单号与操作人类型筛选', async () => {
    const wrapper = mount(OrderLogView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="filter-order-no"]').setValue('CS001')
    await wrapper.find('[data-testid="filter-operator"]').setValue('admin')
    await wrapper.find('[data-testid="filter-search"]').trigger('click')
    await flushPromises()

    const params = getOrderLogsMock.mock.calls.at(-1)?.[0] as Record<string, unknown>
    expect(params.order_no).toBe('CS001')
    expect(params.operator_type).toBe('admin')
  })

  it('空数据展示空态', async () => {
    getOrderLogsMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 20, total: 0, total_pages: 1 } } },
    })
    const wrapper = mount(OrderLogView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="empty-state"]').text()).toContain('暂时无数据')
  })
})
