import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { getDisputesMock, getDisputeMock, assignMock, resolveMock, postMessageMock, getMessagesMock } = vi.hoisted(() => ({
  getDisputesMock: vi.fn(),
  getDisputeMock: vi.fn(),
  assignMock: vi.fn(),
  resolveMock: vi.fn(),
  postMessageMock: vi.fn(),
  getMessagesMock: vi.fn(),
}))

vi.mock('@/api/refund-dispute', () => ({
  getRefundDisputes: getDisputesMock,
  getRefundDispute: getDisputeMock,
  assignRefundDispute: assignMock,
  resolveRefundDispute: resolveMock,
  getRefundDisputeMessages: getMessagesMock,
  postRefundDisputeMessage: postMessageMock,
  REFUND_DISPUTE_STATUS_LABELS: {
    opened: '待介入', platform_involved: '已介入', resolved_refund: '支持买家', resolved_reject: '支持商家', closed: '已关闭',
  },
  REFUND_DISPUTE_STATUS_CLASS: {
    opened: 'bg-amber-50 text-amber-600', platform_involved: 'bg-blue-50 text-blue-500',
    resolved_refund: 'bg-green-100 text-green-600', resolved_reject: 'bg-slate-100 text-slate-500', closed: 'bg-slate-100 text-slate-400',
  },
  REFUND_DISPUTE_REASON_LABELS: {
    refund_rejected: '商家拒绝退款', goods_damaged_dispute: '退货商品争议', timeout_no_process: '超时未处理',
    amount_mismatch: '退款金额争议', not_received_return: '未收到退货/已退未收', other: '其他',
  },
  REFUND_DISPUTE_RESOLUTION_LABELS: {
    resolved_refund: '支持买家（触发退款动作）', resolved_reject: '支持商家（维持原结论）',
  },
  REFUND_DISPUTE_ACTION_LABELS: {
    re_open_refund: '重新发起审核', approve_refund: '同意退款', force_receive: '强制收货(良品全收)', none: '仅记录裁决',
  },
}))

import RefundDisputeView from '@/views/refund/RefundDisputeView.vue'

function sample() {
  return {
    id: 5, public_id: 'dp5', refund_no: 'RF100', refund_status: 'rejected', order_no: 'NO100',
    buyer: { id: 9, username: 'buyer9', nickname: '买家九' },
    reason_code: 'refund_rejected', reason_label: '商家拒绝退款',
    description: '商品无质量问题', evidence: [],
    status: 'opened', status_label: '待介入',
    assignee: null, resolution: null, resolution_note: null, refund_action: null,
    created_at: '2026-09-26 10:00:00', resolved_at: null,
  }
}

beforeEach(() => {
  getDisputesMock.mockResolvedValue({
    data: { data: { list: [sample()], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
  })
  getDisputeMock.mockResolvedValue({
    data: {
      data: {
        ...sample(),
        refund: { refund_no: 'RF100', status: 'rejected', amount: '100.00', type: 'refund', reason: '不想要了', admin_remark: '质检无问题', channel: 'balance' },
        messages: [{ id: 'm1', sender_type: 'customer', sender_id: 9, body: '请尽快处理', attachments: [], created_at: '2026-09-26 10:01:00' }],
      },
    },
  })
  resolveMock.mockResolvedValue({ data: { data: { ...sample(), status: 'resolved_refund', status_label: '支持买家' } } })
  assignMock.mockResolvedValue({ data: { data: { ...sample(), status: 'platform_involved' } } })
  postMessageMock.mockResolvedValue({ data: { data: { id: 'm2' } } })
})

describe('RefundDisputeView（退款纠纷管理页）', () => {
  it('渲染纠纷列表并默认全量查询', async () => {
    const w = mount(RefundDisputeView)
    await flushPromises()
    expect(getDisputesMock).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid="dispute-detail-5"]').exists()).toBe(true)
    expect(w.text()).toContain('商家拒绝退款')
  })

  it('状态 Tab 切换带 status 过滤', async () => {
    const w = mount(RefundDisputeView)
    await flushPromises()
    await w.find('[data-testid="dispute-tab-opened"]').trigger('click')
    await flushPromises()
    expect(getDisputesMock).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'opened' }))
  })

  it('详情抽屉展示快照/消息，提交裁决调用 resolve', async () => {
    const w = mount(RefundDisputeView)
    await flushPromises()

    await w.find('[data-testid="dispute-detail-5"]').trigger('click')
    await flushPromises()

    // 快照 + 消息线程
    expect(w.find('[data-testid="dispute-refund-snapshot"]').exists()).toBe(true)
    expect(w.find('[data-testid="dispute-message"]').exists()).toBe(true)

    // 提交裁决（默认 resolution=resolved_refund + action=none）
    await w.find('[data-testid="dispute-resolve"]').trigger('click')
    await flushPromises()

    expect(resolveMock).toHaveBeenCalledTimes(1)
    expect(resolveMock.mock.calls[0][0]).toBe(5)
    expect(resolveMock.mock.calls[0][1]).toEqual({
      resolution: 'resolved_refund',
      refund_action: 'none',
      note: undefined,
    })
  })
})
