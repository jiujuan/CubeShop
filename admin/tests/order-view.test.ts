import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * 后台订单管理（Vitest）
 *
 * 重点：paid 状态在运营视角呈现为「待发货」，且 **Tab 行、状态下拉框、状态徽标三者同源**，
 * 避免只改一处导致筛选与展示不一致。
 */
const { getOrdersMock, shipOrderMock, exportOrdersMock } = vi.hoisted(() => ({
  getOrdersMock: vi.fn(),
  shipOrderMock: vi.fn(),
  exportOrdersMock: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  getOrders: getOrdersMock,
  shipOrder: shipOrderMock,
  exportOrders: exportOrdersMock,
  ORDER_STATUS_CLASS: {
    pending_payment: 'bg-orange-100 text-orange-500',
    paid: 'bg-blue-100 text-blue-500',
    shipped: 'bg-cyan-100 text-cyan-600',
    completed: 'bg-green-100 text-green-600',
    cancelled: 'bg-slate-100 text-slate-500',
    refunding: 'bg-purple-100 text-purple-500',
    refunded: 'bg-red-100 text-red-500',
  },
  // 与 src/api/order.ts 的 ORDER_TAB_LABELS 对齐：paid → 待发货
  ORDER_TAB_LABELS: {
    pending_payment: '待支付',
    paid: '待发货',
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
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
}

const paidOrder = {
  id: 1001,
  order_no: 'CS20260916001',
  user_id: 7,
  status: 'paid' as const,
  status_label: '已支付', // 后端原始标签（前端应按运营语义显示为「待发货」）
  total_amount: '60.00',
  freight_amount: '10.00',
  pay_amount: '70.00',
  item_count: 1,
  items: [{ product_title: '示例商品', sku_specs: {}, price: '30.00', quantity: 2, total_amount: '60.00' }],
  created_at: '2026-09-16 10:00:00',
}

function mockOrders() {
  getOrdersMock.mockResolvedValue({
    data: { data: { list: [paidOrder], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
  })
}

async function mountView() {
  mockOrders()
  const router = makeRouter()
  const wrapper = mount(OrderView, {
    global: { plugins: [freshPinia(), router], directives: { permission } },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('后台订单管理页 — 待发货 Tab', () => {
  beforeEach(() => {
    getOrdersMock.mockReset()
    shipOrderMock.mockReset()
    exportOrdersMock.mockReset()
  })

  it('Tab 行含「待发货」且不再显示「已支付」', async () => {
    const { wrapper } = await mountView()
    const tabTexts = wrapper.findAll('button').map((b) => b.text())

    expect(tabTexts).toContain('待发货')
    expect(tabTexts).not.toContain('已支付')
    expect(tabTexts).toContain('全部')
  })

  it('状态下拉框选项与 Tab 同步：paid 显示为「待发货」', async () => {
    const { wrapper } = await mountView()
    const options = wrapper.findAll('select option').map((o) => ({
      value: (o.element as HTMLOptionElement).value,
      text: o.text(),
    }))

    expect(options).toContainEqual({ value: 'paid', text: '待发货' })
    expect(options.some((o) => o.text === '已支付')).toBe(false)
    // 下拉首项为占位「状态」，其余与 Tab（去掉「全部」）一一对应
    expect(options[0]).toEqual({ value: '', text: '状态' })
    expect(options).toHaveLength(8)
  })

  it('点击「待发货」Tab 按 status=paid 拉取第一页', async () => {
    const { wrapper } = await mountView()
    getOrdersMock.mockClear()

    const tab = wrapper.findAll('button').find((b) => b.text() === '待发货')
    expect(tab).toBeTruthy()
    await tab!.trigger('click')
    await flushPromises()

    expect(getOrdersMock).toHaveBeenCalledTimes(1)
    expect(getOrdersMock.mock.calls[0][0]).toMatchObject({ status: 'paid', page: 1 })
  })

  it('paid 订单的状态徽标显示「待发货」而非后端的「已支付」', async () => {
    const { wrapper } = await mountView()
    const cells = wrapper.find('tbody tr').findAll('td')

    expect(cells[4].text()).toBe('待发货')
  })
})
