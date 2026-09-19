import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * 后台履约中心（Vitest，WMS 计划 P6）
 *
 * 覆盖：发货单列表筛选 / 失败行错误摘要与重推 / 详情抽屉行项目与流水 /
 * 退货单详情的残次标记与差异提示 / 日志页脱敏 / 权限不足按钮不渲染 /
 * 接口异常的错误态 / 空态。
 */
const {
  getFulfillmentOrdersMock,
  getFulfillmentOrderMock,
  pushFulfillmentOrderMock,
  cancelFulfillmentOrderMock,
  batchPushFulfillmentOrdersMock,
  getReturnInboundOrdersMock,
  getReturnInboundOrderMock,
  getWmsLogsMock,
  getWmsLogMock,
  getWmsWarehouseOptionsMock,
} = vi.hoisted(() => ({
  getFulfillmentOrdersMock: vi.fn(),
  getFulfillmentOrderMock: vi.fn(),
  pushFulfillmentOrderMock: vi.fn(),
  cancelFulfillmentOrderMock: vi.fn(),
  batchPushFulfillmentOrdersMock: vi.fn(),
  getReturnInboundOrdersMock: vi.fn(),
  getReturnInboundOrderMock: vi.fn(),
  getWmsLogsMock: vi.fn(),
  getWmsLogMock: vi.fn(),
  getWmsWarehouseOptionsMock: vi.fn(),
}))

vi.mock('@/api/wms', () => ({
  getFulfillmentOrders: getFulfillmentOrdersMock,
  getFulfillmentOrder: getFulfillmentOrderMock,
  pushFulfillmentOrder: pushFulfillmentOrderMock,
  cancelFulfillmentOrder: cancelFulfillmentOrderMock,
  batchPushFulfillmentOrders: batchPushFulfillmentOrdersMock,
  getReturnInboundOrders: getReturnInboundOrdersMock,
  getReturnInboundOrder: getReturnInboundOrderMock,
  getWmsLogs: getWmsLogsMock,
  getWmsLog: getWmsLogMock,
  getWmsWarehouseOptions: getWmsWarehouseOptionsMock,
  FULFILLMENT_STATUS_LABELS: {
    created: '已创建', pending_push: '待推送', pushing: '推送中', pushed: '已推送',
    picking: '拣货中', packed: '已打包', shipped: '已发货', completed: '已完成',
    cancelled: '已取消', exception: '异常', push_failed: '推送失败',
  },
  FULFILLMENT_STATUS_CLASS: {
    push_failed: 'bg-red-50 text-red-600', pushed: 'bg-cyan-50 text-cyan-600',
    created: '', pending_push: '', pushing: '', picking: '', packed: '',
    shipped: '', completed: '', cancelled: '', exception: '',
  },
  RETURN_INBOUND_STATUS_LABELS: {
    created: '已创建', pending_push: '待推送', pushing: '推送中', pushed: '已推送',
    receiving: '收货中', received: '已收货', completed: '已完成', cancelled: '已取消',
    exception: '异常', push_failed: '推送失败',
  },
  RETURN_INBOUND_STATUS_CLASS: {
    pushed: 'bg-cyan-50 text-cyan-600', completed: 'bg-emerald-100 text-emerald-700',
    created: '', pending_push: '', pushing: '', receiving: '', received: '',
    cancelled: '', exception: '', push_failed: '',
  },
  INVENTORY_TYPE_LABELS: { ZP: '正品', CC: '残次' },
  WMS_PROVIDER_LABELS: { cainiao: '菜鸟（奇门）', jd_cloud: '京东云仓' },
}))

/** 统一分页壳 */
const pager = { page: 1, page_size: 15, total: 1, total_pages: 1 }

const failedRow = {
  id: 11, order_id: 1, order_no: 'NO20260101001', outbound_no: 'FO20260101001',
  warehouse_id: 1, warehouse_name: '主仓', provider: 'cainiao',
  status: 'push_failed', status_label: '推送失败',
  wms_outbound_no: null, tracking_no: null, carrier_code: null, carrier_name: null,
  push_request_id: 'REQ-1', push_times: 2, last_push_at: '2026-09-20 01:00:00',
  last_push_error: 'S03 参数非法：仓库编码不存在',
  shipped_at: null, cancelled_at: null, exception_reason: null,
  can_push: true, can_cancel: true,
  created_at: '2026-09-20 00:00:00', updated_at: '2026-09-20 01:00:00',
}

const detailRow = {
  ...failedRow,
  items: [
    { id: 1, sku_id: 1, platform_sku_code: 'SKU-1', wms_sku_code: 'SKU-1', product_name: '魔方 A', qty: 2, shipped_qty: 0, barcode: null },
  ],
  logs: [
    { id: 9, direction: 'outbound', direction_label: '出站', api_name: 'deliveryorder.create', request_id: 'REQ-1', success: false, error_msg: '参数非法', http_status: 200, created_at: '2026-09-20 01:00:00' },
  ],
}

