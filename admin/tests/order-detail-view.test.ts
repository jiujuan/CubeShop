import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

/**
 * 后台订单详情页（Vitest）
 *
 * 由列表弹窗改造成独立路由页（/orders/:id），额外展示：
 * 订单进度条、收货地址、商品行优惠分摊、金额明细、优惠券、物流轨迹时间线、订单流水。
 *
 * 重点：
 * 1. 金额一律取 amount_details（T-035 唯一口径），老单回退订单级字段；
 * 2. 商品行「实付 = 小计 − 满减分摊 − 券分摊」，与后端落库口径一致；
 * 3. 流水接口失败不阻断详情页（可能缺少 order.log 权限）。
 */
const { getOrderMock, getOrderTimelineMock, getOrderFundsMock } = vi.hoisted(() => ({
  getOrderMock: vi.fn(),
  getOrderTimelineMock: vi.fn(),
  getOrderFundsMock: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  getOrder: getOrderMock,
  getOrderTimeline: getOrderTimelineMock,
  getOrderFunds: getOrderFundsMock,
  ORDER_PROGRESS_FLOW: [
    { status: 'pending_payment', label: '提交订单' },
    { status: 'paid', label: '支付成功' },
    { status: 'pending_ship', label: '待发货' },
    { status: 'shipped', label: '已发货' },
    { status: 'completed', label: '已完成' },
  ],
  ORDER_STATUS_LABELS: {
    pending_payment: '待支付',
    paid: '已支付',
    pending_ship: '待发货',
    shipped: '已发货',
    completed: '已完成',
    cancelled: '已取消',
    refunding: '退款中',
    refunded: '已退款',
  },
  ORDER_STATUS_CLASS: {
    pending_payment: 'bg-orange-100 text-orange-500',
    paid: 'bg-blue-100 text-blue-500',
    pending_ship: 'bg-amber-100 text-amber-600',
    shipped: 'bg-cyan-100 text-cyan-600',
    completed: 'bg-green-100 text-green-600',
    cancelled: 'bg-slate-100 text-slate-500',
    refunding: 'bg-purple-100 text-purple-500',
    refunded: 'bg-red-100 text-red-500',
  },
  TRACE_STATUS_LABELS: {
    pending: '待揽收',
    in_transit: '运输中',
    delivered: '已签收',
    failed: '轨迹异常',
  },
  TRACE_STATUS_CLASS: {
    pending: 'bg-slate-100 text-slate-500',
    in_transit: 'bg-blue-100 text-blue-500',
    delivered: 'bg-green-100 text-green-600',
    failed: 'bg-red-100 text-red-500',
  },
}))

import OrderDetailView from '@/views/order/OrderDetailView.vue'

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return pinia
}

function makeRouter(id: number) {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/orders/:id', component: { template: '<div />' } },
    ],
  })
}

/** 已发货 + 用券 + 有轨迹 + 有流水的完整订单 */
function orderFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: 45,
    order_no: 'CS20260917000009',
    user_id: 7,
    status: 'shipped',
    status_label: '已发货',
    total_amount: '50.00',
    freight_amount: '10.00',
    pay_amount: '50.00',
    discount_amount: '10.00',
    promotion_discount: '0.00',
    remark: '工作日送达',
    created_at: '2026-09-17 10:00:00',
    paid_at: '2026-09-17 10:01:00',
    shipped_at: '2026-09-17 12:00:00',
    completed_at: null,
    cancelled_at: null,
    cancel_reason: null,
    auto_completed: false,
    trace_status: 'in_transit',
    amount_details: {
      v: 1,
      goods_amount: '50.00',
      freight_amount: '10.00',
      promotion_discount: '0.00',
      coupon_discount: '10.00',
      discount_amount: '10.00',
      pay_amount: '50.00',
      promotion_id: null,
      coupon_id: 3,
      user_coupon_id: 11,
      lines: [{ index: 0, product_id: 1, sku_id: 2, amount: '50.00', promotion_share: '0.00', coupon_share: '10.00', payable: '40.00' }],
    },
    coupon: { id: 3, name: '新人立减券', type: 'fixed', amount: '10.00', percent: null, min_spend: '0.00' },
    address_snapshot: {
      contact_name: '张三',
      contact_phone: '13800000000',
      province: '广东省',
      city: '深圳市',
      district: '南山区',
      detail_address: '科技路 1 号',
    },
    items: [
      {
        product_title: '并发C商品',
        sku_specs: { 规格: '标准' },
        price: '50.00',
        quantity: 1,
        total_amount: '50.00',
        coupon_share: '10.00',
        promotion_share: '0.00',
      },
    ],
    shipping: [
      {
        id: 9,
        company_code: 'STO',
        company_name: '申通快递',
        tracking_no: 'ST342343424343',
        trace_status: 'in_transit',
        shipped_at: '2026-09-17 12:00:00',
        delivered_at: null,
        pull_fail_count: 0,
        last_fail_message: null,
        traces: [
          { context: '已到达【深圳中转中心】', occurred_at: '2026-09-17 18:00:00' },
          { context: '【深圳市】快件已揽收', occurred_at: '2026-09-17 12:30:00' },
        ],
      },
    ],
    ...overrides,
  }
}

