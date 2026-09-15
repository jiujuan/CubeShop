import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getProductsMock, getCategoriesMock, getProductMock, addToCartMock,
  getAttributesMock, getBrandsMock,
} = vi.hoisted(() => ({
  getProductsMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getProductMock: vi.fn(),
  addToCartMock: vi.fn(),
  getAttributesMock: vi.fn(),
  getBrandsMock: vi.fn(),
}))

vi.mock('@/api/shop', () => ({
  getProducts: getProductsMock,
  getCategories: getCategoriesMock,
  getProduct: getProductMock,
  getHot: vi.fn(),
  getAttributes: getAttributesMock,
  getBrands: getBrandsMock,
}))
vi.mock('@/api/user', () => ({
  addToCart: addToCartMock,
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  getCart: vi.fn(),
}))

import BrowseView from '@/views/BrowseView.vue'
import ProductCard from '@/components/ProductCard.vue'
import { useAuthStore } from '@/stores/auth'

const product = {
  id: 7,
  title: '无线蓝牙耳机',
  subtitle: '降噪持久续航',
  main_image: null,
  price: '129.00',
  sales_count: 12000,
  category: { id: 3, name: '数码配件' },
}

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/category/:id', component: { template: '<div />' } },
      { path: '/product/:id', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/register', component: { template: '<div />' } },
    ],
  })
  return router
}

function makeProductSku() {
  return { id: 1, sku_id: 1, sku_code: 'SKU-A', specs: {}, price: '129.00', stock: 5 }
}

describe('分类商品列表页（BrowseView）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    getCategoriesMock.mockResolvedValue({
      data: { data: [{ id: 3, name: '数码配件', children: [{ id: 31, name: '手机配件' }] }] },
    })
    getProductsMock.mockResolvedValue({
      data: { data: { list: [product], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
    })
    getBrandsMock.mockResolvedValue({ data: { data: [] } })
    getAttributesMock.mockResolvedValue({ data: { data: [] } })
  })

  it('渲染分类侧栏与商品卡片，点击子分类跳转 /category/:id', async () => {
    const router = makeRouter()
    const { container } = render(BrowseView, { global: { plugins: [router] } })
    await router.isReady()
    router.push('/category/3')

    // 商品卡片（竖版：价格 + 加购按钮）
    await waitFor(() => expect(screen.getByText('无线蓝牙耳机')).toBeTruthy())
    expect(container.textContent).toContain('¥129.00')
    expect(screen.getByText('加入购物车')).toBeTruthy()

    // 侧栏子分类（激活分类展开后出现）点击 → 路由跳转
    let child: HTMLButtonElement | undefined
    await waitFor(() => {
      child = Array.from(container.querySelectorAll('aside button')).find(
        (b) => b.textContent?.includes('手机配件'),
      ) as HTMLButtonElement | undefined
      expect(child).toBeTruthy()
    })
    await fireEvent.click(child!)
    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/category/31'))
  })

  it('无数据时显示「暂无分类商品」', async () => {
    getProductsMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 20, total: 0, total_pages: 1 } } },
    })
    render(BrowseView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByText('暂无分类商品')).toBeTruthy())
  })
})

