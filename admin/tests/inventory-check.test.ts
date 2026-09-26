import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({
  getListMock: vi.fn(),
  createMock: vi.fn(),
  getDetailMock: vi.fn(),
  recordMock: vi.fn(),
  postMock: vi.fn(),
  cancelMock: vi.fn(),
  pushMock: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: mocks.pushMock }),
  useRoute: () => ({ params: { id: '7' } }),
}))

vi.mock('@/api/inventory-check', () => ({
  getInventoryChecks: mocks.getListMock,
  createInventoryCheck: mocks.createMock,
  getInventoryCheck: mocks.getDetailMock,
  recordInventoryCount: mocks.recordMock,
  postInventoryCheck: mocks.postMock,
  cancelInventoryCheck: mocks.cancelMock,
  importInventoryCount: vi.fn(),
  exportInventoryCheck: vi.fn(),
  CHECK_STATUS_LABELS: { draft: '待盘点', counting: '盘点中', posted: '已过账', cancelled: '已作废' },
  CHECK_SCOPE_LABELS: { all: '全部商品', category: '按分类', brand: '按品牌', keyword: '按关键词', custom: '自定义清单' },
  CHECK_ITEM_STATUS_LABELS: { pending: '待盘点', counted: '已盘点', posted: '已过账', skipped: '已跳过' },
}))

vi.mock('@/api/product', () => ({ getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }) }))
vi.mock('@/api/attribute', () => ({ getBrands: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }) }))

import InventoryCheckListView from '@/views/inventory/InventoryCheckListView.vue'
import InventoryCheckDetailView from '@/views/inventory/InventoryCheckDetailView.vue'

const listPayload = {
  list: [
    {
      id: 7,
      check_no: 'PC202609270001',
      title: '月末全盘',
      scope_type: 'all',
      scope_label: '全部商品',
      scope_value: null,
      status: 'counting',
      status_label: '盘点中',
      item_count: 12,
      counted_count: 5,
      diff_count: 2,
      total_diff_qty: 9,
      remark: null,
      created_by_name: 'admin',
      posted_by_name: null,
      posted_at: null,
      created_at: '2026-09-27 00:00:00',
    },
  ],
  pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
}

const detailPayload = {
  check: {
    ...listPayload.list[0],
    item_count: 2,
    counted_count: 1,
    diff_count: 1,
    total_diff_qty: 3,
  },
  items: {
    list: [
      {
        id: 11,
        sku_id: 3,
        sku_code: 'SKU-A',
        product_title: '商品 A',
        specs_text: '颜色:红色',
        system_qty: 10,
        locked_qty: 0,
        counted_qty: 7,
        diff_qty: null,
        status: 'counted',
        status_label: '已盘点',
        remark: null,
      },
      {
        id: 12,
        sku_id: 4,
        sku_code: 'SKU-B',
        product_title: '商品 B',
        specs_text: null,
        system_qty: 5,
        locked_qty: 0,
        counted_qty: null,
        diff_qty: null,
        status: 'pending',
        status_label: '待盘点',
        remark: null,
      },
    ],
    pagination: { page: 1, page_size: 20, total: 2, total_pages: 1 },
  },
}

beforeEach(() => {
  mocks.getListMock.mockReset()
  mocks.createMock.mockReset()
  mocks.getDetailMock.mockReset()
  mocks.recordMock.mockReset()
  mocks.postMock.mockReset()
  mocks.cancelMock.mockReset()
  mocks.pushMock.mockReset()
  mocks.getListMock.mockResolvedValue({ data: { data: listPayload } })
  mocks.getDetailMock.mockResolvedValue({ data: { data: detailPayload } })
})

