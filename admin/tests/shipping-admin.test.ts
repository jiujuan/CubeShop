import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * T-047 物流管理后台前端（Vitest）
 *
 * 覆盖：
 * 1. 发货弹窗（OrderView）：快递公司下拉字典、单号即时校验（8~32 位字母数字）、
 *    粘贴去空白、两步二次确认（下一步 → 摘要 → 确认发货 payload）；
 * 2. 批量发货页（BatchShipView）：成功结果、预校验失败明细展示；
 * 3. 快递公司字典页（ExpressCompanyView）：新增 / 启停 / 删除确认；
 * 4. 物流监控页（ShippingMonitorView）：筛选、异常标记、单条重试；
 * 5. 权限：无 order.ship 时监控页重试按钮不渲染。
 */
const {
  getOrdersMock,
  shipOrderMock,
  getEnabledShippingCompaniesMock,
  getShippingCompaniesMock,
  createShippingCompanyMock,
  updateShippingCompanyMock,
  deleteShippingCompanyMock,
  getShippingsMock,
  pullShippingMock,
  batchShipImportMock,
  downloadBatchShipTemplateMock,
  getShippingChannelMock,
  updateShippingChannelMock,
  getShippingDetailMock,
} = vi.hoisted(() => ({
  getOrdersMock: vi.fn(),
  shipOrderMock: vi.fn(),
  getEnabledShippingCompaniesMock: vi.fn(),
  getShippingCompaniesMock: vi.fn(),
  createShippingCompanyMock: vi.fn(),
  updateShippingCompanyMock: vi.fn(),
  deleteShippingCompanyMock: vi.fn(),
  getShippingsMock: vi.fn(),
  pullShippingMock: vi.fn(),
  batchShipImportMock: vi.fn(),
  downloadBatchShipTemplateMock: vi.fn(),
  getShippingChannelMock: vi.fn(),
  updateShippingChannelMock: vi.fn(),
  getShippingDetailMock: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  getOrders: getOrdersMock,
  shipOrder: shipOrderMock,
  acceptOrder: vi.fn(),
  exportOrders: vi.fn(),
  getEnabledShippingCompanies: getEnabledShippingCompaniesMock,
  getShippingCompanies: getShippingCompaniesMock,
  createShippingCompany: createShippingCompanyMock,
  updateShippingCompany: updateShippingCompanyMock,
  deleteShippingCompany: deleteShippingCompanyMock,
  getShippings: getShippingsMock,
  pullShipping: pullShippingMock,
  batchShipImport: batchShipImportMock,
  downloadBatchShipTemplate: downloadBatchShipTemplateMock,
  getShippingChannel: getShippingChannelMock,
  updateShippingChannel: updateShippingChannelMock,
  getShippingDetail: getShippingDetailMock,
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
    pending: '待查询',
    in_transit: '运输中',
    delivered: '已签收',
    failed: '查询失败',
  },
  TRACE_STATUS_CLASS: {
    pending: 'bg-slate-100 text-slate-500',
    in_transit: 'bg-cyan-100 text-cyan-600',
    delivered: 'bg-green-100 text-green-600',
    failed: 'bg-red-100 text-red-500',
  },
}))

import BatchShipView from '@/views/order/BatchShipView.vue'
import ExpressCompanyView from '@/views/order/ExpressCompanyView.vue'
import OrderView from '@/views/order/OrderView.vue'
import ShippingMonitorView from '@/views/order/ShippingMonitorView.vue'

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
      { path: '/batch-ship', component: { template: '<div />' } },
      { path: '/shipping-monitor', component: { template: '<div />' } },
      { path: '/shipping-companies', component: { template: '<div />' } },
    ],
  })
}

async function mountView(component: unknown, options: { permissions?: string[]; roles?: string[] } = {}) {
  const router = makeRouter()
  router.push('/')
  await router.isReady()
  const wrapper = mount(component as never, {
    global: {
      plugins: [freshPinia(options.permissions, options.roles), router],
      directives: { permission },
    },
  })
  await flushPromises()
  return { wrapper, router }
}

function pendingShipOrder() {
  return {
    id: 1001,
    order_no: 'CS20260917001',
    user_id: 7,
    status: 'pending_ship',
    status_label: '待发货',
    total_amount: '60.00',
    freight_amount: '10.00',
    pay_amount: '70.00',
    item_count: 1,
    items: [{ product_title: '示例商品', sku_specs: {}, price: '30.00', quantity: 2, total_amount: '60.00' }],
    created_at: '2026-09-17 10:00:00',
  }
}