const rioRow = {
  id: 21, refund_id: 5, refund_no: 'RF20260101001', order_id: 1, order_no: 'NO20260101001',
  inbound_no: 'RI20260101001', warehouse_id: 1, warehouse_name: '主仓', provider: 'cainiao',
  status: 'push_failed', status_label: '推送失败',
  wms_inbound_no: null, push_request_id: 'REQ-2', push_times: 1,
  last_push_at: '2026-09-20 01:00:00', last_push_error: null,
  received_at: null, cancelled_at: null, exception_reason: '缺少 SKU 映射',
  return_reason: '七天无理由',
  can_push: true, can_cancel: true, can_manual_received: false,
  created_at: '2026-09-20 00:00:00', updated_at: '2026-09-20 01:00:00',
}

const rioDetail = {
  ...rioRow,
  items: [
    { id: 3, sku_id: 1, platform_sku_code: 'SKU-1', wms_sku_code: 'SKU-1', product_name: '魔方 A', qty: 2, received_qty: 3, inventory_type: 'CC', barcode: null },
  ],
  logs: [
    { id: 8, direction: 'outbound', direction_label: '出站', api_name: 'returnorder.create', request_id: 'REQ-2', success: false, error_msg: '缺映射', http_status: 200, created_at: '2026-09-20 01:00:00' },
  ],
}

const logRow = {
  id: 31, direction: 'outbound', direction_label: '出站', provider: 'cainiao',
  api_name: 'deliveryorder.create', request_id: 'REQ-ABC-123', biz_no: 'FO20260101001',
  http_status: 200, success: false, error_msg: '参数非法', created_at: '2026-09-20 01:00:00',
}

function setupAuth(roles: string[], permissions: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'u', nickname: null, avatar: null, phone: null, email: null,
    roles, permissions,
  }
  return pinia
}

async function mountPage(component: unknown, permissions: string[]) {
  const pinia = setupAuth(['operator'], permissions)
  const wrapper = mount(component as never, {
    attachTo: document.body,
    global: { plugins: [pinia], directives: { permission } },
  })
  await flushPromises()
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getWmsWarehouseOptionsMock.mockResolvedValue({ data: { code: 0, data: [{ id: 1, name: '主仓' }] } })
})

describe('发货单列表', () => {
  it('列表按状态筛选时请求参数正确', async () => {
    getFulfillmentOrdersMock.mockResolvedValue({ data: { code: 0, data: { list: [], pagination: pager } } })
    const wrapper = await mountPage(
      (await import('@/views/wms/FulfillmentOrderListView.vue')).default,
      ['wms.order.view', 'wms.order.manage'],
    )

    const select = wrapper.find('[data-testid="filter-status"]')
    await select.setValue('push_failed')
    await wrapper.find('[data-testid="search"]').trigger('click')
    await flushPromises()

    expect(getFulfillmentOrdersMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ status: 'push_failed', page: 1, page_size: 15 }),
    )
  })

  it('PushFailed 行显示错误摘要与重推按钮，点击后调用 pushFulfillmentOrder', async () => {
    getFulfillmentOrdersMock.mockResolvedValue({ data: { code: 0, data: { list: [failedRow], pagination: pager } } })
    pushFulfillmentOrderMock.mockResolvedValue({ data: { code: 0, data: failedRow } })

    const wrapper = await mountPage(
      (await import('@/views/wms/FulfillmentOrderListView.vue')).default,
      ['wms.order.view', 'wms.order.manage'],
    )

    expect(wrapper.find('[data-testid="error-11"]').text()).toContain('S03 参数非法')
    await wrapper.find('[data-testid="push-11"]').trigger('click')
    expect(pushFulfillmentOrderMock).toHaveBeenCalledWith(11)
    await flushPromises()
    await flushPromises()
    expect(wrapper.text()).toContain('推送失败')
  })

  it('详情抽屉展示行项目与推送流水', async () => {
    getFulfillmentOrdersMock.mockResolvedValue({ data: { code: 0, data: { list: [failedRow], pagination: pager } } })
    getFulfillmentOrderMock.mockResolvedValue({ data: { code: 0, data: detailRow } })

    const wrapper = await mountPage(
      (await import('@/views/wms/FulfillmentOrderListView.vue')).default,
      ['wms.order.view', 'wms.order.manage'],
    )

    await wrapper.find('[data-testid="detail-11"]').trigger('click')
    await flushPromises()
    await flushPromises()

    const detail = document.querySelector('[data-testid="fo-detail"]')
    expect(detail?.textContent).toContain('魔方 A')
    expect(detail?.textContent).toContain('deliveryorder.create')
    expect(detail?.textContent).toContain('REQ-1')
    expect(detail?.querySelector('[data-testid="detail-items"]')).not.toBeNull()
  })

  it('无 wms.order.manage 时重推/取消/批量按钮不渲染', async () => {
    getFulfillmentOrdersMock.mockResolvedValue({ data: { code: 0, data: { list: [failedRow], pagination: pager } } })

    const wrapper = await mountPage(
      (await import('@/views/wms/FulfillmentOrderListView.vue')).default,
      ['wms.order.view'],
    )

    expect(wrapper.find('[data-testid="push-11"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="cancel-11"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="batch-push"]').exists()).toBe(false)
    // 只读账号仍可看详情
    expect(wrapper.find('[data-testid="detail-11"]').exists()).toBe(true)
  })

  it('接口异常时给出可读错误态（含 request_id）', async () => {
    getFulfillmentOrdersMock.mockRejectedValue({
      message: '仓库不存在',
      data: { request_id: 'REQ-XYZ' },
    })

    const wrapper = await mountPage(
      (await import('@/views/wms/FulfillmentOrderListView.vue')).default,
      ['wms.order.view'],
    )

    expect(wrapper.find('[data-testid="tip"]').text()).toContain('仓库不存在')
    expect(wrapper.find('[data-testid="tip"]').text()).toContain('REQ-XYZ')
  })

  it('空数据显示空态而非崩溃', async () => {
    getFulfillmentOrdersMock.mockResolvedValue({
      data: { code: 0, data: { list: [], pagination: { ...pager, total: 0 } } },
    })

    const wrapper = await mountPage(
      (await import('@/views/wms/FulfillmentOrderListView.vue')).default,
      ['wms.order.view'],
    )

    expect(wrapper.find('[data-testid="empty"]').text()).toBe('暂时无数据')
  })

  it('勾选多行后批量重推提交全部 id', async () => {
    getFulfillmentOrdersMock.mockResolvedValue({ data: { code: 0, data: { list: [failedRow], pagination: pager } } })
    batchPushFulfillmentOrdersMock.mockResolvedValue({
      data: { code: 0, data: { total: 1, succeeded: 1, results: [{ id: 11, success: true, message: 'ok' }] } },
    })

    const wrapper = await mountPage(
      (await import('@/views/wms/FulfillmentOrderListView.vue')).default,
      ['wms.order.view', 'wms.order.manage'],
    )

    await wrapper.find('[data-testid="check-all"]').trigger('change')
    await wrapper.find('[data-testid="batch-push"]').trigger('click')
    await flushPromises()

    expect(batchPushFulfillmentOrdersMock).toHaveBeenCalledWith([11])
  })
})

