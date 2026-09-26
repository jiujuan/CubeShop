import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

/**
 * 前台订单详情 — 退款纠纷/申诉入口（#4，Vitest）
 * 覆盖：rejected 退款显示「发起纠纷」、提交调用 openRefundDispute、已有纠纷展示进度且隐藏按钮。
 */
const {
  getOrderMock,
  openDisputeMock,
  getDisputesMock,
} = vi.hoisted(() => ({
  getOrderMock: vi.fn(),
  openDisputeMock: vi.fn(),
  getDisputesMock: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  getOrder: getOrderMock,
  confirmOrder: vi.fn(),
  rebuyOrder: vi.fn(),
  applyRefund: vi.fn(),
  openRefundDispute: openDisputeMock,
  getRefundDisputes: getDisputesMock,
  REFUND_CHANNEL_LABELS: { wechat: '微信支付', alipay: '支付宝', balance: '余额', offline: '线下' },
  REFUND_DISPUTE_REASON_LABELS: {
    refund_rejected: '商家拒绝退款', goods_damaged_dispute: '退货商品争议', timeout_no_process: '超时未处理',
    amount_mismatch: '退款金额争议', not_received_return: '未收到退货/已退未收', other: '其他',
  },
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getProducts: vi.fn(),
  getProduct: vi.fn(),
  getHot: vi.fn(),
}))

vi.mock('@/api/user', () => ({
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  addToCart: vi.fn(),
  getCart: vi.fn(),
}))

import OrderDetailView from '@/views/OrderDetailView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/cart', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/orders', component: { template: '<div />' } },
      { path: '/orders/:id', component: { template: '<div />' } },
      { path: '/orders/:id/pay', component: { template: '<div />' } },
    ],
  })
}

function baseOrder(refunds: unknown[]) {
  return {
    id: 11,
    order_no: 'CS20260920000123',
    status: 'pending_ship',
    status_label: '待发货',
    total_amount: '100.00',
    freight_amount: '0.00',
    pay_amount: '100.00',
    item_count: 1,
    items: [{ product_id: 1, sku_id: 5, product_title: '测试商品', sku_specs: { 规格: '标准' }, sku_image: null, price: '100.00', quantity: 1, total_amount: '100.00' }],
    actions: { can_pay: false, can_cancel: false, can_confirm: false, can_refund: true, can_review: false, can_rebuy: true },
    created_at: '2026-09-20 10:00:00',
    remark: null,
    address_snapshot: null,
    cancel_reason: null,
    logs: [],
    refunds,
  }
}

function rejectedRefund() {
  return {
    id: 'rf1',
    refund_no: 'RF1',
    type: 'refund',
    amount: '100.00',
    reason: null,
    images: [],
    status: 'rejected',
    return_status: null,
    admin_remark: '质检无问题',
    created_at: '2026-09-20 10:00:00',
    channel: null,
    refund_status: null,
    failed_reason: null,
    refunded_at: null,
    retry_count: 0,
  }
}

async function renderDetail(order: Record<string, unknown>, disputes: unknown[] = []) {
  getOrderMock.mockResolvedValue({ data: { data: order } })
  getDisputesMock.mockResolvedValue({ data: { data: disputes } })
  const router = makeRouter()
  router.push('/orders/11')
  await router.isReady()

  const utils = render(OrderDetailView, { global: { plugins: [router] } })
  await waitFor(() => expect(screen.getByText('退款记录')).toBeTruthy())
  return utils
}

describe('订单详情退款纠纷入口（#4）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    const auth = useAuthStore()
    auth.token = 'fake-token'
  })

  it('rejected 退款显示「发起纠纷」按钮，提交调用纠纷接口', async () => {
    openDisputeMock.mockResolvedValue({ data: { data: { id: 'dp1', status: 'opened' } } })
    const w = await renderDetail(baseOrder([rejectedRefund()]))

    const btn = await waitFor(() => screen.getByTestId('open-dispute'))
    await fireEvent.click(btn)
    await waitFor(() => expect(screen.getByTestId('dispute-form')).toBeTruthy())

    await fireEvent.update(screen.getByTestId('dispute-description'), '商品没有质量问题，要求退款')
    await fireEvent.click(screen.getByTestId('dispute-submit'))

    await waitFor(() => expect(openDisputeMock).toHaveBeenCalledTimes(1))
    expect(openDisputeMock.mock.calls[0][0]).toBe('rf1')
    expect(openDisputeMock.mock.calls[0][1]).toEqual({
      reason_code: 'refund_rejected',
      description: '商品没有质量问题，要求退款',
    })
    w.unmount()
  })

  it('已有进行中的纠纷：展示进度文案且不显示发起按钮', async () => {
    const dispute = {
      id: 'dp1',
      refund_id: 'rf1',
      reason_code: 'refund_rejected',
      reason_label: '商家拒绝退款',
      description: null,
      evidence: [],
      status: 'platform_involved',
      status_label: '平台处理中',
      resolution: null,
      resolution_note: null,
      refund_action: null,
      created_at: '2026-09-20 11:00:00',
      resolved_at: null,
    }

    await renderDetail(baseOrder([rejectedRefund()]), [dispute])

    await waitFor(() => expect(screen.getByTestId('refund-dispute-progress')).toBeTruthy())
    expect(screen.getByTestId('refund-dispute-progress').textContent).toContain('平台处理中')
    expect(screen.queryByTestId('open-dispute')).toBeNull()
  })
})