function shippingRow(overrides: Record<string, unknown> = {}) {
  return {
    id: 51,
    order_id: 1001,
    order_no: 'CS20260917001',
    company_code: 'SF',
    company_name: '顺丰速运',
    tracking_no: 'SF0000000001',
    trace_status: 'in_transit',
    trace_count: 3,
    shipped_at: '2026-09-17 10:00:00',
    delivered_at: null,
    pull_fail_count: 0,
    last_fail_message: null,
    abnormal: false,
    ...overrides,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  getOrdersMock.mockResolvedValue({
    data: { data: { list: [pendingShipOrder()], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
  })
  getEnabledShippingCompaniesMock.mockResolvedValue({
    data: { data: [{ code: 'SF', name: '顺丰速运' }, { code: 'ZTO', name: '中通快递' }] },
  })
})

describe('T-047 发货弹窗 — 快递公司下拉与单号校验', () => {
  it('TC-047-F01 打开发货弹窗加载启用字典，空单号时「下一步」禁用', async () => {
    const { wrapper } = await mountView(OrderView)

    await wrapper.find('[data-testid="ship-1001"]').trigger('click')
    await flushPromises()

    const select = wrapper.find('[data-testid="ship-company"]')
    expect(select.exists()).toBe(true)
    const options = select.findAll('option').map((o) => o.text())
    expect(options).toContain('顺丰速运（SF）')
    expect(options).toContain('中通快递（ZTO）')

    const next = wrapper.find('[data-testid="ship-next"]')
    expect(next.attributes('disabled')).toBeDefined()
  })

  it('TC-047-F02 非法单号即时报错，合法单号才能进入二次确认并提交 payload', async () => {
    const { wrapper } = await mountView(OrderView)
    await wrapper.find('[data-testid="ship-1001"]').trigger('click')
    await flushPromises()

    const no = wrapper.find('[data-testid="ship-tracking-no"]')
    // 过短单号：失焦触发校验提示
    ;(no.element as HTMLInputElement).value = 'SF123'
    await no.setValue('SF123')
    await no.trigger('blur')
    await flushPromises()

    expect(wrapper.find('[data-testid="ship-tracking-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="ship-next"]').attributes('disabled')).toBeDefined()

    // 填合法值 + 快递公司 → 进入二次确认
    await no.setValue('SF0000000001')
    await wrapper.get('[data-testid="ship-company"]').setValue('SF')
    await flushPromises()
    expect(wrapper.find('[data-testid="ship-tracking-error"]').exists()).toBe(false)

    await wrapper.find('[data-testid="ship-next"]').trigger('click')
    await flushPromises()

    const summary = wrapper.find('[data-testid="ship-confirm-summary"]')
    expect(summary.exists()).toBe(true)
    expect(summary.text()).toContain('顺丰速运')
    expect(summary.text()).toContain('SF0000000001')

    shipOrderMock.mockResolvedValue({ data: { data: { ...pendingShipOrder(), status: 'shipped' } } })
    await wrapper.find('[data-testid="ship-final"]').trigger('click')
    await flushPromises()

    expect(shipOrderMock).toHaveBeenCalledWith(1001, {
      express_company_code: 'SF',
      tracking_no: 'SF0000000001',
      remark: undefined,
    })
  })

  it('TC-047-F03 单号粘贴自动去除空白字符', async () => {
    const { wrapper } = await mountView(OrderView)
    await wrapper.find('[data-testid="ship-1001"]').trigger('click')
    await flushPromises()

    const no = wrapper.find('[data-testid="ship-tracking-no"]')
    await no.trigger('paste', {
      clipboardData: { getData: () => '  SF000\n00000\t01  ' },
      preventDefault: () => {},
    })
    await flushPromises()

    expect((no.element as HTMLInputElement).value).toBe('SF0000000001')
  })
})

describe('T-047 批量发货页', () => {
  function pickXlsx(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper'], name = 'ship.xlsx') {
    const input = wrapper.find('[data-testid="batch-file-input"]')
    Object.defineProperty(input.element, 'files', { value: [new File(['x'], name)], configurable: true })
    return input.trigger('change')
  }

  it('TC-047-F04 上传成功展示「成功 N / 共 M 单」', async () => {
    batchShipImportMock.mockResolvedValue({
      data: { data: { success: 3, total: 3, failed: [] } },
    })
    const { wrapper } = await mountView(BatchShipView)

    await pickXlsx(wrapper)
    await flushPromises()
    expect(wrapper.find('[data-testid="batch-upload"]').attributes('disabled')).toBeUndefined()

    await wrapper.find('[data-testid="batch-upload"]').trigger('click')
    await flushPromises()

    expect(batchShipImportMock).toHaveBeenCalledTimes(1)
    const success = wrapper.find('[data-testid="batch-success"]')
    expect(success.exists()).toBe(true)
    expect(success.text()).toContain('成功 3 / 共 3 单')
  })

  it('TC-047-F05 预校验失败（40000 携带 data）展示失败明细且不显示成功横幅', async () => {
    batchShipImportMock.mockRejectedValue(
      Object.assign(new Error('校验未全部通过，未执行任何发货'), {
        data: {
          success: 0,
          total: 2,
          // 字段名与后端一致：reason（非 message）
          failed: [
            { row: 1, order_no: 'CS20260917001', reason: '订单号不存在' },
            { row: 2, order_no: '', reason: '快递单号格式错误' },
          ],
        },
      }),
    )
    const { wrapper } = await mountView(BatchShipView)

    await pickXlsx(wrapper)
    await flushPromises()
    await wrapper.find('[data-testid="batch-upload"]').trigger('click')
    await flushPromises()

    const failed = wrapper.find('[data-testid="batch-failed"]')
    expect(failed.exists()).toBe(true)
    expect(failed.text()).toContain('未执行任何发货')
    expect(failed.text()).toContain('订单号不存在')
    expect(failed.text()).toContain('快递单号格式错误')
    expect(wrapper.find('[data-testid="batch-success"]').exists()).toBe(false)
  })

  it('TC-047-F12 识别不一致时展示 warnings 提示且不阻断成功横幅', async () => {
    batchShipImportMock.mockResolvedValue({
      data: {
        data: {
          success: 2,
          total: 2,
          failed: [],
          warnings: [
            {
              row: 2,
              order_no: 'CS20260917001',
              tracking_no: 'YT12345678',
              filled: 'ZTO',
              detected: 'YTO',
              detected_name: '圆通速递',
            },
          ],
        },
      },
    })
    const { wrapper } = await mountView(BatchShipView)

    await pickXlsx(wrapper)
    await flushPromises()
    await wrapper.find('[data-testid="batch-upload"]').trigger('click')
    await flushPromises()

    // 已按填写内容发货：成功横幅仍在
    expect(wrapper.find('[data-testid="batch-success"]').exists()).toBe(true)
    const warnings = wrapper.find('[data-testid="batch-warnings"]')
    expect(warnings.exists()).toBe(true)
    expect(warnings.text()).toContain('圆通速递')
    expect(warnings.text()).toContain('YTO')
    // 明确告知仅供参考
    expect(warnings.text()).toContain('仅供参考')
  })

  it('TC-047-F13 无 warnings 时不展示提示区', async () => {
    batchShipImportMock.mockResolvedValue({
      data: { data: { success: 1, total: 1, failed: [], warnings: [] } },
    })
    const { wrapper } = await mountView(BatchShipView)

    await pickXlsx(wrapper)
    await flushPromises()
    await wrapper.find('[data-testid="batch-upload"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="batch-success"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="batch-warnings"]').exists()).toBe(false)
  })

  it('TC-047-F11 非 xlsx/xls 文件被拒绝', async () => {
    const { wrapper } = await mountView(BatchShipView)
    await pickXlsx(wrapper, 'doc.pdf')
    await flushPromises()

    expect(wrapper.find('[data-testid="batch-upload"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('仅支持 .xlsx / .xls 文件')
  })
})

function companyFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: 1, code: 'SF', name: '顺丰速运', channel_code: 'shunfeng',
    carrier_codes: { kuaidi100: 'shunfeng' }, sort: 10, status: 1, ...overrides,
  }
}

describe('T-047 快递公司字典页', () => {
  it('TC-047-F06 新增弹窗校验必填并提交 create payload', async () => {
    getShippingCompaniesMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 20, total: 0, total_pages: 1 } } },
    })
    createShippingCompanyMock.mockResolvedValue({ data: { data: companyFixture() } })
    const { wrapper } = await mountView(ExpressCompanyView)

    await wrapper.find('[data-testid="company-create"]').trigger('click')
    await flushPromises()

    const save = wrapper.find('[data-testid="company-form-save"]')
    expect(save.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="company-form-code"]').setValue('JD')
    await wrapper.get('[data-testid="company-form-name"]').setValue('京东物流')
    await wrapper.get('[data-testid="company-form-carrier-kuaidi100"]').setValue('jd')
    await flushPromises()
    expect(save.attributes('disabled')).toBeUndefined()

    await save.trigger('click')
    await flushPromises()

    // channel_code 为历史兼容列，随 carrier_codes.kuaidi100 同步写入，避免两处不一致
    expect(createShippingCompanyMock).toHaveBeenCalledWith({
      code: 'JD',
      name: '京东物流',
      carrier_codes: { kuaidi100: 'jd' },
      channel_code: 'jd',
      sort: 0,
      status: 1,
    })
  })

  it('TC-047-F07 启停切换只传 status，删除需二次确认', async () => {
    getShippingCompaniesMock.mockResolvedValue({
      data: { data: { list: [companyFixture()], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
    })
    updateShippingCompanyMock.mockResolvedValue({ data: { data: companyFixture({ status: 0 }) } })
    deleteShippingCompanyMock.mockResolvedValue({ data: { data: null } })
    const { wrapper } = await mountView(ExpressCompanyView)

    await wrapper.find('[data-testid="company-toggle-SF"]').trigger('click')
    await flushPromises()
    expect(updateShippingCompanyMock).toHaveBeenCalledWith(1, { status: 0 })

    await wrapper.find('[data-testid="company-delete-SF"]').trigger('click')
    await flushPromises()
    expect(deleteShippingCompanyMock).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="company-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(deleteShippingCompanyMock).toHaveBeenCalledWith(1)
  })
})

