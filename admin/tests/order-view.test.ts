import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * 后台订单管理（Vitest）
 *
 * 状态模型：待支付 → 已支付 → 待发货 → 已发货 → 已完成（`pending_ship` 为独立状态）
 *
 * 重点：
 * 1. Tab 行 / 状态下拉框 / 状态徽标三者同源（都取 ORDER_STATUS_LABELS），避免筛选与展示不一致；
 * 2. 「已支付」是真实状态，附带「受理备货」兜底操作；
 * 3. 「待发货」（pending_ship）才是发货队列，附带「发货」操作；
 * 4. 支持从仪表盘待办卡带 `?status=` 直达并自动应用筛选。
 */
const { getOrdersMock, shipOrderMock, acceptOrderMock, exportOrdersMock, getEnabledShippingCompaniesMock } = vi.hoisted(() => ({
  getOrdersMock: vi.fn(),
  shipOrderMock: vi.fn(),
  acceptOrderMock: vi.fn(),
  exportOrdersMock: vi.fn(),
  getEnabledShippingCompaniesMock: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  getOrders: getOrdersMock,
  shipOrder: shipOrderMock,
  acceptOrder: acceptOrderMock,
  exportOrders: exportOrdersMock,
  getEnabledShippingCompanies: getEnabledShippingCompaniesMock,
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
  // 与 src/api/order.ts 的 ORDER_STATUS_LABELS 对齐（键顺序 = 履约主链路）
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
}))

import OrderView from '@/views/order/OrderView.vue'

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

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/orders', component: { template: '<div />' } },
      { path: '/orders/:id', component: { template: '<div />' } },
    ],
  })
}

function orderFixture(status: 'paid' | 'pending_ship', label: string, id = 1001) {
  return {
    id,
    order_no: 'CS20260916001',
    user_id: 7,
    status,
    status_label: label,
    total_amount: '60.00',
    freight_amount: '10.00',
    pay_amount: '70.00',
    item_count: 1,
    items: [{ product_title: '示例商品', sku_specs: {}, price: '30.00', quantity: 2, total_amount: '60.00' }],
    created_at: '2026-09-16 10:00:00',
  }
}

function mockOrders(list = [orderFixture('pending_ship', '待发货')]) {
  getOrdersMock.mockResolvedValue({
    data: { data: { list, pagination: { page: 1, page_size: 20, total: list.length, total_pages: 1 } } },
  })
}