function timelineFixture() {
  return {
    order_id: 45,
    order_no: 'CS20260917000009',
    list: [
      {
        id: 1,
        order_id: 45,
        from_status: null,
        from_status_label: null,
        to_status: 'pending_payment',
        to_status_label: '待支付',
        operator_type: 'user',
        operator_type_label: '用户',
        remark: '订单创建',
        created_at: '2026-09-17 10:00:00',
      },
      {
        id: 2,
        order_id: 45,
        from_status: 'paid',
        from_status_label: '已支付',
        to_status: 'pending_ship',
        to_status_label: '待发货',
        operator_type: 'system',
        operator_type_label: '系统',
        remark: '支付成功自动受理',
        created_at: '2026-09-17 10:01:00',
      },
    ],
  }
}

/** 资金视图 fixture（G7）：余额支付 + 支付事件 + 余额消费 + 全额退款 */
function fundsFixture() {
  return {
    order: {
      id: 45,
      order_no: 'CS20260917000009',
      status: 'shipped',
      status_label: '已发货',
      user_id: 7,
      total_amount: '50.00',
      freight_amount: '10.00',
      discount_amount: '10.00',
      promotion_discount: '0.00',
      pay_amount: '50.00',
      amount_details: null,
      created_at: '2026-09-17 10:00:00',
      paid_at: '2026-09-17 10:01:00',
    },
    payments: [
      {
        id: 1,
        payment_no: 'PAY20260917100030001',
        channel: 'balance',
        channel_label: '余额支付',
        amount: '50.00',
        status: 'success',
        status_label: '支付成功',
        channel_trade_no: null,
        paid_at: '2026-09-17 10:01:00',
        created_at: '2026-09-17 10:00:30',
      },
    ],
    payment_events: [
      { payment_no: 'PAY20260917100030001', event: 'create', event_label: '创建支付单', created_at: '2026-09-17 10:00:30' },
    ],
    balance_logs: [
      {
        type: 'consume',
        type_label: '消费',
        amount: '-50.00',
        balance_before: '500.00',
        balance_after: '450.00',
        remark: '余额支付 PAY20260917100030001',
        created_at: '2026-09-17 10:00:30',
      },
    ],
    refunds: [
      {
        refund_no: 'RF20260917110000001',
        type: 'refund',
        amount: '50.00',
        status: 'success',
        status_label: '退款成功',
        refund_details: null,
        reason: '不想要了',
        created_at: '2026-09-17 11:00:00',
        processed_at: '2026-09-17 11:05:00',
      },
    ],
    summary: {
      pay_success_amount: '50.00',
      refund_success_amount: '50.00',
      balance_consume_amount: '50.00',
      balance_refund_amount: '0.00',
      net_amount: '0.00',
    },
  }
}

async function mountView(
  options: {
    order?: Record<string, unknown> | null
    fail?: boolean
    id?: number
    timelineFail?: boolean
    funds?: Record<string, unknown> | null
    fundsFail?: boolean
  } = {},
) {
  const id = options.id ?? 45
  if (options.fail) {
    getOrderMock.mockRejectedValue(new Error('404'))
  } else {
    getOrderMock.mockResolvedValue({ data: { data: (options.order ?? orderFixture()) as never } })
  }
  if (options.timelineFail) {
    getOrderTimelineMock.mockRejectedValue(new Error('403'))
  } else {
    getOrderTimelineMock.mockResolvedValue({ data: { data: timelineFixture() } })
  }
  if (options.fundsFail) {
    getOrderFundsMock.mockRejectedValue(new Error('403'))
  } else {
    getOrderFundsMock.mockResolvedValue({ data: { data: (options.funds ?? fundsFixture()) as never } })
  }

  const router = makeRouter(id)
  router.push(`/orders/${id}`)
  await router.isReady()

  const wrapper = mount(OrderDetailView, { global: { plugins: [freshPinia(), router] } })
  await flushPromises()
  await flushPromises()
  return { wrapper, router }
}

