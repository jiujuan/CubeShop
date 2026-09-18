import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * 后台 WMS 对接配置（Vitest，WMS 计划 P0）
 *
 * 覆盖：仓库列表（含 WMS 摘要）、配置页凭证「只写不读」与 Mock 连通性测试结果、
 * 回调地址复制、SKU 映射 CSV 导入的逐行反馈与删除。
 */
const {
  getWmsWarehousesMock,
  createWmsWarehouseMock,
  updateWmsWarehouseMock,
  getWmsConfigMock,
  saveWmsConfigMock,
  testWmsConnectionMock,
  getWmsSkuMappingsMock,
  importWmsSkuMappingsMock,
  deleteWmsSkuMappingMock,
} = vi.hoisted(() => ({
  getWmsWarehousesMock: vi.fn(),
  createWmsWarehouseMock: vi.fn(),
  updateWmsWarehouseMock: vi.fn(),
  getWmsConfigMock: vi.fn(),
  saveWmsConfigMock: vi.fn(),
  testWmsConnectionMock: vi.fn(),
  getWmsSkuMappingsMock: vi.fn(),
  importWmsSkuMappingsMock: vi.fn(),
  deleteWmsSkuMappingMock: vi.fn(),
}))

vi.mock('@/api/wms', () => ({
  getWmsWarehouses: getWmsWarehousesMock,
  createWmsWarehouse: createWmsWarehouseMock,
  updateWmsWarehouse: updateWmsWarehouseMock,
  getWmsConfig: getWmsConfigMock,
  saveWmsConfig: saveWmsConfigMock,
  testWmsConnection: testWmsConnectionMock,
  getWmsSkuMappings: getWmsSkuMappingsMock,
  importWmsSkuMappings: importWmsSkuMappingsMock,
  deleteWmsSkuMapping: deleteWmsSkuMappingMock,
  WMS_PROVIDER_LABELS: { cainiao: '菜鸟（奇门）', jd_cloud: '京东云仓' },
  WMS_API_ENV_LABELS: { prod: '生产环境', sandbox: '沙箱环境' },
  WMS_MAPPING_MODE_LABELS: { same: '跟随平台 SKU 编码', manual: '手工映射' },
}))

import WarehouseListView from '@/views/wms/WarehouseListView.vue'
import WmsConfigView from '@/views/wms/WmsConfigView.vue'
import WmsSkuMappingView from '@/views/wms/WmsSkuMappingView.vue'

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'admin', nickname: '管理员', avatar: null, phone: null, email: null,
    roles: ['super_admin'], permissions: ['wms.config.manage'],
  }
  return pinia
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/wms/warehouses', component: { template: '<div />' } },
      { path: '/wms/warehouses/:id/config', component: { template: '<div />' } },
      { path: '/wms/warehouses/:id/mappings', component: { template: '<div />' } },
    ],
  })
}

/**
 * 挂载到指定路由。
 *
 * 必须先 `await router.push(path)` + `isReady()`：组件在 setup 里读 `route.params.id`，
 * 若路由尚未解析，`Number(undefined)` 会得到 NaN，导致带上错误参数调用接口。
 */
async function mountAt(component: unknown, path = '/') {
  const pinia = freshPinia()
  const router = makeRouter()
  await router.push(path)
  await router.isReady()

  return mount(component as never, {
    global: { plugins: [pinia, router], directives: { permission } },
  })
}

const warehouseFixture = {
  id: 7,
  code: 'WH_DEFAULT',
  name: '默认仓库',
  contact_name: '张三',
  contact_phone: '13800000000',
  province: '广东省',
  city: '深圳市',
  district: '南山区',
  address: '科技路 1 号',
  status: 1,
  created_at: '2026-09-20 10:00:00',
  wms: { provider: 'cainiao' as const, provider_label: '菜鸟（奇门）', enabled: true, api_env: 'sandbox' as const, sku_mapping_mode: 'same' as const },
}

const configFixture = {
  configured: true,
  id: 3,
  warehouse_id: 7,
  provider: 'cainiao' as const,
  provider_label: '菜鸟（奇门）',
  enabled: true,
  auto_push: true,
  auto_push_return: true,
  push_retry_times: 3,
  sku_mapping_mode: 'same' as const,
  app_key: 'cn-app-key',
  app_secret_masked: '****cret',
  has_app_secret: true,
  access_token_masked: null,
  has_access_token: false,
  customer_id: 'CUST-1',
  owner_no: 'OWNER-1',
  warehouse_code: 'CN-WH-1',
  warehouse_no: 'CN-WH-1-P',
  api_env: 'sandbox' as const,
  extra_config: null,
  remark: null,
  callback_url: 'https://shop.test/api/wms/callback/cainiao?token=abc',
  updated_at: '2026-09-20 10:00:00',
}

