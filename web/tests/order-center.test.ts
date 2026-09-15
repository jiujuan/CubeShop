import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getOrdersMock,
  getOrderMock,
  confirmOrderMock,
  cancelOrderMock,
  rebuyOrderMock,
  ORDER_TABS_STUB,
} = vi.hoisted(() => ({
  getOrdersMock: vi.fn(),
  getOrderMock: vi.fn(),
  confirmOrderMock: vi.fn(),
  cancelOrderMock: vi.fn(),
  rebuyOrderMock: vi.fn(),
  /** ORDER_TABS 是组件内使用的运行时值，mock 必须提供 */
  ORDER_TABS_STUB: [
    { value: 'all', label: '全部', empty: '暂无订单' },
    { value: 'pending_payment', label: '待付款', empty: '暂无待付款订单' },
    { value: 'pending_ship', label: '待发货', empty: '暂无待发货订单' },
    { value: 'pending_receive', label: '待收货', empty: '暂无待收货订单' },
    { value: 'pending_review', label: '待评价', empty: '暂无待评价订单' },
    { value: 'after_sale', label: '退款售后', empty: '暂无售后订单' },
  ],
}))

vi.mock('@/api/order', () => ({
  getOrders: getOrdersMock,
  getOrder: getOrderMock,
  confirmOrder: confirmOrderMock,
  cancelOrder: cancelOrderMock,
  rebuyOrder: rebuyOrderMock,
  applyRefund: vi.fn(),
  ORDER_TABS: ORDER_TABS_STUB,
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
import OrderListView from '@/views/OrderListView.vue'
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

function baseOrder(overrides: Record<string, unknown> = {}) {
  return {
    id: 11,
    order_no: 'CS20260915000123',
    status: 'shipped',
    status_label: '已发货',
    total_amount: '100.00',
    freight_amount: '0.00',
    pay_amount: '100.00',
    item_count: 2,
    items_preview: [{ product_id: 1, product_title: '测试商品', sku_image: null, quantity: 2 }],
    items: [
      {
        product_id: 1,
        sku_id: 5,
        product_title: '测试商品',
        sku_specs: { 规格: '标准' },
        sku_image: null,
        price: '50.00',
        quantity: 2,
        total_amount: '100.00',
      },
    ],
    actions: {
      can_pay: false,
      can_cancel: false,
      can_confirm: true,
      can_refund: true,
      can_review: false,
      can_rebuy: true,
    },
    created_at: '2026-09-15 10:00:00',
    remark: null,
    address_snapshot: null,
    cancel_reason: null,
    refunds: [],
    ...overrides,
  }
}

function logsFor(status: string) {
  const base = [
    {
      from_status: null,
      to_status: 'pending_payment',
      to_status_label: '待支付',
      operator_type: 'user',
      operator_id: 1,
      remark: '创建订单',
      created_at: '2026-09-15 10:00:00',
    },
    {
      from_status: 'pending_payment',
      to_status: 'paid',
      to_status_label: '已支付',
      operator_type: 'system',
      operator_id: null,
      remark: '支付成功',
      created_at: '2026-09-15 10:05:00',
    },
  ]
  if (status === 'shipped' || status === 'completed') {
    base.push({
      from_status: 'paid',
      to_status: 'shipped',
      to_status_label: '已发货',
      operator_type: 'admin',
      operator_id: 1,
      remark: '商家已发货',
      created_at: '2026-09-15 11:00:00',
    } as never)
  }
  if (status === 'completed') {
    base.push({
      from_status: 'shipped',
      to_status: 'completed',
      to_status_label: '已完成',
      operator_type: 'user',
      operator_id: 1,
      remark: '用户确认收货',
      created_at: '2026-09-15 12:00:00',
    } as never)
  }
  if (status === 'cancelled') {
    base.push({
      from_status: 'pending_payment',
      to_status: 'cancelled',
      to_status_label: '已取消',
      operator_type: 'user',
      operator_id: 1,
      remark: '用户主动取消',
      created_at: '2026-09-15 10:30:00',
    } as never)
  }
  return base
}

async function renderDetail(order: Record<string, unknown>) {
  getOrderMock.mockResolvedValue({ data: { data: order } })
  const router = makeRouter()
  router.push('/orders/11')
  await router.isReady()

  const utils = render(OrderDetailView, { global: { plugins: [router] } })
  await waitFor(() => expect(screen.getByText('订单进度')).toBeTruthy())
  return { ...utils, router }
}

describe('订单详情时间轴与确认收货（T-005）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    const auth = useAuthStore()
    auth.token = 'fake-token'
    vi.stubGlobal('alert', vi.fn())
    vi.stubGlobal('confirm', vi.fn(() => true))
  })

  it('已发货订单渲染时间轴节点并显示确认收货按钮', async () => {
    await renderDetail({
      ...baseOrder(),
      logs: logsFor('shipped'),
    })

    // 主链路 4 个节点
    expect(document.querySelector('[data-testid="timeline-node-pending_payment"]')).toBeTruthy()
    expect(document.querySelector('[data-testid="timeline-node-shipped"]')).toBeTruthy()

    // 未到达的「交易完成」节点置灰
    const completedNode = document.querySelector('[data-testid="timeline-node-completed"]')
    expect(completedNode?.getAttribute('data-reached')).toBe('0')

    expect(screen.getByTestId('confirm-receipt')).toBeTruthy()
  })

  it('未发货（已支付）订单不显示确认收货按钮', async () => {
    await renderDetail({
      ...baseOrder({
        status: 'paid',
        status_label: '已支付',
        actions: { ...baseOrder().actions, can_confirm: false },
      }),
      logs: logsFor('paid'),
    })

    expect(screen.queryByTestId('confirm-receipt')).toBeNull()
  })

  it('点击确认收货弹出二次确认，确认后调用接口并刷新', async () => {
    confirmOrderMock.mockResolvedValue({ data: { code: 0 } })

    await renderDetail({
      ...baseOrder(),
      logs: logsFor('shipped'),
    })

    await fireEvent.click(screen.getByTestId('confirm-receipt'))
    await waitFor(() => expect(screen.getByTestId('confirm-dialog')).toBeTruthy())

    // 二次确认文案必须提示不可撤销
    expect(screen.getByText(/不可撤销/)).toBeTruthy()

    // 第二次 getOrder 返回已完成
    getOrderMock.mockResolvedValue({
      data: { data: { ...baseOrder({ status: 'completed', status_label: '已完成' }), logs: logsFor('completed') } },
    })

    await fireEvent.click(screen.getByTestId('confirm-dialog-ok'))

    await waitFor(() => expect(confirmOrderMock).toHaveBeenCalledWith(11))
    await waitFor(() => expect(getOrderMock).toHaveBeenCalledTimes(2))
  })

  it('确认收货失败展示错误提示且状态不变', async () => {
    confirmOrderMock.mockRejectedValue(new Error('订单当前状态「待支付」不允许变更为「已完成」'))

    await renderDetail({
      ...baseOrder(),
      logs: logsFor('shipped'),
    })

    await fireEvent.click(screen.getByTestId('confirm-receipt'))
    await fireEvent.click(screen.getByTestId('confirm-dialog-ok'))

    await waitFor(() => expect(screen.getByText(/不允许变更/)).toBeTruthy())
  })

  it('已取消订单渲染异常分支告警', async () => {
    await renderDetail({
      ...baseOrder({
        status: 'cancelled',
        status_label: '已取消',
        actions: { ...baseOrder().actions, can_confirm: false, can_refund: false, can_rebuy: true },
      }),
      logs: logsFor('cancelled'),
    })

    expect(screen.getByTestId('timeline-abnormal')).toBeTruthy()
    expect(screen.getByText('订单已取消')).toBeTruthy()
  })
})