describe('库存盘点列表页', () => {
  it('渲染盘点单列表与统计列', async () => {
    const w = mount(InventoryCheckListView)
    await flushPromises()

    expect(w.find('[data-testid="check-row-7"]').text()).toContain('PC202609270001')
    expect(w.find('[data-testid="check-row-7"]').text()).toContain('盘点中')
    expect(w.find('[data-testid="check-row-7"]').text()).toContain('12')
  })

  it('新建盘点后跳转到详情', async () => {
    mocks.createMock.mockResolvedValue({ data: { data: { id: 9 } } })
    const w = mount(InventoryCheckListView)
    await flushPromises()

    await w.find('[data-testid="check-create-open"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="check-create-dialog"]').exists()).toBe(true)

    await w.find('[data-testid="check-scope-select"]').setValue('keyword')
    await w.find('[data-testid="check-scope-keyword"]').setValue('T恤')
    await w.find('[data-testid="check-create-submit"]').trigger('click')
    await flushPromises()

    expect(mocks.createMock).toHaveBeenCalledWith(expect.objectContaining({ scope_type: 'keyword', scope_value: 'T恤' }))
    expect(mocks.pushMock).toHaveBeenCalledWith('/inventory-checks/9')
  })

  it('范围未填写时拒绝提交并提示', async () => {
    const w = mount(InventoryCheckListView)
    await flushPromises()

    await w.find('[data-testid="check-create-open"]').trigger('click')
    await flushPromises()
    await w.find('[data-testid="check-scope-select"]').setValue('keyword')
    await w.find('[data-testid="check-create-submit"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="check-create-error"]').text()).toContain('盘点范围')
    expect(mocks.createMock).not.toHaveBeenCalled()
  })
})

describe('库存盘点详情页', () => {
  it('渲染统计条与明细行', async () => {
    const w = mount(InventoryCheckDetailView)
    await flushPromises()

    expect(w.find('[data-testid="check-detail-no"]').text()).toBe('PC202609270001')
    expect(w.find('[data-testid="check-stat-items"]').text()).toBe('2')
    expect(w.find('[data-testid="check-stat-diff"]').text()).toBe('1')
    expect(w.find('[data-testid="item-row-11"]').text()).toContain('SKU-A')
    expect((w.find('[data-testid="count-input-11"]').element as HTMLInputElement).value).toBe('7')
  })

  it('修改实盘数量后调用录入接口', async () => {
    mocks.recordMock.mockResolvedValue({ data: { data: { updated: 1 } } })
    const w = mount(InventoryCheckDetailView)
    await flushPromises()

    await w.find('[data-testid="count-input-11"]').setValue('9')
    await w.find('[data-testid="count-input-11"]').trigger('change')
    await flushPromises()

    expect(mocks.recordMock).toHaveBeenCalledWith(7, [{ item_id: 11, counted_qty: 9 }])
  })

  it('过账需二次确认，确认后调用过账接口', async () => {
    mocks.postMock.mockResolvedValue({ data: { data: { adjusted: 1 } } })
    const w = mount(InventoryCheckDetailView)
    await flushPromises()

    await w.find('[data-testid="check-post"]').trigger('click')
    expect(w.find('[data-testid="check-confirm"]').exists()).toBe(true)
    expect(mocks.postMock).not.toHaveBeenCalled()

    await w.find('[data-testid="check-confirm-yes"]').trigger('click')
    await flushPromises()

    expect(mocks.postMock).toHaveBeenCalledWith(7)
    expect(w.find('[data-testid="check-action-ok"]').text()).toContain('过账完成')
  })

  it('作废需二次确认，确认后调用作废接口', async () => {
    mocks.cancelMock.mockResolvedValue({ data: { data: null } })
    const w = mount(InventoryCheckDetailView)
    await flushPromises()

    await w.find('[data-testid="check-cancel"]').trigger('click')
    await w.find('[data-testid="check-confirm-yes"]').trigger('click')
    await flushPromises()

    expect(mocks.cancelMock).toHaveBeenCalledWith(7)
  })

  it('已过账的盘点单不显示录入框与操作按钮', async () => {
    mocks.getDetailMock.mockResolvedValue({
      data: { data: { ...detailPayload, check: { ...detailPayload.check, status: 'posted', status_label: '已过账' } } },
    })
    const w = mount(InventoryCheckDetailView)
    await flushPromises()

    expect(w.find('[data-testid="count-input-11"]').exists()).toBe(false)
    expect((w.find('[data-testid="check-post"]').element as HTMLButtonElement).disabled).toBe(true)
  })
})