describe('后台订单详情页', () => {
  beforeEach(() => {
    getOrderMock.mockReset()
    getOrderTimelineMock.mockReset()
    getOrderFundsMock.mockReset()
  })

  it('按 id 拉取订单与流水两个接口', async () => {
    await mountView({ id: 45 })

    expect(getOrderMock).toHaveBeenCalledTimes(1)
    expect(getOrderMock.mock.calls[0][0]).toBe(45)
    expect(getOrderTimelineMock).toHaveBeenCalledTimes(1)
    expect(getOrderTimelineMock.mock.calls[0][0]).toBe(45)
  })

  it('进度条五步齐全，已发货时前四步高亮、第五步未激活', async () => {
    const { wrapper } = await mountView()
    const card = wrapper.find('[data-testid="progress-card"]')

    const labels = card.findAll('span').map((s) => s.text())
    expect(labels).toEqual(['提交订单', '支付成功', '待发货', '已发货', '已完成'])

    // 高亮节点带主题色背景
    const dots = card.findAll('div.h-7.w-7')
    expect(dots).toHaveLength(5)
    const activeCount = dots.filter((d) => d.classes().includes('bg-[#1677ff]')).length
    expect(activeCount).toBe(4)
  })

  it('已完成订单进度条全部高亮', async () => {
    const { wrapper } = await mountView({ order: orderFixture({ status: 'completed' }) })
    const dots = wrapper.find('[data-testid="progress-card"]').findAll('div.h-7.w-7')

    expect(dots.filter((d) => d.classes().includes('bg-[#1677ff]'))).toHaveLength(5)
  })

  it('取消订单走到第一步即终止并显示终态分支说明', async () => {
    const { wrapper } = await mountView({
      order: orderFixture({ status: 'cancelled', cancel_reason: '用户主动取消' }),
    })

    const tip = wrapper.find('[data-testid="abnormal-end"]')
    expect(tip.exists()).toBe(true)
    expect(tip.text()).toContain('已取消')
    expect(tip.text()).toContain('用户主动取消')

    const dots = wrapper.find('[data-testid="progress-card"]').findAll('div.h-7.w-7')
    expect(dots.filter((d) => d.classes().includes('bg-[#1677ff]'))).toHaveLength(1)
  })

  it('渲染收货地址卡片（联系人 / 电话 / 省市区详细地址）', async () => {
    const { wrapper } = await mountView()
    const card = wrapper.find('[data-testid="address-card"]')

    expect(card.text()).toContain('张三')
    expect(card.text()).toContain('13800000000')
    expect(card.text()).toContain('广东省深圳市南山区科技路 1 号')
  })

  it('商品明细展示满减分摊 / 券分摊 / 实付（实付 = 小计 − 分摊）', async () => {
    const { wrapper } = await mountView()
    const row = wrapper.findAll('tbody tr')[0]
    const cells = row.findAll('td').map((c) => c.text())

    expect(cells[0]).toBe('并发C商品')
    expect(cells[4]).toBe('¥50.00') // 小计
    expect(cells[5]).toBe('-¥0.00') // 满减分摊
    expect(cells[6]).toBe('-¥10.00') // 券分摊
    expect(cells[7]).toBe('¥40.00') // 实付
  })

  it('金额明细优先取 amount_details，逐项与订单一致', async () => {
    const { wrapper } = await mountView()
    const card = wrapper.find('[data-testid="amount-card"]')
    const text = card.text()

    expect(text).toContain('¥50.00') // 商品总额
    expect(text).toContain('-¥0.00') // 满减
    expect(text).toContain('-¥10.00') // 券
    expect(text).toContain('¥10.00') // 运费
    expect(text).toContain('¥50.00') // 实付
  })

  it('无 amount_details 的 V1.0 老单回退订单级字段', async () => {
    const { wrapper } = await mountView({
      order: orderFixture({ amount_details: null, coupon: null, total_amount: '60.00', pay_amount: '70.00' }),
    })

    const text = wrapper.find('[data-testid="amount-card"]').text()
    expect(text).toContain('¥60.00') // 商品总额回退 total_amount
    expect(text).toContain('¥70.00') // 实付回退 pay_amount
    expect(text).toContain('该订单未使用优惠')
  })

  it('优惠券卡片展示券名 / 优惠内容 / 门槛 / 本单抵扣', async () => {
    const { wrapper } = await mountView()
    const card = wrapper.find('[data-testid="coupon-card"]')
    const text = card.text()

    expect(text).toContain('新人立减券')
    expect(text).toContain('立减 ¥10.00')
    expect(text).toContain('无门槛')
    expect(text).toContain('-¥10.00')
  })

  it('无券订单优惠券卡片显示占位文案', async () => {
    const { wrapper } = await mountView({ order: orderFixture({ coupon: null }) })

    expect(wrapper.find('[data-testid="coupon-card"]').text()).toContain('该订单未使用优惠券')
  })

  it('物流卡片展示快递公司 / 运单号 / 状态与轨迹时间线（按返回顺序）', async () => {
    const { wrapper } = await mountView()
    const card = wrapper.find('[data-testid="shipping-card"]')

    expect(card.text()).toContain('申通快递')
    expect(card.text()).toContain('ST342343424343')
    expect(card.text()).toContain('运输中')

    const timeline = wrapper.find('[data-testid="trace-timeline"]')
    const items = timeline.findAll('div.relative')
    expect(items).toHaveLength(2)
    expect(items[0].text()).toContain('已到达【深圳中转中心】')
    expect(items[1].text()).toContain('【深圳市】快件已揽收')
  })

  it('未发货订单物流卡片显示占位文案', async () => {
    const { wrapper } = await mountView({ order: orderFixture({ shipping: [], trace_status: null }) })

    expect(wrapper.find('[data-testid="shipping-card"]').text()).toContain('该订单尚未发货，暂无物流信息')
  })

  it('订单流水按接口返回渲染状态流转与操作人', async () => {
    const { wrapper } = await mountView()
    const card = wrapper.find('[data-testid="logs-card"]')
    const items = card.findAll('div.relative')

    expect(items).toHaveLength(2)
    expect(items[0].text()).toContain('待支付')
    expect(items[0].text()).toContain('用户')
    expect(items[1].text()).toContain('已支付 → 待发货')
    expect(items[1].text()).toContain('系统')
  })

  it('流水接口失败不阻断详情页，仅显示空态', async () => {
    const { wrapper } = await mountView({ timelineFail: true })

    expect(wrapper.find('[data-testid="logs-card"]').text()).toContain('暂无流水记录')
    // 主体内容仍完整渲染
    expect(wrapper.find('[data-testid="progress-card"]').exists()).toBe(true)
  })

  it('订单接口失败显示错误文案且不渲染内容区', async () => {
    const { wrapper } = await mountView({ fail: true })

    expect(wrapper.text()).toContain('订单不存在或无权查看')
    expect(wrapper.find('[data-testid="progress-card"]').exists()).toBe(false)
  })

  it('顶部状态徽标与订单号渲染，返回按钮可点击', async () => {
    const { wrapper } = await mountView()

    expect(wrapper.find('[data-testid="status-badge"]').text()).toBe('已发货')
    expect(wrapper.text()).toContain('CS20260917000009')
    expect(wrapper.find('[data-testid="back"]').exists()).toBe(true)
  })

  it('资金视图渲染汇总（收款/退款/余额/净入账）', async () => {
    const { wrapper } = await mountView()
    const summary = wrapper.find('[data-testid="funds-summary"]')

    expect(summary.find('[data-testid="funds-pay-amount"]').text()).toBe('¥50.00')
    expect(summary.find('[data-testid="funds-refund-amount"]').text()).toBe('¥50.00')
    expect(summary.find('[data-testid="funds-balance-amount"]').text()).toBe('¥50.00')
    expect(summary.find('[data-testid="funds-net-amount"]').text()).toBe('¥0.00')
  })

  it('资金时间线按时间倒序合并支付/余额/退款，事件与流水号随行', async () => {
    const { wrapper } = await mountView()
    const timeline = wrapper.find('[data-testid="funds-timeline"]')
    const items = timeline.findAll('div.relative')

    expect(items).toHaveLength(4)
    // 倒序：退款(11:05) → 支付(10:01) → 支付事件(10:00:30) → 余额(10:00:30，同秒按合并顺序稳定排序)
    expect(items[0].text()).toContain('退款成功')
    expect(items[0].text()).toContain('¥50.00')
    expect(items[0].text()).toContain('RF20260917110000001')
    expect(items[1].text()).toContain('余额支付')
    expect(items[1].text()).toContain('支付成功')
    expect(items[2].text()).toContain('创建支付单')
    expect(items[3].text()).toContain('消费')
    expect(items[3].text()).toContain('500.00 → 450.00')
  })

  it('无资金流水订单显示空态', async () => {
    const { wrapper } = await mountView({
      funds: {
        ...fundsFixture(),
        payments: [],
        payment_events: [],
        balance_logs: [],
        refunds: [],
        summary: {
          pay_success_amount: '0.00',
          refund_success_amount: '0.00',
          balance_consume_amount: '0.00',
          balance_refund_amount: '0.00',
          net_amount: '0.00',
        },
      },
    })

    expect(wrapper.find('[data-testid="funds-timeline"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="funds-card"]').text()).toContain('该订单暂无资金流水')
  })

  it('资金接口失败不阻断详情页，仅显示占位文案', async () => {
    const { wrapper } = await mountView({ fundsFail: true })

    expect(wrapper.find('[data-testid="funds-card"]').text()).toContain('资金流水加载失败')
    // 主体内容仍完整渲染
    expect(wrapper.find('[data-testid="progress-card"]').exists()).toBe(true)
  })
})
