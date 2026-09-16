import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * T-021 报表中心 / 驾驶舱（Vitest）
 * 覆盖：指标卡格式化、趋势区间切换参数、待办跳转、空数据、导出上限提示。
 */
const {
  getReportOverviewMock, getReportTrendMock, getTopProductsMock,
  getCategoryShareMock, getReportUsersMock, exportOrdersMock,
} = vi.hoisted(() => ({
  getReportOverviewMock: vi.fn(),
  getReportTrendMock: vi.fn(),
  getTopProductsMock: vi.fn(),
  getCategoryShareMock: vi.fn(),
  getReportUsersMock: vi.fn(),
  exportOrdersMock: vi.fn(),
}))

vi.mock('@/api/report', () => ({
  getReportOverview: getReportOverviewMock,
  getReportTrend: getReportTrendMock,
  getTopProducts: getTopProductsMock,
  getCategoryShare: getCategoryShareMock,
  getReportUsers: getReportUsersMock,
  exportOrders: exportOrdersMock,
}))

import DashboardIndexView from '@/views/dashboard/IndexView.vue'
import ReportCenterView from '@/views/operation/ReportCenterView.vue'

function freshPinia(permissions: string[] = []) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'admin', nickname: null, avatar: null, phone: null, email: null,
    roles: ['super_admin'], permissions,
  }
  return pinia
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>, router = makeRouter()) => ({
  plugins: [pinia, router],
  directives: { permission },
})

const overviewFixture = {
  today: { orders: 12, paid_orders: 10, sales: '1234.50', aov: '123.45', conversion_rate: 83.3 },
  yesterday: { orders: 8, paid_orders: 7, sales: '1000.00', aov: '142.85', conversion_rate: 87.5 },
  last_7_days: { orders: 80, paid_orders: 70, sales: '8888.00', aov: '126.97', conversion_rate: 87.5 },
  pending: { ship: 3, refund: 1, review: 2, stock_warning: 5 },
  conversion_rate: 83.3,
}

function mockAll() {
  getReportOverviewMock.mockResolvedValue({ data: { data: overviewFixture } })
  getReportTrendMock.mockResolvedValue({
    data: { data: { days: 30, series: [
      { date: '2026-09-01', orders: 5, sales: '100.00' },
      { date: '2026-09-02', orders: 8, sales: '200.00' },
    ] } },
  })
  getTopProductsMock.mockResolvedValue({ data: { data: { list: [
    { product_id: 1, title: '降噪耳机', quantity: 20, amount: '1999.00' },
  ] } } })
  getCategoryShareMock.mockResolvedValue({ data: { data: { total: '1999.00', items: [
    { category_id: 4, category_name: '数码配件', amount: '1999.00', percent: 100 },
  ] } } })
  getReportUsersMock.mockResolvedValue({ data: { data: {
    days: 7, series: [], total_new_users: 6, buyers: 10, repeat_buyers: 3, repurchase_rate: 30,
  } } })
}

describe('T-021 驾驶舱 Dashboard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAll()
    freshPinia()
  })

  it('渲染指标卡并千分位格式化金额', async () => {
    const wrapper = mount(DashboardIndexView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="metric-today_sales"]').text()).toContain('¥1,234.50')
    expect(wrapper.find('[data-testid="metric-today_orders"]').text()).toContain('12')
    expect(wrapper.find('[data-testid="metric-conversion"]').text()).toContain('83.3%')
  })

  it('待办卡点击跳转对应列表（mock router.push）', async () => {
    const router = makeRouter()
    const pushSpy = vi.spyOn(router, 'push')
    const wrapper = mount(DashboardIndexView, { global: globalCfg(freshPinia(), router) })
    await flushPromises()

    await wrapper.find('[data-testid="todo-ship"]').trigger('click')
    expect(pushSpy).toHaveBeenCalledWith('/orders?status=pending_ship')

    await wrapper.find('[data-testid="todo-review"]').trigger('click')
    expect(pushSpy).toHaveBeenCalledWith('/reviews?status=pending')
  })

  it('切换趋势区间携带正确 days 参数', async () => {
    const wrapper = mount(DashboardIndexView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="trend-range-7"]').trigger('click')
    await flushPromises()
    expect(getReportTrendMock).toHaveBeenLastCalledWith(7)

    await wrapper.find('[data-testid="trend-range-90"]').trigger('click')
    await flushPromises()
    expect(getReportTrendMock).toHaveBeenLastCalledWith(90)
  })

  it('图表无数据时展示空态', async () => {
    getTopProductsMock.mockResolvedValue({ data: { data: { list: [] } } })
    getCategoryShareMock.mockResolvedValue({ data: { data: { total: '0', items: [] } } })
    const wrapper = mount(DashboardIndexView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="bar-chart"]').text()).toContain('暂无数据')
    expect(wrapper.find('[data-testid="donut-chart"]').text()).toContain('暂无数据')
  })
})

describe('T-021 报表中心 ReportCenter', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAll()
    freshPinia()
  })

  it('渲染区间栏与导出按钮', async () => {
    const wrapper = mount(ReportCenterView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="range-bar"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="export-btn"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="range-days"]').text()).toContain('上限 90 天')
  })

  it('快捷区间「近 7 日」触发趋势查询 days=7', async () => {
    const wrapper = mount(ReportCenterView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="quick-last7"]').trigger('click')
    await flushPromises()
    expect(getReportTrendMock).toHaveBeenLastCalledWith(7)
  })

  it('导出成功展示行数提示', async () => {
    exportOrdersMock.mockResolvedValue({ data: { data: { rows: [{ 订单号: 'CS1' }], total: 1, truncated: false, limit: 5000 } } })
    const wrapper = mount(ReportCenterView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="export-btn"]').trigger('click')
    await flushPromises()

    expect(exportOrdersMock).toHaveBeenCalled()
    expect(wrapper.find('[data-testid="export-tip"]').text()).toContain('已导出 1 行')
  })

  it('导出截断时提示缩小范围', async () => {
    exportOrdersMock.mockResolvedValue({ data: { data: { rows: [{ 订单号: 'CS1' }], total: 9000, truncated: true, limit: 5000 } } })
    const wrapper = mount(ReportCenterView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="export-btn"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="export-tip"]').text()).toContain('请缩小区间')
  })

  it('复购率与新增用户展示', async () => {
    const wrapper = mount(ReportCenterView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.text()).toContain('30%')
    expect(wrapper.text()).toContain('复购 3 / 买家 10')
  })
})
