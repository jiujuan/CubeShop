import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

/**
 * 前台订单详情 — 退款进度（Phase 5，Vitest）
 * 覆盖：processing 进度文案、success 结果提示、failed 失败提示、渠道标签与退款单号展示。
 */
const {
  getOrderMock,
  confirmOrderMock,
  rebuyOrderMock,
} = vi.hoisted(() => ({
  getOrderMock: vi.fn(),
  confirmOrderMock: vi.fn(),
  rebuyOrderMock: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  getOrder: getOrderMock,
  confirmOrder: confirmOrderMock,
  rebuyOrder: rebuyOrderMock,
  applyRefund: vi.fn(),
  REFUND_CHANNEL_LABELS: { wechat: '微信支付', alipay: '支付宝', balance: '余额', offline: '线下' },
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

async function renderDetail(order: Record<string, unknown>) {
  getOrderMock.mockResolvedValue({ data: { data: order } })
  const router = makeRouter()
  router.push('/orders/11')
  await router.isReady()

  const utils = render(OrderDetailView, { global: { plugins: [router] } })
  await waitFor(() => expect(screen.getByText('退款记录')).toBeTruthy())
  return { ...utils, router }
}

describe('订单详情退款进度（Phase 5）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    const auth = useAuthStore()
    auth.token = 'fake-token'
  })

  it('processing 退款展示处理中进度文案', async () => {
    await renderDetail(baseOrder([
      { refund_no: 'RF1', type: 'refund', amount: '100.00', reason: null, images: [], status: 'processing', return_status: null, admin_remark: null, created_at: '2026-09-20 10:00:00', channel: 'wechat', refund_status: 'PROCESSING', failed_reason: null, refunded_at: null, retry_count: 0 },
    ]))

    expect(screen.getByText(/退款处理中，预计 1-3 个工作日原路退回/)).toBeTruthy()
    // 渠道标签
    expect(screen.getByText('微信支付')).toBeTruthy()
  })

  it('success 退款展示原路退回结果提示（含完成时间）', async () => {
    await renderDetail(baseOrder([
      { refund_no: 'RF2', type: 'refund', amount: '100.00', reason: null, images: [], status: 'success', return_status: null, admin_remark: null, created_at: '2026-09-20 10:00:00', channel: 'alipay', refund_status: 'SUCCESS', failed_reason: null, refunded_at: '2026-09-20 12:00:00', retry_count: 1 },
    ]))

    expect(screen.getByText(/退款已原路退回/)).toBeTruthy()
    expect(screen.getByText(/完成时间 2026-09-20 12:00:00/)).toBeTruthy()
  })

  it('failed 退款展示失败提示与失败原因', async () => {
    await renderDetail(baseOrder([
      { refund_no: 'RF3', type: 'refund', amount: '100.00', reason: null, images: [], status: 'failed', return_status: null, admin_remark: null, created_at: '2026-09-20 10:00:00', channel: 'wechat', refund_status: 'FAILED', failed_reason: '渠道拒绝', refunded_at: null, retry_count: 3 },
    ]))

    expect(screen.getByText(/退款失败：渠道拒绝/)).toBeTruthy()
    expect(screen.getByText(/请联系客服处理/)).toBeTruthy()
  })

  it('展示退款单号（我方单号与渠道单号）', async () => {
    await renderDetail(baseOrder([
      { refund_no: 'RF4', type: 'refund', amount: '100.00', reason: null, images: [], status: 'processing', return_status: null, admin_remark: null, created_at: '2026-09-20 10:00:00', channel: 'wechat', out_refund_no: 'OUT999', channel_refund_no: 'CH999', refund_status: 'PROCESSING', failed_reason: null, refunded_at: null, retry_count: 0 },
    ]))

    const text = document.body.textContent ?? ''
    expect(text).toContain('OUT999')
    expect(text).toContain('CH999')
  })
})