describe('退货入库单列表', () => {
  it('详情展示 inventory_type 与超收差异提示', async () => {
    getReturnInboundOrdersMock.mockResolvedValue({ data: { code: 0, data: { list: [rioRow], pagination: pager } } })
    getReturnInboundOrderMock.mockResolvedValue({ data: { code: 0, data: rioDetail } })

    const wrapper = await mountPage(
      (await import('@/views/wms/ReturnInboundOrderListView.vue')).default,
      ['wms.return.manage'],
    )

    await wrapper.find('[data-testid="detail-21"]').trigger('click')
    await flushPromises()
    await flushPromises()

    const detail = document.querySelector('[data-testid="rio-detail"]')
    expect(detail?.textContent).toContain('残次')
    expect(detail?.querySelector('[data-testid="diff-hint"]')?.textContent).toContain('超收 1 件')
  })

  it('列表展示关联退款单号与退货原因', async () => {
    getReturnInboundOrdersMock.mockResolvedValue({ data: { code: 0, data: { list: [rioRow], pagination: pager } } })

    const wrapper = await mountPage(
      (await import('@/views/wms/ReturnInboundOrderListView.vue')).default,
      ['wms.return.manage'],
    )

    expect(wrapper.text()).toContain('RF20260101001')
    expect(wrapper.text()).toContain('七天无理由')
  })
})

describe('WMS 调用日志', () => {
  it('详情弹窗渲染脱敏报文，不含 AppSecret 与完整手机号', async () => {
    getWmsLogsMock.mockResolvedValue({ data: { code: 0, data: { list: [logRow], pagination: pager } } })
    getWmsLogMock.mockResolvedValue({
      data: {
        code: 0,
        data: {
          ...logRow,
          request_body: { app_secret: '***', buyer_phone: '*******8000', deliveryOrderCode: 'FO20260101001' },
          response_body: { access_token: '***', flag: 'failure' },
        },
      },
    })

    const wrapper = await mountPage(
      (await import('@/views/wms/WmsApiLogView.vue')).default,
      ['wms.config.manage'],
    )

    await wrapper.find('[data-testid="detail-31"]').trigger('click')
    await flushPromises()
    await flushPromises()

    const detail = document.querySelector('[data-testid="log-detail"]')
    const text = detail?.textContent ?? ''
    expect(text).not.toContain('SUPER_SECRET')
    expect(text).not.toContain('13800138000')
    expect(text).toContain('***')
    expect(text).toContain('FO20260101001')
  })

  it('列表按成功失败筛选时请求参数正确', async () => {
    getWmsLogsMock.mockResolvedValue({ data: { code: 0, data: { list: [], pagination: pager } } })

    const wrapper = await mountPage(
      (await import('@/views/wms/WmsApiLogView.vue')).default,
      ['wms.config.manage'],
    )

    await wrapper.find('[data-testid="filter-success"]').setValue('0')
    await wrapper.find('[data-testid="search"]').trigger('click')
    await flushPromises()

    expect(getWmsLogsMock).toHaveBeenLastCalledWith(expect.objectContaining({ success: false }))
  })
})
