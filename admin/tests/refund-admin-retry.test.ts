import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * 后台退款视图 — Phase 5 新增能力（Vitest）
 * 覆盖：失败态重试按钮可见性（达上限置灰）、退款全链路日志抽屉、详情渠道退款区块。
 */
const {
  getRefundsMock,
  getRefundDetailMock,
  processRefundMock,
  receiveRefundMock,
  uploadImageMock,
  getRefundLogsMock,
  retryRefundMock,
} = vi.hoisted(() => ({
  getRefundsMock: vi.fn(),
  getRefundDetailMock: vi.fn(),
  processRefundMock: vi.fn(),
  receiveRefundMock: vi.fn(),
  uploadImageMock: vi.fn(),
  getRefundLogsMock: vi.fn(),
  retryRefundMock: vi.fn(),
}))

vi.mock('@/api/refund', () => ({
  getRefunds: getRefundsMock,
  getRefundDetail: getRefundDetailMock,
  processRefund: processRefundMock,
  receiveRefund: receiveRefundMock,
  getRefundLogs: getRefundLogsMock,
  retryRefund: retryRefundMock,
  REFUND_MAX_RETRY: 3,
  REFUND_CHANNEL_LABELS: { wechat: '微信支付', alipay: '支付宝', balance: '余额', offline: '线下' },
  REFUND_ACTION_LABELS: { apply: '用户提交申请', process_approve: '后台同意退款' },
  REFUND_STATUS_CLASS: { pending: 'a', approved: 'b', rejected: 'c', success: 'd', failed: 'e', processing: 'f' },
  REFUND_STATUS_LABELS: { pending: '待审核', approved: '已同意', rejected: '已拒绝', success: '退款成功', failed: '退款失败', processing: '退款中' },
  REFUND_TYPE_LABELS: { refund: '仅退款', return_refund: '退货退款' },
  RETURN_STATUS_LABELS: { waiting_return: '待退货', shipping: '退货中', received: '已收货', exception: '异常' },
}))

vi.mock('@/api/product', () => ({ uploadImage: uploadImageMock }))

import RefundView from '@/views/refund/RefundView.vue'

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = { id: 1, username: 'admin', nickname: '管理员', avatar: null, phone: null, email: null, roles: ['super_admin'], permissions: ['refund.view', 'refund.process'] }
  return pinia
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/products/:id', component: { template: '<div />' } },
    ],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia, makeRouter()],
  directives: { permission },
})

const baseRefund = {
  id: 22,
  refund_no: 'RF20260920022',
  order_id: 5,
  order_no: 'CS20260920022',
  user_id: 3,
  type: 'refund' as const,
  amount: '88.00',
  reason: '商品破损',
  images: [],
  status: 'pending' as const,
  return_status: null,
  return_tracking_no: null,
  return_express_company: null,
  return_details: null,
  return_received_details: null,
  return_received_at: null,
  return_exception_reason: null,
  admin_remark: null,
  admin_images: [],
  processed_by: null,
  processed_by_name: null,
  processed_at: null,
  created_at: '2026-09-20 10:00:00',
  order_status: 'pending_ship',
  // Phase 4/5 渠道字段
  channel: 'wechat',
  out_refund_no: 'R22',
  channel_refund_no: 'CH22',
  refund_status: 'PROCESSING',
  failed_reason: null,
  retry_count: 0,
  refunded_at: null,
  max_retry: 3,
}

const listResult = { list: [baseRefund], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } }

describe('后台退款 — 重试与日志（Phase 5）', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getRefundsMock.mockResolvedValue({ data: { data: listResult } })
    retryRefundMock.mockResolvedValue({ data: { data: { ...baseRefund, status: 'processing' } } })
    getRefundLogsMock.mockResolvedValue({ data: { data: { refund_id: 22, logs: [] } } })
    freshPinia()
  })

  it('失败态且未达重试上限 → 显示「重试」按钮', async () => {
    getRefundsMock.mockResolvedValue({
      data: { data: { list: [{ ...baseRefund, status: 'failed', refund_status: 'FAILED', failed_reason: '渠道拒绝', retry_count: 1 }], pagination: listResult.pagination } },
    })
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    const btn = wrapper.find('[data-testid="retry-22"]')
    expect(btn.exists()).toBe(true)
    expect(btn.text()).toContain('重试')
  })

  it('失败态但已达重试上限 → 不显示「重试」按钮', async () => {
    getRefundsMock.mockResolvedValue({
      data: { data: { list: [{ ...baseRefund, status: 'failed', refund_status: 'FAILED', failed_reason: '渠道拒绝', retry_count: 3, max_retry: 3 }], pagination: listResult.pagination } },
    })
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="retry-22"]').exists()).toBe(false)
  })

  it('成功态退款 → 不显示「重试」按钮（仅保留详情/日志）', async () => {
    getRefundsMock.mockResolvedValue({
      data: { data: { list: [{ ...baseRefund, status: 'success', refund_status: 'SUCCESS', refunded_at: '2026-09-20 11:00:00' }], pagination: listResult.pagination } },
    })
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="retry-22"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="logs-22"]').exists()).toBe(true)
  })

  it('点击「日志」打开退款全链路日志抽屉并调用 getRefundLogs', async () => {
    getRefundLogsMock.mockResolvedValue({
      data: { data: { refund_id: 22, logs: [{ id: 1, type: 'query', channel: 'wechat', out_refund_no: 'R22', channel_status: 'SUCCESS', actor_type: 'system', actor_id: null, note: '查单成功', request: null, response: { refund_status: 'SUCCESS' }, created_at: '2026-09-20 11:05:00' }] } },
    })
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="logs-22"]').trigger('click')
    await flushPromises()

    expect(getRefundLogsMock).toHaveBeenCalledWith(22)
    expect(wrapper.find('[data-testid="refund-logs"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="refund-log-entry"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="refund-logs"]').text()).toContain('查单成功')
  })

  it('点击「重试」调用 retryRefund 并刷新列表', async () => {
    getRefundsMock.mockResolvedValue({
      data: { data: { list: [{ ...baseRefund, status: 'failed', refund_status: 'FAILED', failed_reason: '渠道拒绝', retry_count: 1 }], pagination: listResult.pagination } },
    })
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="retry-22"]').trigger('click')
    await flushPromises()

    expect(retryRefundMock).toHaveBeenCalledWith(22)
    expect(getRefundsMock).toHaveBeenCalledTimes(2) // 初次加载 + 重试后刷新
  })

  it('详情弹层展示渠道退款区块（渠道中文、我方/渠道单号、渠道状态）', async () => {
    getRefundDetailMock.mockResolvedValue({
      data: { data: { ...baseRefund, status: 'failed', refund_status: 'FAILED', failed_reason: '渠道拒绝', retry_count: 1, user: { id: 3, username: 'buyer', nickname: '买家甲', phone: '13800000000' }, order: { order_no: 'CS20260920022', status: 'pending_ship', pay_amount: '88.00', created_at: '2026-09-20 09:59:00' }, items: [], logs: [] } },
    })
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="detail-22"]').trigger('click')
    await flushPromises()

    const channel = wrapper.find('[data-testid="detail-channel"]')
    expect(channel.exists()).toBe(true)
    expect(channel.text()).toContain('微信支付')
    expect(channel.text()).toContain('R22')
    expect(channel.text()).toContain('CH22')
    expect(channel.text()).toContain('FAILED')
    expect(channel.text()).toContain('渠道拒绝')
    expect(channel.text()).toContain('1 / 3')
  })
})