beforeEach(() => {
  vi.clearAllMocks()

  getWmsWarehousesMock.mockResolvedValue({
    data: {
      code: 0,
      message: 'ok',
      data: {
        list: [
          warehouseFixture,
          { ...warehouseFixture, id: 8, code: 'WH_NO', name: '未接入仓', wms: null },
        ],
        pagination: { page: 1, page_size: 15, total: 2, total_pages: 1 },
      },
    },
  })
  createWmsWarehouseMock.mockResolvedValue({ data: { code: 0, message: 'ok', data: warehouseFixture } })
  getWmsConfigMock.mockResolvedValue({ data: { code: 0, message: 'ok', data: configFixture } })
  saveWmsConfigMock.mockResolvedValue({ data: { code: 0, message: 'ok', data: configFixture } })
  testWmsConnectionMock.mockResolvedValue({
    data: {
      code: 0,
      message: 'ok',
      data: { success: true, mock: true, provider: 'mock', duration_ms: 3, message: '连通成功（Mock，3 ms）', error: null, request_id: 'MOCK-ABC' },
    },
  })
  getWmsSkuMappingsMock.mockResolvedValue({
    data: {
      code: 0,
      message: 'ok',
      data: {
        list: [
          {
            id: 1, warehouse_id: 7, sku_id: '01HZZZK', platform_sku_code: 'SKU-A', wms_sku_code: 'W-A',
            barcode: '6901', status: 1, product_title: '测试商品A', sku_specs: { 规格: '标准' }, updated_at: '2026-09-20 10:00:00',
          },
        ],
        pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
      },
    },
  })
  importWmsSkuMappingsMock.mockResolvedValue({
    data: {
      code: 0,
      message: '导入完成',
      data: {
        total: 3,
        success_count: 2,
        failed_count: 1,
        results: [
          { line: 1, sku_code: 'SKU-A', success: true, message: '导入成功' },
          { line: 2, sku_code: 'NOT-EXIST', success: false, message: '平台 SKU 编码不存在' },
          { line: 3, sku_code: 'SKU-B', success: true, message: '导入成功' },
        ],
      },
    },
  })
  deleteWmsSkuMappingMock.mockResolvedValue({ data: { code: 0, message: '删除成功', data: null } })
})

describe('WMS 仓库列表', () => {
  it('渲染仓库与 WMS 摘要，未配置显示「未配置」', async () => {
    const wrapper = await mountAt(WarehouseListView)
    await flushPromises()

    expect(wrapper.text()).toContain('WH_DEFAULT')
    expect(wrapper.text()).toContain('菜鸟（奇门）')
    expect(wrapper.text()).toContain('跟随平台 SKU 编码')
    // 未接入 WMS 的仓显示「未配置」
    expect(wrapper.text()).toContain('未配置')
  })

  it('新建仓库弹窗提交调用创建接口', async () => {
    const wrapper = await mountAt(WarehouseListView)
    await flushPromises()

    await wrapper.find('[data-testid="warehouse-create"]').trigger('click')
    await wrapper.find('[data-testid="warehouse-form-code"]').setValue('WH_NEW')
    await wrapper.find('[data-testid="warehouse-form-name"]').setValue('新仓库')
    await wrapper.find('[data-testid="warehouse-form-save"]').trigger('click')
    await flushPromises()

    expect(createWmsWarehouseMock).toHaveBeenCalledTimes(1)
    expect(createWmsWarehouseMock.mock.calls[0][0]).toMatchObject({ code: 'WH_NEW', name: '新仓库', status: 1 })
  })
})

