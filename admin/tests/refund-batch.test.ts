import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * 后台退款批量审核 + 导出（#5，Vitest）
 * 覆盖：行选择/全选、批量按钮出现与提交、导出触发。
 */
const { getRefundsMock, batchProcessRefundsMock, exportRefundsMock } = vi.hoisted(() => ({
  getRefundsMock: vi.fn(),
  batchProcessRefundsMock: vi.fn(),
  exportRefundsMock: vi.fn(),
}))

vi.mock('@/api/refund', () => ({
  getRefunds: getRefundsMock,
  getRefundDetail: vi.fn(),
  processRefund: vi.fn(),
  receiveRefund: vi.fn(),
  getRefundLogs: vi.fn(),
  retryRefund: vi.fn(),
  batchProcessRefunds: batchProcessRefundsMock,
  exportRefunds: exportRefundsMock,
  REFUND_MAX_RETRY: 3,
  REFUND_CHANNEL_LABELS: { wechat: '微信支付', alipay: '支付宝', balance: '余额', offline: '线下' },
  REFUND_STATUS_LABELS: { pending: '待审核', approved: '已同意', rejected: '已拒绝', success: '退款成功', failed: '退款失败', processing: '退款中' },
  REFUND_STATUS_CLASS: {
    pending: 'bg-orange-100 text-orange-500', approved: 'bg-blue-100 text-blue-500',
    rejected: 'bg-slate-100 text-slate-500', success: 'bg-green-100 text-green-600', failed: 'bg-red-100 text-red-500', processing: 'bg-blue-50 text-blue-500',
  },
  REFUND_TYPE_LABELS: { refund: '仅退款', return_refund: '退货退款' },
  RETURN_STATUS_LABELS: { waiting_return: '待退货', shipping: '退货中', received: '已收货', exception: '异常' },
  REFUND_ACTION_LABELS: {
    apply: '用户提交申请', process_approve: '后台同意退款', process_reject: '后台拒绝退款',
    return_received: '后台确认收货', coupon_returned: '返还优惠券',
  },
}))

vi.mock('@/api/product', () => ({ uploadImage: vi.fn() }))

import RefundView from '@/views/refund/RefundView.vue'

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'admin', nickname: '管理员', avatar: null, phone: null, email: null,
    roles: ['super_admin'], permissions: ['refund.view', 'refund.process'],
  }
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

function makeRefund(id: number) {
  return {
    id,
    refund_no: `RF2026092600${id}`,
    order_id: 5,
    order_no: `CS2026092600${id}`,
    user_id: 3,
    type: 'refund' as const,
    amount: '88.00',
    reason: '不想要了',
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
    created_at: '2026-09-26 10:00:00',
    order_status: 'refunding',
  }
}

describe('后台退款批量审核 + 导出 RefundView（#5）', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getRefundsMock.mockResolvedValue({
      data: {
        data: {
          list: [makeRefund(21), makeRefund(22)],
          pagination: { page: 1, page_size: 20, total: 2, total_pages: 1 },
        },
      },
    })
    batchProcessRefundsMock.mockResolvedValue({
      data: {
        code: 0,
        data: {
          total: 2, succeeded_count: 2, failed_count: 0,
          succeeded: [
            { id: 22, refund_no: 'RF202609260022', status: 'rejected', status_label: '已拒绝' },
            { id: 21, refund_no: 'RF202609260021', status: 'rejected', status_label: '已拒绝' },
          ],
          failed: [],
        },
      },
    })
    freshPinia()
  })

  it('未选择时不显示批量按钮，导出按钮始终可见', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="export-refunds"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="batch-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="batch-reject"]').exists()).toBe(false)
  })

  it('点击行选择与全选：显示已选数与批量按钮', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="select-21"]').setValue(true)
    await flushPromises()
    expect(wrapper.find('[data-testid="selected-count"]').text()).toContain('1')
    expect(wrapper.find('[data-testid="batch-approve"]').exists()).toBe(true)

    // 全选 → 2 单
    await wrapper.find('[data-testid="select-all"]').setValue(true)
    await flushPromises()
    expect(wrapper.find('[data-testid="selected-count"]').text()).toContain('2')
  })

  it('批量拒绝提交所选 ids，成功后展示结果提示并刷新列表', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="select-all"]').setValue(true)
    await wrapper.find('[data-testid="batch-reject"]').trigger('click')
    await flushPromises()

    expect(batchProcessRefundsMock).toHaveBeenCalledWith([21, 22], 'reject')
    expect(wrapper.find('[data-testid="batch-tip"]').text()).toContain('成功 2 单')
    // 成功后清空选择
    expect(wrapper.find('[data-testid="batch-reject"]').exists()).toBe(false)
    // 刷新列表
    expect(getRefundsMock).toHaveBeenCalledTimes(2)
  })

  it('批量有失败单时在错误提示中展示失败原因', async () => {
    batchProcessRefundsMock.mockResolvedValue({
      data: {
        code: 0,
        data: {
          total: 2, succeeded_count: 1, failed_count: 1,
          succeeded: [{ id: 21, refund_no: 'RF202609260021', status: 'rejected', status_label: '已拒绝' }],
          failed: [{ id: 22, refund_no: 'RF202609260022', reason: '退款单已处理，请勿重复操作' }],
        },
      },
    })
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="select-22"]').setValue(true)
    await wrapper.find('[data-testid="select-21"]').setValue(true)
    await wrapper.find('[data-testid="batch-approve"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('成功 1 单，失败 1 单')
    expect(wrapper.text()).toContain('退款单已处理')
  })

  it('导出按钮触发 exportRefunds 并携带当前状态筛选', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="export-refunds"]').trigger('click')
    await flushPromises()

    expect(exportRefundsMock).toHaveBeenCalledWith({ status: undefined })
  })
})
