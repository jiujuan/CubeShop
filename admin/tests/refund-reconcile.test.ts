import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { getDiffsMock, resolveDiffMock, exportDiffMock } = vi.hoisted(() => ({
  getDiffsMock: vi.fn(),
  resolveDiffMock: vi.fn(),
  exportDiffMock: vi.fn(),
}))

vi.mock('@/api/payment-reconcile', () => ({
  getPaymentReconcileDiffs: getDiffsMock,
  resolvePaymentReconcileDiff: resolveDiffMock,
  exportPaymentReconcileDiffs: exportDiffMock,
  RECONCILE_DIFF_TYPE_LABELS: {
    MISSING_LOCAL: '漏单', MISSING_CHANNEL: '本地成功·渠道无记录', AMOUNT_MISMATCH: '金额不一致',
    DUPLICATE_CALLBACK: '重复回调', UNKNOWN: '未知差异',
    REFUND_STATUS_MISMATCH: '退款状态不一致', REFUND_CHANNEL_MISSING: '退款·渠道无记录', REFUND_LOCAL_MISSING: '渠道退款·本地无单',
  },
  RECONCILE_DIFF_TYPE_CLASS: {
    MISSING_LOCAL: 'bg-orange-50', MISSING_CHANNEL: 'bg-red-50', AMOUNT_MISMATCH: 'bg-amber-50',
    DUPLICATE_CALLBACK: 'bg-violet-50', UNKNOWN: 'bg-slate-100',
    REFUND_STATUS_MISMATCH: 'bg-red-50', REFUND_CHANNEL_MISSING: 'bg-orange-50', REFUND_LOCAL_MISSING: 'bg-amber-50',
  },
  RECONCILE_DIFF_STATUS_LABELS: { pending: '待处理', processing: '核查中', resolved: '已处置', ignored: '已忽略' },
  RECONCILE_DIFF_STATUS_CLASS: {
    pending: 'bg-amber-50', processing: 'bg-blue-50', resolved: 'bg-emerald-50', ignored: 'bg-slate-100',
  },
  PAYMENT_CHANNEL_LABELS: { wechat: '微信支付', alipay: '支付宝', balance: '余额支付', offline: '线下转账', mock: '本地模拟' },
}))

import RefundReconcileView from '@/views/refund/RefundReconcileView.vue'

function sample() {
  return {
    id: 21, reconcile_date: '2026-09-26', channel: 'wechat', channel_label: '微信支付', platform: null, platform_label: null,
    diff_type: 'REFUND_STATUS_MISMATCH', diff_type_label: '退款状态不一致', payment_no: 'RF00011', channel_trade_no: 'OUT11',
    order_no: 'NO11', local_amount: '100.00', channel_amount: '100.00', local_status: 'processing', channel_status: 'SUCCESS',
    detail: null, status: 'pending', status_label: '待处理', handled_by: null, handled_at: null, handle_remark: null, created_at: '2026-09-26 10:00:00',
  }
}

beforeEach(() => {
  getDiffsMock.mockResolvedValue({
    data: { data: { list: [sample()], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
  })
  resolveDiffMock.mockResolvedValue({ data: { data: { id: 21, status: 'resolved', status_label: '已处置' } } })
  exportDiffMock.mockResolvedValue(undefined)
})

describe('RefundReconcileView（退款对账差异页）', () => {
  it('按 category=refund 渲染退款差异', async () => {
    const w = mount(RefundReconcileView)
    await flushPromises()
    expect(w.find('[data-testid="diff-row-21"]').exists()).toBe(true)
    // 调用带了 category=refund
    expect(getDiffsMock).toHaveBeenCalledWith(expect.objectContaining({ category: 'refund' }))
  })

  it('点击「处置」调用 resolve 接口', async () => {
    const w = mount(RefundReconcileView)
    await flushPromises()

    await w.find('[data-testid="resolve-21"]').trigger('click')
    await flushPromises()

    expect(resolveDiffMock).toHaveBeenCalledTimes(1)
    expect(resolveDiffMock.mock.calls[0][0]).toBe(21)
  })
})