async function mountView(options: { path?: string; list?: ReturnType<typeof orderFixture>[] } = {}) {
  mockOrders(options.list)
  const router = makeRouter()
  if (options.path) {
    router.push(options.path)
    await router.isReady()
  }
  const wrapper = mount(OrderView, {
    global: { plugins: [freshPinia(), router], directives: { permission } },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('后台订单管理页 — 订单状态 Tab', () => {
  beforeEach(() => {
    getOrdersMock.mockReset()
    shipOrderMock.mockReset()
    acceptOrderMock.mockReset()
    exportOrdersMock.mockReset()
    getEnabledShippingCompaniesMock.mockReset()
    getEnabledShippingCompaniesMock.mockResolvedValue({ data: { data: [{ code: 'SF', name: '顺丰速运' }, { code: 'ZTO', name: '中通快递' }] } })
  })

  it('Tab 行按履约主链路依次为 待支付/已支付/待发货/已发货/已完成', async () => {
    const { wrapper } = await mountView()
    const tabTexts = wrapper.findAll('button').map((b) => b.text())

    expect(tabTexts).toContain('全部')
    const lifecycle = ['待支付', '已支付', '待发货', '已发货', '已完成']
    const positions = lifecycle.map((t) => tabTexts.indexOf(t))

    expect(positions.every((p) => p > 0)).toBe(true)
    for (let i = 1; i < positions.length; i++) {
      expect(positions[i]).toBeGreaterThan(positions[i - 1])
    }
  })

  it('状态下拉框选项与 Tab 同步，且含「已支付」「待发货」两项', async () => {
    const { wrapper } = await mountView()
    const options = wrapper.findAll('select option').map((o) => ({
      value: (o.element as HTMLOptionElement).value,
      text: o.text(),
    }))

    expect(options[0]).toEqual({ value: '', text: '状态' })
    expect(options).toContainEqual({ value: 'paid', text: '已支付' })
    expect(options).toContainEqual({ value: 'pending_ship', text: '待发货' })
    // 占位 + 8 个状态
    expect(options).toHaveLength(9)
  })

  it('点击「待发货」Tab 按 status=pending_ship 拉取第一页', async () => {
    const { wrapper } = await mountView()
    getOrdersMock.mockClear()

    const tab = wrapper.findAll('button').find((b) => b.text() === '待发货')
    expect(tab).toBeTruthy()
    await tab!.trigger('click')
    await flushPromises()

    expect(getOrdersMock).toHaveBeenCalledTimes(1)
    expect(getOrdersMock.mock.calls[0][0]).toMatchObject({ status: 'pending_ship', page: 1 })
  })

  it('pending_ship 订单徽标显示「待发货」并提供「发货」操作', async () => {
    const { wrapper } = await mountView()
    const cells = wrapper.find('tbody tr').findAll('td')

    expect(cells[4].text()).toBe('待发货')
    expect(cells[7].text()).toContain('发货')
    expect(cells[7].text()).not.toContain('受理备货')
  })

  it('paid 订单徽标显示「已支付」并提供「受理备货」而非直接发货', async () => {
    const { wrapper } = await mountView({ list: [orderFixture('paid', '已支付')] })
    const cells = wrapper.find('tbody tr').findAll('td')

    expect(cells[4].text()).toBe('已支付')
    expect(cells[7].text()).toContain('受理备货')
    expect(cells[7].text()).not.toContain('发货')
  })

  it('后台「受理备货」提交后调用 accept 接口', async () => {
    acceptOrderMock.mockResolvedValue({ data: { data: orderFixture('pending_ship', '待发货') } })
    const { wrapper } = await mountView({ list: [orderFixture('paid', '已支付')] })

    await wrapper.find('[data-testid="accept-1001"]').trigger('click')
    await flushPromises()

    const confirm = wrapper.findAll('button').find((b) => b.text() === '确认受理')
    expect(confirm).toBeTruthy()
    await confirm!.trigger('click')
    await flushPromises()

    expect(acceptOrderMock).toHaveBeenCalledTimes(1)
    expect(acceptOrderMock.mock.calls[0][0]).toBe(1001)
  })

  it('支持从仪表盘待办卡带 ?status=pending_ship 直达并应用筛选', async () => {
    await mountView({ path: '/orders?status=pending_ship' })

    expect(getOrdersMock).toHaveBeenCalledTimes(1)
    expect(getOrdersMock.mock.calls[0][0]).toMatchObject({ status: 'pending_ship' })
  })

  it('非法 query.status 被忽略，不带筛选条件', async () => {
    await mountView({ path: '/orders?status=not_a_status' })

    expect(getOrdersMock).toHaveBeenCalledTimes(1)
    expect(getOrdersMock.mock.calls[0][0].status).toBeUndefined()
  })
})

describe('后台订单管理页 — 详情入口改为路由跳转', () => {
  beforeEach(() => {
    getOrdersMock.mockReset()
    shipOrderMock.mockReset()
    acceptOrderMock.mockReset()
    exportOrdersMock.mockReset()
    getEnabledShippingCompaniesMock.mockReset()
    getEnabledShippingCompaniesMock.mockResolvedValue({ data: { data: [{ code: 'SF', name: '顺丰速运' }] } })
  })

  it('点击「详情」跳转到 /orders/:id 而不是弹出层', async () => {
    const { wrapper, router } = await mountView({ list: [orderFixture('shipped', '已发货', 2048)] })

    await wrapper.find('[data-testid="detail-2048"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/orders/2048')
  })

  it('页面内不再存在详情弹窗节点', async () => {
    const { wrapper } = await mountView({ list: [orderFixture('shipped', '已发货', 2048)] })

    await wrapper.find('[data-testid="detail-2048"]').trigger('click')
    await flushPromises()

    // 弹窗通常带 fixed inset-0 遮罩层，改造后不应出现
    expect(wrapper.find('.fixed.inset-0').exists()).toBe(false)
  })
})