describe('WMS 配置页', () => {
  it('AppSecret 不回填明文，占位显示掩码与「留空不修改」', async () => {
    const wrapper = await mountAt(WmsConfigView, '/wms/warehouses/7/config')
    await flushPromises()

    const secret = wrapper.find('[data-testid="wms-app-secret"]')
    expect((secret.element as HTMLInputElement).value).toBe('')
    expect(secret.attributes('placeholder')).toContain('已配置（****cret）')
    // 页面文本不含任何明文密钥
    expect(wrapper.html()).not.toContain('cn-app-secret')
  })

  it('京东云仓选项被禁用', async () => {
    const wrapper = await mountAt(WmsConfigView, '/wms/warehouses/7/config')
    await flushPromises()

    const jd = wrapper.find('[data-testid="wms-provider-jd_cloud"]')
    expect(jd.attributes('disabled')).toBeDefined()
  })

  it('留空 AppSecret 保存时不提交 app_secret 字段（不覆盖原值）', async () => {
    const wrapper = await mountAt(WmsConfigView, '/wms/warehouses/7/config')
    await flushPromises()

    await wrapper.find('[data-testid="wms-config-save"]').trigger('click')
    await flushPromises()

    expect(saveWmsConfigMock).toHaveBeenCalledTimes(1)
    const payload = saveWmsConfigMock.mock.calls[0][1] as Record<string, unknown>
    expect(payload).not.toHaveProperty('app_secret')
    expect(payload).not.toHaveProperty('access_token')
  })

  it('填写 AppSecret 后保存会提交该字段', async () => {
    const wrapper = await mountAt(WmsConfigView, '/wms/warehouses/7/config')
    await flushPromises()

    await wrapper.find('[data-testid="wms-app-secret"]').setValue('new-secret')
    await wrapper.find('[data-testid="wms-config-save"]').trigger('click')
    await flushPromises()

    expect((saveWmsConfigMock.mock.calls[0][1] as Record<string, unknown>).app_secret).toBe('new-secret')
  })

  it('连通性测试展示 Mock 与耗时徽标', async () => {
    const wrapper = await mountAt(WmsConfigView, '/wms/warehouses/7/config')
    await flushPromises()

    await wrapper.find('[data-testid="wms-test-connection"]').trigger('click')
    await flushPromises()

    expect(testWmsConnectionMock).toHaveBeenCalledWith(7)
    const result = wrapper.find('[data-testid="wms-test-result"]')
    expect(result.exists()).toBe(true)
    expect(result.text()).toContain('连通成功（Mock，3 ms）')
    expect(result.text()).toContain('Mock 模式')
  })

  it('回调地址只读可复制，点击后调用剪贴板', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.assign(navigator, { clipboard: { writeText } })

    const wrapper = await mountAt(WmsConfigView, '/wms/warehouses/7/config')
    await flushPromises()

    const url = wrapper.find('[data-testid="wms-callback-url"]')
    expect((url.element as HTMLInputElement).value).toContain('/api/wms/callback/cainiao?token=')
    expect(url.attributes('readonly')).toBeDefined()

    await wrapper.find('[data-testid="wms-copy-callback"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith('https://shop.test/api/wms/callback/cainiao?token=abc')
    expect(wrapper.find('[data-testid="wms-copy-callback"]').text()).toContain('已复制')
  })
})

describe('WMS SKU 映射页', () => {
  it('渲染映射列表与商品信息', async () => {
    const wrapper = await mountAt(WmsSkuMappingView, '/wms/warehouses/7/mappings')
    await flushPromises()

    expect(wrapper.text()).toContain('SKU-A')
    expect(wrapper.text()).toContain('W-A')
    expect(wrapper.text()).toContain('测试商品A')
  })

  it('手工新增映射调用导入接口（单行）', async () => {
    const wrapper = await mountAt(WmsSkuMappingView, '/wms/warehouses/7/mappings')
    await flushPromises()

    await wrapper.find('[data-testid="sku-mapping-add"]').trigger('click')
    await wrapper.find('[data-testid="mapping-form-sku-code"]').setValue('SKU-Z')
    await wrapper.find('[data-testid="mapping-form-wms-code"]').setValue('W-Z')
    await wrapper.find('[data-testid="mapping-form-save"]').trigger('click')
    await flushPromises()

    expect(importWmsSkuMappingsMock).toHaveBeenCalledWith(7, [{ sku_code: 'SKU-Z', wms_sku_code: 'W-Z', barcode: undefined }])
  })

  it('CSV 导入跳过表头并按行展示结果（含失败行号）', async () => {
    const wrapper = await mountAt(WmsSkuMappingView, '/wms/warehouses/7/mappings')
    await flushPromises()

    const csv = 'sku_code,wms_sku_code,barcode\nSKU-A,W-A,6901\nNOT-EXIST,W-X,\nSKU-B,W-B,'
    const file = new File([csv], 'mapping.csv', { type: 'text/csv' })
    // jsdom 的 File 无 text()，补齐后触发 change
    Object.defineProperty(file, 'text', { value: () => Promise.resolve(csv) })

    const input = wrapper.find('[data-testid="sku-mapping-file"]')
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await flushPromises()

    expect(importWmsSkuMappingsMock).toHaveBeenCalledTimes(1)
    // 表头被跳过，只剩 3 行数据
    expect(importWmsSkuMappingsMock.mock.calls[0][1]).toEqual([
      { sku_code: 'SKU-A', wms_sku_code: 'W-A', barcode: '6901' },
      { sku_code: 'NOT-EXIST', wms_sku_code: 'W-X', barcode: undefined },
      { sku_code: 'SKU-B', wms_sku_code: 'W-B', barcode: undefined },
    ])

    const result = wrapper.find('[data-testid="sku-mapping-import-result"]')
    expect(result.exists()).toBe(true)
    expect(result.text()).toContain('成功 2')
    expect(result.text()).toContain('失败 1')
    expect(result.text()).toContain('平台 SKU 编码不存在')
  })

  it('删除映射需二次确认，确认后按 sku_id 调删除接口', async () => {
    const wrapper = await mountAt(WmsSkuMappingView, '/wms/warehouses/7/mappings')
    await flushPromises()

    await wrapper.find('[data-testid="mapping-delete-SKU-A"]').trigger('click')
    expect(deleteWmsSkuMappingMock).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="mapping-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(deleteWmsSkuMappingMock).toHaveBeenCalledWith(7, '01HZZZK')
  })
})