describe('订单列表分组、检索与再次购买（T-006）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    const auth = useAuthStore()
    auth.token = 'fake-token'
    vi.stubGlobal('alert', vi.fn())
    vi.stubGlobal('confirm', vi.fn(() => true))

    getOrdersMock.mockResolvedValue({
      data: {
        data: {
          list: [{ ...baseOrder(), logs: undefined }],
          pagination: { page: 1, page_size: 10, total: 1, total_pages: 1 },
        },
      },
    })
  })

  async function renderList() {
    const router = makeRouter()
    router.push('/orders')
    await router.isReady()
    const utils = render(OrderListView, { global: { plugins: [router] } })
    await waitFor(() => expect(screen.getByTestId('order-tabs')).toBeTruthy())
    return { ...utils, router }
  }

  it('切换 Tab 时按 tab 参数请求（而非 status）', async () => {
    await renderList()

    await fireEvent.click(screen.getByTestId('order-tab-pending_receive'))

    await waitFor(() =>
      expect(getOrdersMock).toHaveBeenLastCalledWith(
        expect.objectContaining({ tab: 'pending_receive', page: 1 }),
      ),
    )
  })

  it('关键词与时间区间组装为请求参数', async () => {
    await renderList()

    await fireEvent.update(screen.getByTestId('order-keyword') as HTMLInputElement, 'CS2026')
    await fireEvent.update(screen.getByTestId('order-start') as HTMLInputElement, '2026-09-01')
    await fireEvent.update(screen.getByTestId('order-end') as HTMLInputElement, '2026-09-30')
    await fireEvent.click(screen.getByTestId('order-search-btn'))

    await waitFor(() =>
      expect(getOrdersMock).toHaveBeenLastCalledWith(
        expect.objectContaining({
          keyword: 'CS2026',
          start: '2026-09-01',
          end: '2026-09-30',
          page: 1,
        }),
      ),
    )
  })

  it('空态文案按 Tab 区分', async () => {
    await renderList()

    getOrdersMock.mockResolvedValue({
      data: {
        data: { list: [], pagination: { page: 1, page_size: 10, total: 0, total_pages: 1 } },
      },
    })

    await fireEvent.click(screen.getByTestId('order-tab-pending_receive'))

    await waitFor(() => expect(screen.getByText('暂无待收货订单')).toBeTruthy())
  })

  it('待付款订单展示倒计时', async () => {
    // 用本地时间拼接，避免 toISOString() 的 UTC 偏移导致倒计时被判定为已超时
    const d = new Date(Date.now() - 5 * 60 * 1000)
    const local = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`

    getOrdersMock.mockResolvedValue({
      data: {
        data: {
          list: [
            {
              ...baseOrder({
                status: 'pending_payment',
                status_label: '待支付',
                created_at: local,
                actions: { ...baseOrder().actions, can_pay: true, can_cancel: true, can_confirm: false },
              }),
            },
          ],
          pagination: { page: 1, page_size: 10, total: 1, total_pages: 1 },
        },
      },
    })

    await renderList()

    expect(screen.getByTestId('order-countdown').textContent).toMatch(/剩余 \d+:\d{2}/)
  })

  it('再次购买成功跳转购物车', async () => {
    rebuyOrderMock.mockResolvedValue({ data: { data: { added: 2, skipped: [], cart_count: 2 } } })

    const { router } = await renderList()
    const push = vi.spyOn(router, 'push')

    await fireEvent.click(screen.getByTestId('order-rebuy-11'))

    await waitFor(() => expect(rebuyOrderMock).toHaveBeenCalledWith(11))
    await waitFor(() => expect(push).toHaveBeenCalledWith('/cart'))
  })

  it('再次购买存在失效行时弹窗提示明细', async () => {
    rebuyOrderMock.mockResolvedValue({
      data: {
        data: {
          added: 0,
          skipped: [{ product_id: 9, title: '已下架商品', reason: '商品已下架' }],
          cart_count: 0,
        },
      },
    })

    await renderList()

    await fireEvent.click(screen.getByTestId('order-rebuy-11'))

    await waitFor(() => expect(alert).toHaveBeenCalled())
    expect((alert as unknown as ReturnType<typeof vi.fn>).mock.calls[0][0]).toContain('商品已下架')
  })

  it('已发货订单可下单确认收货（走二次确认而非直接调用）', async () => {
    await renderList()

    await fireEvent.click(screen.getByTestId('order-confirm-11'))

    expect(screen.getByTestId('confirm-dialog')).toBeTruthy()
    expect(confirmOrderMock).not.toHaveBeenCalled()
  })
})