describe('列表页按属性筛选（T-014）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    getCategoriesMock.mockResolvedValue({
      data: { data: [{ id: 3, name: '数码配件', children: [{ id: 31, name: '手机配件' }] }] },
    })
    getProductsMock.mockResolvedValue({
      data: { data: { list: [product], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
    })
    getBrandsMock.mockResolvedValue({
      data: { data: [{ id: 5, name: '安克', logo: null }, { id: 6, name: '绿联', logo: null }] },
    })
    getAttributesMock.mockResolvedValue({
      data: {
        data: [
          {
            id: 11, name: '材质', type: 'param', is_filterable: true, is_multiple: false,
            values: [{ id: 111, value: '金属' }, { id: 112, value: '塑料' }],
          },
        ],
      },
    })
  })

  async function renderAtCategory() {
    const router = makeRouter()
    const utils = render(BrowseView, { global: { plugins: [router] } })
    await router.isReady()
    router.push('/category/3')
    await waitFor(() => expect(screen.getByTestId('filter-toggle')).toBeTruthy())
    return { router, utils }
  }

  it('筛选面板按当前分类渲染品牌与可筛属性', async () => {
    const { utils } = await renderAtCategory()
    await fireEvent.click(screen.getByTestId('filter-toggle'))

    await waitFor(() => expect(screen.getByTestId('filter-panel')).toBeTruthy())
    expect(screen.getByTestId('filter-brands')).toBeTruthy()
    expect(screen.getByText('安克')).toBeTruthy()
    expect(screen.getByTestId('filter-attr-11')).toBeTruthy()
    expect(screen.getByTestId('filter-attr-11-金属')).toBeTruthy()
    // 拉取筛选维度时携带当前分类（路由切换后触发）
    await waitFor(() =>
      expect(getAttributesMock).toHaveBeenCalledWith({ category_id: 3, filterable: 1 }),
    )
    void utils
  })

  it('多选品牌与属性后请求参数组装正确，并同步 URL', async () => {
    const { router } = await renderAtCategory()
    await fireEvent.click(screen.getByTestId('filter-toggle'))
    await waitFor(() => expect(screen.getByTestId('filter-panel')).toBeTruthy())

    getProductsMock.mockClear()
    await fireEvent.click(screen.getByTestId('filter-brand-5'))
    await waitFor(() => expect(router.currentRoute.value.query.brand).toBe('5'))

    await fireEvent.click(screen.getByTestId('filter-attr-11-金属'))
    await waitFor(() => expect(router.currentRoute.value.query.attr).toEqual(['11:金属']))

    // 最后一次请求应带上品牌与属性参数
    await waitFor(() => {
      const last = getProductsMock.mock.calls.at(-1)?.[0]
      expect(last.brand_id).toBe(5)
      expect(last.attribute_values).toEqual(['11:金属'])
    })
  })

  it('已选条件以 chips 展示，支持单个移除与一键清空', async () => {
    const { router } = await renderAtCategory()
    await fireEvent.click(screen.getByTestId('filter-toggle'))
    await waitFor(() => expect(screen.getByTestId('filter-panel')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('filter-brand-5'))
    await waitFor(() => expect(screen.getByTestId('filter-chips')).toBeTruthy())
    expect(screen.getByTestId('chip-brand-5').textContent).toContain('安克')

    // 单个移除
    await fireEvent.click(screen.getByTestId('chip-brand-5').querySelector('button')!)
    await waitFor(() => expect(router.currentRoute.value.query.brand).toBeUndefined())

    // 再选一个后一键清空
    await fireEvent.click(screen.getByTestId('filter-brand-5'))
    await waitFor(() => expect(router.currentRoute.value.query.brand).toBe('5'))
    await fireEvent.click(screen.getByTestId('filter-clear'))
    await waitFor(() => expect(router.currentRoute.value.query.brand).toBeUndefined())
  })

  it('筛选后无结果时展示空态与「清空筛选条件」入口', async () => {
    const { router } = await renderAtCategory()
    await fireEvent.click(screen.getByTestId('filter-toggle'))
    await waitFor(() => expect(screen.getByTestId('filter-panel')).toBeTruthy())

    getProductsMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 20, total: 0, total_pages: 1 } } },
    })
    await fireEvent.click(screen.getByTestId('filter-brand-5'))

    await waitFor(() => expect(screen.getByTestId('empty-clear-filter')).toBeTruthy())
    // 点击清空后筛选条件被移除（URL query 清空）
    await fireEvent.click(screen.getByTestId('empty-clear-filter'))
    await waitFor(() => expect(router.currentRoute.value.query.brand).toBeUndefined())
  })
})

describe('ProductCard 竖版卡片快捷加购', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('已登录：点击加购取第一个 SKU 下单', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const auth = useAuthStore()
    auth.setToken('tok-ui-1')
    getProductMock.mockResolvedValue({ data: { data: { ...product, skus: [makeProductSku()] } } })
    addToCartMock.mockResolvedValue({ data: { data: null } })

    render(ProductCard, {
      props: { product, layout: 'vertical', tag: 'hot' },
      global: { plugins: [makeRouter(), pinia] },
    })
    fireEvent.click(screen.getByTitle('加入购物车'))

    await waitFor(() => expect(addToCartMock).toHaveBeenCalledWith(1, 1))
    await waitFor(() => expect(screen.getByText('已加入购物车')).toBeTruthy())
  })

  it('未登录：点击加购跳登录页（不调加购接口）', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = makeRouter()
    render(ProductCard, {
      props: { product, layout: 'vertical' },
      global: { plugins: [router, pinia] },
    })
    fireEvent.click(screen.getByTitle('加入购物车'))

    await router.isReady()
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/login'))
    expect(getProductMock).not.toHaveBeenCalled()
    expect(addToCartMock).not.toHaveBeenCalled()
  })
})
