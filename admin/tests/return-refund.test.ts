import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { getRefundsMock, receiveRefundMock, getRefundLogsMock } = vi.hoisted(() => ({
  getRefundsMock: vi.fn(),
  receiveRefundMock: vi.fn(),
  getRefundLogsMock: vi.fn(),
}))

vi.mock('@/api/refund', () => ({
  getRefunds: getRefundsMock,
  receiveRefund: receiveRefundMock,
  getRefundLogs: getRefundLogsMock,
  REFUND_CHANNEL_LABELS: { wechat: '微信支付', alipay: '支付宝', balance: '余额', offline: '线下' },
  REFUND_TYPE_LABELS: { refund: '仅退款', return_refund: '退货退款' },
  RETURN_STATUS_LABELS: { waiting_return: '待退货', shipping: '退货中', received: '已收货', exception: '异常' },
  RETURN_CONDITION_LABELS: { good: '良品', defective: '残次' },
}))

import ReturnRefundView from '@/views/refund/ReturnRefundView.vue'

function sample() {
  return {
    id: 11, refund_no: 'RF00011', order_no: 'NO11', user_id: 1, type: 'return_refund',
    amount: '100.00', reason: null, images: [], status: 'approved', return_status: 'waiting_return',
    return_tracking_no: 'SF123', return_express_company: '顺丰', return_details: [{ sku_id: 5, quantity: 2 }],
    return_received_details: null, return_received_at: null, return_exception_reason: null,
    admin_remark: null, admin_images: [], processed_by: null, processed_by_name: null, processed_at: null,
    channel: 'wechat', out_refund_no: null, channel_refund_no: null, refund_status: null,
    failed_reason: null, retry_count: 0, refunded_at: null, max_retry: 3, created_at: '2026-09-26 10:00:00', order_status: 'refunding',
  }
}

beforeEach(() => {
  getRefundsMock.mockResolvedValue({
    data: { data: { list: [sample()], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
  })
  receiveRefundMock.mockResolvedValue({ data: { data: { ...sample(), return_status: 'received' } } })
  getRefundLogsMock.mockResolvedValue({ data: { data: { logs: [] } } })
})

describe('ReturnRefundView（退货处理页）', () => {
  it('按 type=return_refund 渲染退货单', async () => {
    const w = mount(ReturnRefundView)
    await flushPromises()
    expect(w.find('[data-testid="return-row-11"]').exists()).toBe(true)
  })

  it('点击「确认收货」后提交实收明细（良品/数量）', async () => {
    const w = mount(ReturnRefundView)
    await flushPromises()

    await w.find('[data-testid="receive-11"]').trigger('click')
    await flushPromises()

    const submit = w.find('[data-testid="receive-submit-11"]')
    expect(submit.exists()).toBe(true)

    await submit.trigger('click')
    await flushPromises()

    expect(receiveRefundMock).toHaveBeenCalledTimes(1)
    const payload = receiveRefundMock.mock.calls[0][1]
    expect(payload.received_details).toHaveLength(1)
    expect(payload.received_details[0].sku_id).toBe(5)
    expect(payload.received_details[0].quantity).toBe(2)
    expect(payload.received_details[0].condition).toBe('good')
  })
})