describe('T-047 物流监控页', () => {
  it('TC-047-F08 筛选查询失败 → 请求带 trace_status；异常行显示标记与失败原因', async () => {
    getShippingsMock.mockResolvedValue({
      data: {
        data: {
          list: [
            shippingRow({
              trace_status: 'failed',
              abnormal: true,
              pull_fail_count: 3,
              last_fail_message: '查询渠道超时',
            }),
          ],
          pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
        },
      },
    })
    const { wrapper } = await mountView(ShippingMonitorView)

    await wrapper.get('[data-testid="monitor-status-filter"]').setValue('failed')
    await wrapper.findAll('button').find((b) => b.text() === '搜索')!.trigger('click')
    await flushPromises()

    expect(getShippingsMock).toHaveBeenLastCalledWith(expect.objectContaining({ trace_status: 'failed' }))

    const badge = wrapper.find('[data-testid="monitor-abnormal-51"]')
    expect(badge.exists()).toBe(true)
    expect(badge.text()).toBe('异常')
    expect(badge.attributes('title')).toContain('查询渠道超时')
  })

  it('TC-047-F09 单条重试调用 pull 接口并刷新列表', async () => {
    getShippingsMock.mockResolvedValue({
      data: {
        data: {
          list: [shippingRow()],
          pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
        },
      },
    })
    pullShippingMock.mockResolvedValue({
      data: { data: { result: 'pulled', trace_status: 'in_transit', pull_fail_count: 0, trace_count: 3 }, message: '拉取成功' },
    })
    const { wrapper } = await mountView(ShippingMonitorView)

    await wrapper.find('[data-testid="monitor-pull-51"]').trigger('click')
    await flushPromises()

    expect(pullShippingMock).toHaveBeenCalledWith(51)
    expect(wrapper.find('[data-testid="monitor-retry-msg"]').text()).toBe('拉取成功')
  })

  it('TC-047-F10 无 order.ship 权限时重试按钮不渲染', async () => {
    getShippingsMock.mockResolvedValue({
      data: {
        data: {
          list: [shippingRow()],
          pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
        },
      },
    })
    const { wrapper } = await mountView(ShippingMonitorView, { permissions: [], roles: ['operator'] })

    expect(wrapper.find('[data-testid="monitor-pull-51"]').exists()).toBe(false)
  })

  /** 渠道信息 mock：默认「快递100 + 后台配置 + 密钥已配置」 */
  function channelInfo(overrides: Record<string, unknown> = {}) {
    return {
      configured: 'kuaidi100',
      channel: 'kuaidi100',
      label: '快递100',
      source: 'database',
      available: true,
      key_configured: true,
      customer_configured: true,
      options: [
        { value: '', label: '跟随环境配置（.env）' },
        { value: 'kuaidi100', label: '快递100' },
        { value: 'mock', label: '本地演示（Mock，不发真实请求）' },
        { value: 'off', label: '关闭轨迹查询' },
      ],
      ...overrides,
    }
  }

  function monitorList() {
    getShippingsMock.mockResolvedValue({
      data: {
        data: {
          list: [shippingRow()],
          pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
        },
      },
    })
  }

  it('TC-047-F14 展示当前渠道与密钥状态（不回显密钥明文）', async () => {
    getShippingChannelMock.mockResolvedValue({ data: { data: channelInfo() } })
    monitorList()
    const { wrapper } = await mountView(ShippingMonitorView)

    expect(wrapper.find('[data-testid="channel-label"]').text()).toBe('快递100')
    expect(wrapper.find('[data-testid="channel-available"]').text()).toBe('可查询')
    expect(wrapper.find('[data-testid="channel-source"]').text()).toBe('后台配置')
    expect(wrapper.find('[data-testid="channel-key"]').text()).toBe('已配置')
    // 密钥只在 .env 维护，页面应提示而非提供输入框
    expect(wrapper.find('[data-testid="channel-card"]').text()).toContain('SHIPPING_CHANNEL_KEY')
  })

  it('TC-047-F15 切换渠道调用接口并重新读取', async () => {
    getShippingChannelMock.mockResolvedValue({ data: { data: channelInfo({ configured: '', source: 'env' }) } })
    updateShippingChannelMock.mockResolvedValue({
      data: { data: { configured: 'mock', channel: 'mock' }, message: '物流渠道已切换' },
    })
    monitorList()
    const { wrapper } = await mountView(ShippingMonitorView)

    expect(wrapper.find('[data-testid="channel-source"]').text()).toBe('环境配置（.env）')

    await wrapper.find('[data-testid="channel-switch"]').setValue('mock')
    await flushPromises()

    expect(updateShippingChannelMock).toHaveBeenCalledWith('mock')
    // 切换后重新拉取渠道信息（初始 1 次 + 刷新 1 次）
    expect(getShippingChannelMock).toHaveBeenCalledTimes(2)
  })

  it('TC-047-F16 运单轨迹详情与用户端同口径（时间线倒序）', async () => {
    getShippingChannelMock.mockResolvedValue({ data: { data: channelInfo() } })
    getShippingDetailMock.mockResolvedValue({
      data: {
        data: {
          id: 51,
          order_id: 1001,
          order_no: 'CS20260917001',
          company_code: 'SF',
          company_name: '顺丰速运',
          tracking_no: 'SF12345678',
          phone: '13800000000',
          trace_status: 'in_transit',
          shipped_at: '2026-09-01 10:00:00',
          delivered_at: null,
          pull_fail_count: 0,
          last_fail_message: null,
          has_trace: true,
          traces: [
            { context: '快件已到达中转中心', occurred_at: '2026-09-02 10:00:00' },
            { context: '快件已揽收', occurred_at: '2026-09-01 09:00:00' },
          ],
        },
      },
    })
    monitorList()
    const { wrapper } = await mountView(ShippingMonitorView)

    await wrapper.find('[data-testid="monitor-detail-51"]').trigger('click')
    await flushPromises()

    const list = wrapper.find('[data-testid="detail-trace-list"]')
    expect(list.exists()).toBe(true)
    expect(list.findAll('li')).toHaveLength(2)
    // 最新在顶
    expect(list.findAll('li')[0].text()).toContain('快件已到达中转中心')
    expect(wrapper.find('[data-testid="detail-status"]').text()).toBe('运输中')

    // 关闭弹层
    await wrapper.find('[data-testid="detail-close"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="detail-mask"]').exists()).toBe(false)
  })

  it('TC-047-F17 无轨迹时详情弹层展示空态而非时间线', async () => {
    getShippingChannelMock.mockResolvedValue({ data: { data: channelInfo() } })
    getShippingDetailMock.mockResolvedValue({
      data: {
        data: {
          id: 51,
          order_id: 1001,
          order_no: 'CS20260917001',
          company_code: 'SF',
          company_name: '顺丰速运',
          tracking_no: 'SF12345678',
          phone: null,
          trace_status: 'pending',
          shipped_at: '2026-09-01 10:00:00',
          delivered_at: null,
          pull_fail_count: 1,
          last_fail_message: '查询渠道超时',
          has_trace: false,
          traces: [],
        },
      },
    })
    monitorList()
    const { wrapper } = await mountView(ShippingMonitorView)

    await wrapper.find('[data-testid="monitor-detail-51"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="detail-trace-list"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="detail-trace-empty"]').text()).toContain('暂无轨迹')
    expect(wrapper.find('[data-testid="detail-fail"]').text()).toContain('查询渠道超时')
  })
})
