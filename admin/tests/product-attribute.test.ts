import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/** 统一 mock：属性域 + 商品域 API */
const {
  getBrandsMock, createBrandMock, updateBrandMock, deleteBrandMock,
  getAttributesMock, createAttributeMock, updateAttributeMock, deleteAttributeMock,
  batchSaveAttributeValuesMock, getCategoryTemplateMock, saveCategoryTemplateMock,
  previewSkuMatrixMock,
  getCategoriesMock, getProductMock, createProductMock, updateProductMock, updateProductStatusMock, uploadImageMock,
} = vi.hoisted(() => ({
  getBrandsMock: vi.fn(), createBrandMock: vi.fn(), updateBrandMock: vi.fn(), deleteBrandMock: vi.fn(),
  getAttributesMock: vi.fn(), createAttributeMock: vi.fn(), updateAttributeMock: vi.fn(), deleteAttributeMock: vi.fn(),
  batchSaveAttributeValuesMock: vi.fn(), getCategoryTemplateMock: vi.fn(), saveCategoryTemplateMock: vi.fn(),
  previewSkuMatrixMock: vi.fn(),
  getCategoriesMock: vi.fn(), getProductMock: vi.fn(), createProductMock: vi.fn(),
  updateProductMock: vi.fn(), updateProductStatusMock: vi.fn(), uploadImageMock: vi.fn(),
}))

vi.mock('@/api/attribute', () => ({
  getBrands: getBrandsMock, createBrand: createBrandMock, updateBrand: updateBrandMock, deleteBrand: deleteBrandMock,
  getAttributes: getAttributesMock, createAttribute: createAttributeMock, updateAttribute: updateAttributeMock,
  deleteAttribute: deleteAttributeMock, batchSaveAttributeValues: batchSaveAttributeValuesMock,
  getCategoryTemplate: getCategoryTemplateMock, saveCategoryTemplate: saveCategoryTemplateMock,
  previewSkuMatrix: previewSkuMatrixMock,
}))

vi.mock('@/api/product', () => ({
  getCategories: getCategoriesMock, getProduct: getProductMock, createProduct: createProductMock,
  updateProduct: updateProductMock, updateProductStatus: updateProductStatusMock, uploadImage: uploadImageMock,
}))

import BrandView from '@/views/product/BrandView.vue'
import AttributeView from '@/views/product/AttributeView.vue'
import CategoryAttributeView from '@/views/product/CategoryAttributeView.vue'
import ProductEditView from '@/views/product/ProductEditView.vue'

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'admin', nickname: null, avatar: null, phone: null, email: null,
    roles: ['super_admin'], permissions: [],
  }
  return pinia
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/products', component: { template: '<div />' } },
      { path: '/products/new', component: ProductEditView },
      { path: '/products/:id/edit', component: ProductEditView },
    ],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>, router = makeRouter()) => ({
  plugins: [pinia, router],
  directives: { permission },
})

const attrRow = {
  id: 11, name: '颜色', type: 'spec' as const, type_label: '规格',
  is_filterable: true, is_multiple: false, allow_custom: false, sort: 0,
  values: [{ id: 111, value: '黑', sort: 0 }, { id: 112, value: '白', sort: 0 }],
}

beforeEach(() => {
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({
    data: { data: [{ id: 3, parent_id: 0, name: '数码配件', sort: 0, status: 1, children: [{ id: 31, parent_id: 3, name: '手机配件', sort: 0, status: 1, children: [] }] }] },
  })
  getBrandsMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 20, total: 0, total_pages: 1 } } } })
  getAttributesMock.mockResolvedValue({ data: { data: { list: [attrRow], pagination: { page: 1, page_size: 200, total: 1, total_pages: 1 } } } })
  getCategoryTemplateMock.mockResolvedValue({
    data: { data: { category_id: 3, category_name: '数码配件', attributes: [{ attribute_id: 11, name: '颜色', type: 'spec', is_filterable: true, is_required: false, sort: 0 }] } },
  })
})

