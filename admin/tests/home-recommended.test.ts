import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const {
  getBrandsMock, getAttributesMock, getCategoryTemplateMock, previewSkuMatrixMock,
  getCategoriesMock, getProductMock, createProductMock, updateProductMock, updateProductStatusMock, uploadImageMock,
} = vi.hoisted(() => ({
  getBrandsMock: vi.fn(), getAttributesMock: vi.fn(), getCategoryTemplateMock: vi.fn(), previewSkuMatrixMock: vi.fn(),
  getCategoriesMock: vi.fn(), getProductMock: vi.fn(), createProductMock: vi.fn(),
  updateProductMock: vi.fn(), updateProductStatusMock: vi.fn(), uploadImageMock: vi.fn(),
}))

vi.mock('@/api/attribute', () => ({
  getBrands: getBrandsMock, getAttributes: getAttributesMock,
  getCategoryTemplate: getCategoryTemplateMock, previewSkuMatrix: previewSkuMatrixMock,
}))

vi.mock('@/api/product', () => ({
  getCategories: getCategoriesMock, getProduct: getProductMock, createProduct: createProductMock,
  updateProduct: updateProductMock, updateProductStatus: updateProductStatusMock, uploadImage: uploadImageMock,
}))

/** 运费模板下拉（商品表单依赖），空列表即退化为「全局默认」 */
vi.mock('@/api/shipping', () => ({
  getFreightTemplates: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
}))

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
  previewSkuMatrixMock.mockResolvedValue({
    data: { data: { total: 1, created: [{ signature: '颜色:黑', specs: { 颜色: '黑' } }], kept: [], removed: [], max_skus: 200 } },
  })
})

/** 保存流程：点「保存」→ 若弹出确认框则点「确认保存」 */
async function save(wrapper: ReturnType<typeof mount>) {
  await wrapper.findAll('button').find((b) => b.text().includes('保存'))!.trigger('click')
  await flushPromises()
  const confirm = wrapper.findAll('button').find((b) => b.text().includes('确认保存'))
  if (confirm) {
    await confirm.trigger('click')
    await flushPromises()
  }
}

describe('商品编辑页「首页推荐」勾选（P-HomeRecommend）', () => {
  async function mountCreate() {
    const router = makeRouter()
    router.push('/products/new')
    await router.isReady()
    const wrapper = mount(ProductEditView, { global: globalCfg(freshPinia(), router), attachTo: document.body })
    await flushPromises()
    return { wrapper, router }
  }

  async function fillRequired(wrapper: ReturnType<typeof mount>) {
    await wrapper.find('[data-testid="category-select"]').setValue(31)
    await flushPromises()
    await wrapper.find('input[placeholder="请输入商品标题"]').setValue('首页推荐测试商品')
    await wrapper.find('[data-testid="spec-value-11-111"]').trigger('click')
    await flushPromises()
    for (const el of wrapper.findAll('input[type="number"]')) {
      if ((el.element as HTMLInputElement).step === '0.01') await el.setValue('99')
    }
  }

  it('新建页默认不勾选，勾选后提交带 is_home_recommended=true', async () => {
    createProductMock.mockResolvedValue({ data: { data: { id: 99 } } })
    updateProductStatusMock.mockResolvedValue({ data: { code: 0, data: null } })

    const { wrapper } = await mountCreate()
    const box = wrapper.find('[data-testid="home-recommended-checkbox"]')
    expect(box.exists()).toBe(true)
    expect((box.element as HTMLInputElement).checked).toBe(false)

    await fillRequired(wrapper)
    await box.setValue(true)

    await save(wrapper)

    expect(createProductMock).toHaveBeenCalled()
    expect(createProductMock.mock.calls[0][0].is_home_recommended).toBe(true)
    wrapper.unmount()
  })

  it('未勾选时提交 is_home_recommended=false，不影响历史商品', async () => {
    createProductMock.mockResolvedValue({ data: { data: { id: 98 } } })
    updateProductStatusMock.mockResolvedValue({ data: { code: 0, data: null } })

    const { wrapper } = await mountCreate()
    await fillRequired(wrapper)
    await save(wrapper)

    expect(createProductMock).toHaveBeenCalled()
    expect(createProductMock.mock.calls[0][0].is_home_recommended).toBe(false)
    wrapper.unmount()
  })

  /** 挂载已勾选推荐的存量商品编辑页 */
  async function mountEdit(isHomeRecommended: boolean) {
    getProductMock.mockResolvedValue({
      data: {
        data: {
          id: 3,
          title: '不锈钢保温杯',
          category_id: 3,
          status: 1,
          sort: 0,
          is_home_recommended: isHomeRecommended,
          images: [],
          attribute_values: [],
          specs_selection: [{ attribute_id: 11, name: '颜色', value_names: ['450ml'] }],
          skus: [
            { id: 7, sku_code: 'CS-003-01', specs: { 颜色: '450ml' }, signature: '颜色:450ml', price: '69.00', stock: 1200, status: 1 },
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

  it('编辑页按后台配置回显勾选状态', async () => {
    const on = await mountEdit(true)
    expect((on.wrapper.find('[data-testid="home-recommended-checkbox"]').element as HTMLInputElement).checked).toBe(true)
    on.wrapper.unmount()

    const off = await mountEdit(false)
    expect((off.wrapper.find('[data-testid="home-recommended-checkbox"]').element as HTMLInputElement).checked).toBe(false)
    off.wrapper.unmount()
  })

  it('编辑页取消勾选后提交 is_home_recommended=false', async () => {
    updateProductMock.mockResolvedValue({ data: { code: 0, data: null } })
    updateProductStatusMock.mockResolvedValue({ data: { code: 0, data: null } })

    const { wrapper } = await mountEdit(true)
    await wrapper.find('[data-testid="home-recommended-checkbox"]').setValue(false)
    await flushPromises()

    await save(wrapper)

    expect(updateProductMock).toHaveBeenCalled()
    const [id, payload] = updateProductMock.mock.calls[0]
    expect(id).toBe(3)
    expect(payload.is_home_recommended).toBe(false)
    wrapper.unmount()
  })
})