// ---------- T-011 品牌管理 ----------
describe('品牌管理页（T-011）', () => {
  it('渲染品牌列表', async () => {
    getBrandsMock.mockResolvedValue({
      data: { data: { list: [{ id: 5, name: '安克', logo: null, sort: 0, status: 1, product_count: 2 }], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
    })
    const wrapper = mount(BrandView, { global: globalCfg(freshPinia()) })
    await flushPromises()
    expect(wrapper.text()).toContain('安克')
    expect(wrapper.text()).toContain('2')
  })

  it('删除被引用品牌时展示后端拒绝原因', async () => {
    getBrandsMock.mockResolvedValue({
      data: { data: { list: [{ id: 5, name: '安克', logo: null, sort: 0, status: 1, product_count: 2 }], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
    })
    deleteBrandMock.mockRejectedValue(new Error('该品牌已被 2 个商品引用，无法删除'))

    const wrapper = mount(BrandView, { global: globalCfg(freshPinia()), attachTo: document.body })
    await flushPromises()

    // 点击行内「删除」→ 弹出确认框 → 点击确认
    const rowDelete = wrapper.findAll('button').find((b) => b.text().includes('删除'))
    await rowDelete!.trigger('click')
    await flushPromises()

    const confirm = document.querySelector('[data-testid="confirm-ok"]') as HTMLButtonElement | null
    expect(confirm).toBeTruthy()
    confirm!.click()
    await flushPromises()

    expect(deleteBrandMock).toHaveBeenCalledWith(5)
    expect(wrapper.text()).toContain('无法删除')
    wrapper.unmount()
  })
})

// ---------- T-011 属性库 ----------
describe('属性库页（T-011）', () => {
  it('选中属性后编辑值并保存（覆盖语义）', async () => {
    batchSaveAttributeValuesMock.mockResolvedValue({ data: { data: { created: 1, removed: 0, retained: [] } } })
    const wrapper = mount(AttributeView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    // 默认选中第一个属性，值「黑/白」已展示
    expect(wrapper.text()).toContain('颜色')
    expect(wrapper.text()).toContain('黑')

    // 新增一个值
    const input = wrapper.find('input[placeholder="输入属性值后回车添加"]')
    await input.setValue('蓝')
    await wrapper.findAll('button').find((b) => b.text().includes('添加'))!.trigger('click')

    // 保存
    await wrapper.findAll('button').find((b) => b.text().includes('保存属性值'))!.trigger('click')
    await flushPromises()

    expect(batchSaveAttributeValuesMock).toHaveBeenCalledWith(11, ['黑', '白', '蓝'])
  })
})

// ---------- T-011 分类模板 ----------
describe('分类属性模板页（T-011）', () => {
  it('勾选属性并保存模板，请求参数正确', async () => {
    saveCategoryTemplateMock.mockResolvedValue({ data: { data: null } })
    const wrapper = mount(CategoryAttributeView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    const checkbox = wrapper.find('[data-testid="tmpl-attr-11"]')
    await checkbox.setValue(true)

    await wrapper.findAll('button').find((b) => b.text().includes('保存模板'))!.trigger('click')
    await flushPromises()

    expect(saveCategoryTemplateMock).toHaveBeenCalled()
    const [catId, attrs] = saveCategoryTemplateMock.mock.calls[0]
    expect(catId).toBe(3)
    expect(attrs).toEqual([{ attribute_id: 11, is_required: false, sort: 1 }])
  })
})

// ---------- T-010 商品表单 ----------
describe('商品编辑表单（T-010）', () => {
  async function mountCreate() {
    const router = makeRouter()
    router.push('/products/new')
    await router.isReady()
    const wrapper = mount(ProductEditView, { global: globalCfg(freshPinia(), router), attachTo: document.body })
    await flushPromises()
    return { wrapper, router }
  }

  it('切换分类后按模板渲染规格属性', async () => {
    const { wrapper } = await mountCreate()
    await wrapper.find('[data-testid="category-select"]').setValue(31)
    await flushPromises()

    expect(getCategoryTemplateMock).toHaveBeenCalled()
    expect(wrapper.find('[data-testid="spec-attr-11"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="spec-value-11-111"]').text()).toBe('黑')
  })

  it('勾选规格值后调用矩阵接口并渲染 SKU 行与数量提示', async () => {
    previewSkuMatrixMock.mockResolvedValue({
      data: { data: { total: 2, created: [{ signature: '颜色:黑', specs: { 颜色: '黑' } }, { signature: '颜色:白', specs: { 颜色: '白' } }], kept: [], removed: [], max_skus: 200 } },
    })
    const { wrapper } = await mountCreate()
    await wrapper.find('[data-testid="category-select"]').setValue(31)
    await flushPromises()

    await wrapper.find('[data-testid="spec-value-11-111"]').trigger('click')
    await flushPromises()

    expect(previewSkuMatrixMock).toHaveBeenCalled()
    const arg = previewSkuMatrixMock.mock.calls[0][0]
    expect(arg.specs_selection).toEqual([{ attribute_id: 11, values: [111] }])
    expect(wrapper.find('[data-testid="matrix-count"]').text()).toContain('2')
    expect(wrapper.find('[data-testid="sku-row-0"]').exists()).toBe(true)
  })

  it('超过 SKU 上限时保存被阻断', async () => {
    previewSkuMatrixMock.mockResolvedValue({
      data: { data: { total: 500, created: [{ signature: '颜色:黑', specs: { 颜色: '黑' } }], kept: [], removed: [], max_skus: 200 } },
    })
    const { wrapper } = await mountCreate()
    await wrapper.find('[data-testid="category-select"]').setValue(31)
    await flushPromises()
    // 填标题
    await wrapper.find('input[placeholder="请输入商品标题"]').setValue('测试商品')
    await wrapper.find('[data-testid="spec-value-11-111"]').trigger('click')
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text().includes('保存'))!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('超过上限')
  })

  it('批量粘贴规格文本可填充价格库存', async () => {
    previewSkuMatrixMock.mockResolvedValue({
      data: { data: { total: 2, created: [{ signature: '颜色:黑', specs: { 颜色: '黑' } }, { signature: '颜色:白', specs: { 颜色: '白' } }], kept: [], removed: [], max_skus: 200 } },
    })
    const { wrapper } = await mountCreate()
    await wrapper.find('[data-testid="category-select"]').setValue(31)
    await flushPromises()
    await wrapper.find('[data-testid="spec-value-11-111"]').trigger('click')
    await wrapper.find('[data-testid="spec-value-11-112"]').trigger('click')
    await flushPromises()

    // 打开粘贴面板
    await wrapper.findAll('button').find((b) => b.text().includes('批量粘贴规格文本'))!.trigger('click')
    const textarea = wrapper.find('textarea')
    await textarea.setValue('黑|99.00|10|SKU-B')
    await wrapper.findAll('button').find((b) => b.text().includes('解析并填充'))!.trigger('click')
    await flushPromises()

    const first = wrapper.find('[data-testid="sku-row-0"]')
    expect((first.find('input[placeholder="留空自动生成"]').element as HTMLInputElement).value).toBe('SKU-B')
  })

  it('保存前弹出摘要确认（新增/保留/失效）', async () => {
    previewSkuMatrixMock.mockResolvedValue({
      data: { data: { total: 2, created: [{ signature: '颜色:黑', specs: { 颜色: '黑' } }, { signature: '颜色:白', specs: { 颜色: '白' } }], kept: [], removed: [{ sku_id: 9, specs: { 颜色: '红' }, signature: '颜色:红', action: 'delete' }], max_skus: 200 } },
    })
    const { wrapper } = await mountCreate()
    await wrapper.find('[data-testid="category-select"]').setValue(31)
    await flushPromises()
    await wrapper.find('input[placeholder="请输入商品标题"]').setValue('测试商品')
    await wrapper.find('[data-testid="spec-value-11-111"]').trigger('click')
    await flushPromises()

    // 填价格使校验通过
    for (const el of wrapper.findAll('input[type="number"]')) {
      const input = el.element as HTMLInputElement
      if (input.step === '0.01') await el.setValue('99')
    }

    await wrapper.findAll('button').find((b) => b.text().includes('保存'))!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('新增')
    expect(wrapper.text()).toContain('失效 1')
  })

  // ---- 历史/导入数据：规格值不在属性值库中（回归：保存撞 sku_code 唯一键） ----
  /** 挂载「历史导入商品」编辑页：存量 SKU 规格值 450ml/600ml 不在属性值库（库里只有 黑/白） */
  async function mountLegacyEdit() {
    getProductMock.mockResolvedValue({
      data: {
        data: {
          id: 3,
          title: '不锈钢保温杯',
          category_id: 3,
          status: 1,
          sort: 0,
          images: [],
          attribute_values: [],
          specs_selection: [{ attribute_id: 11, name: '颜色', value_names: ['450ml', '600ml'] }],
          skus: [
            { id: 7, sku_code: 'CS-003-01', specs: { 颜色: '450ml' }, signature: '颜色:450ml', price: '69.00', stock: 1200, status: 1 },
            { id: 8, sku_code: 'CS-003-02', specs: { 颜色: '600ml' }, signature: '颜色:600ml', price: '89.00', stock: 800, status: 1 },
          ],
        },
      },
    })
    const router = makeRouter()
    router.push('/products/3/edit')
    await router.isReady()
    const wrapper = mount(ProductEditView, { global: globalCfg(freshPinia(), router), attachTo: document.body })
    await flushPromises()
    return { wrapper, router }
  }

  it('存量规格值无法回显时切换为旧表格，不再把隐藏行静默提交', async () => {
    const { wrapper } = await mountLegacyEdit()

    expect(wrapper.find('input[placeholder="如：容量:450ml"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('不在属性值库中')
    // 矩阵 UI 不渲染，避免「界面上看不到、提交时却被带上」
    expect(wrapper.find('[data-testid="spec-value-11-111"]').exists()).toBe(false)
    expect(previewSkuMatrixMock).not.toHaveBeenCalled()
  })

  it('旧表格保存沿用原编码与原规格名（不回退成猜测的属性名）', async () => {
    updateProductMock.mockResolvedValue({ data: { code: 0, data: null } })
    updateProductStatusMock.mockResolvedValue({ data: { code: 0, data: null } })
    const { wrapper } = await mountLegacyEdit()

    await wrapper.findAll('button').find((b) => b.text().includes('保存'))!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('按编码匹配已有行')

    await wrapper.findAll('button').find((b) => b.text().includes('确认保存'))!.trigger('click')
    await flushPromises()

    expect(updateProductMock).toHaveBeenCalled()
    const [id, payload] = updateProductMock.mock.calls[0]
    expect(id).toBe(3)
    expect(payload.specs_selection).toBeUndefined()
    expect(payload.skus).toEqual([
      { sku_code: 'CS-003-01', specs: { 颜色: '450ml' }, price: 69, stock: 1200, status: 1 },
      { sku_code: 'CS-003-02', specs: { 颜色: '600ml' }, price: 89, stock: 800, status: 1 },
    ])
  })

  it('旧表格填写「属性名:值」可解析回原来的规格键', async () => {
    updateProductMock.mockResolvedValue({ data: { code: 0, data: null } })
    updateProductStatusMock.mockResolvedValue({ data: { code: 0, data: null } })
    const { wrapper } = await mountLegacyEdit()

    const specInput = wrapper.find('input[placeholder="如：容量:450ml"]')
    await specInput.setValue('容量:450ml/颜色:黑色')
    await wrapper.findAll('button').find((b) => b.text().includes('保存'))!.trigger('click')
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('确认保存'))!.trigger('click')
    await flushPromises()

    const [, payload] = updateProductMock.mock.calls[0]
    expect(payload.skus[0].specs).toEqual({ 容量: '450ml', 颜色: '黑色' })
  })
})
