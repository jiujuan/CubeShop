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
    // P2-11：分类 id 对外是字符串（与前端 categoryId 的比较口径保持一致）
    getCategoriesMock.mockResolvedValue({
      data: { data: [{ id: '3', name: '数码配件', children: [{ id: '31', name: '手机配件' }] }] },
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
      data: { data: [{ id: '3', name: '数码配件', children: [{ id: '31', name: '手机配件' }] }] },
    })
    getProductsMock.mockResolvedValue({
      data: { data: { list: [product], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
    })
    getBrandsMock.mockResolvedValue({
      data: { data: [{ id: '5', name: '安克', logo: null }, { id: '6', name: '绿联', logo: null }] },
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

  it('筛选面板只渲染可筛属性（品牌已上移至顶部信息区）', async () => {
    const { utils } = await renderAtCategory()
    await fireEvent.click(screen.getByTestId('filter-toggle'))

    await waitFor(() => expect(screen.getByTestId('filter-panel')).toBeTruthy())
    expect(screen.queryByTestId('filter-brands')).toBeNull()
    expect(screen.getByTestId('filter-attr-11')).toBeTruthy()
    expect(screen.getByTestId('filter-attr-11-金属')).toBeTruthy()
    // 拉取筛选维度时携带当前分类（路由切换后触发）
    await waitFor(() =>
      expect(getAttributesMock).toHaveBeenCalledWith({ category_id: '3', filterable: 1 }),
    )
    // 品牌接口同样按当前分类收敛
    await waitFor(() => expect(getBrandsMock).toHaveBeenCalledWith({ category_id: '3' }))
    void utils
  })

  it('顶部品牌与属性筛选后请求参数组装正确，并同步 URL', async () => {
    const { router } = await renderAtCategory()
    await waitFor(() => expect(screen.getByTestId('heading-brand-5')).toBeTruthy())

    getProductsMock.mockClear()
    await fireEvent.click(screen.getByTestId('heading-brand-5'))
    await waitFor(() => expect(router.currentRoute.value.query.brand).toBe('5'))

    await fireEvent.click(screen.getByTestId('filter-toggle'))
    await waitFor(() => expect(screen.getByTestId('filter-panel')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('filter-attr-11-金属'))
    await waitFor(() => expect(router.currentRoute.value.query.attr).toEqual(['11:金属']))

    // 最后一次请求应带上品牌与属性参数
    await waitFor(() => {
      const last = getProductsMock.mock.calls.at(-1)?.[0]
      expect(last.brand_id).toBe('5')
      expect(last.attribute_values).toEqual(['11:金属'])
    })
  })

  it('已选条件以 chips 展示，支持单个移除与一键清空', async () => {
    const { router } = await renderAtCategory()
    await waitFor(() => expect(screen.getByTestId('heading-brand-5')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('heading-brand-5'))
    await waitFor(() => expect(screen.getByTestId('filter-chips')).toBeTruthy())
    expect(screen.getByTestId('chip-brand-5').textContent).toContain('安克')

    // 单个移除
    await fireEvent.click(screen.getByTestId('chip-brand-5').querySelector('button')!)
    await waitFor(() => expect(router.currentRoute.value.query.brand).toBeUndefined())

    // 再选一个后一键清空
    await fireEvent.click(screen.getByTestId('heading-brand-5'))
    await waitFor(() => expect(router.currentRoute.value.query.brand).toBe('5'))
    await fireEvent.click(screen.getByTestId('filter-clear'))
    await waitFor(() => expect(router.currentRoute.value.query.brand).toBeUndefined())
  })

  it('筛选后无结果时展示空态与「清空筛选条件」入口', async () => {
    const { router } = await renderAtCategory()
    await waitFor(() => expect(screen.getByTestId('heading-brand-5')).toBeTruthy())

    getProductsMock.mockResolvedValue({
      data: { data: { list: [], pagination: { page: 1, page_size: 20, total: 0, total_pages: 1 } } },
    })
    await fireEvent.click(screen.getByTestId('heading-brand-5'))

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

describe('分类页顶部信息区与价格区间浮层（2026-09-19 改版）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    getCategoriesMock.mockResolvedValue({
      data: { data: [{ id: '3', name: '数码配件', children: [{ id: '31', name: '手机配件' }] }] },
    })
    getProductsMock.mockResolvedValue({
      data: { data: { list: [product], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
    })
    getBrandsMock.mockResolvedValue({
      data: { data: [{ id: '5', name: '安克', logo: null }, { id: '6', name: '绿联', logo: null }] },
    })
    getAttributesMock.mockResolvedValue({ data: { data: [] } })
  })

  async function renderAt(path: string) {
    const router = makeRouter()
    render(BrowseView, { global: { plugins: [router] } })
    await router.isReady()
    router.push(path)
    await waitFor(() => expect(screen.getByTestId('browse-heading')).toBeTruthy())
    return router
  }

  it('顶部信息区展示大分类、小分类名与该分类下品牌', async () => {
    await renderAt('/category/31')

    await waitFor(() => expect(screen.getByTestId('browse-heading-root').textContent).toBe('数码配件'))
    // 命中小分类：以高亮按钮呈现
    const sub = screen.getByTestId('heading-sub-31')
    expect(sub.textContent).toBe('手机配件')
    expect(sub.className).toContain('text-[#1677ff]')
    expect(screen.getByTestId('browse-heading-brands')).toBeTruthy()
    expect(screen.getByTestId('heading-brand-5').textContent).toBe('安克')
    expect(screen.getByTestId('heading-brand-6').textContent).toBe('绿联')
  })

  it('大分类与小分类均可点击：大分类回一级页，小分类进二级页', async () => {
    const router = await renderAt('/category/31')

    // 等分类数据就绪（activeRoot 依赖它）再点击
    await waitFor(() => expect(screen.getByTestId('heading-sub-31')).toBeTruthy())

    // 处于小分类时大分类可点 → 跳一级分类页
    await fireEvent.click(screen.getByTestId('browse-heading-root'))
    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/category/3'))

    // 处于大分类时小分类可点 → 跳二级分类页
    await waitFor(() => expect(screen.getByTestId('heading-sub-31')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('heading-sub-31'))
    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/category/31'))
  })

  it('处于大分类时，小分类名位置展示其下全部小分类（均可点击）', async () => {
    await renderAt('/category/3')

    await waitFor(() => expect(screen.getByTestId('browse-heading-root').textContent).toBe('数码配件'))
    expect(screen.getByTestId('heading-sub-31').textContent).toBe('手机配件')
  })

  it('品牌接口按当前分类拉取（category_brands 收敛）', async () => {
    await renderAt('/category/31')
    await waitFor(() =>
      expect(getBrandsMock).toHaveBeenCalledWith({ category_id: '31' }),
    )
  })

  it('点击顶部品牌名即按该品牌筛选（与 chips 同源）', async () => {
    const router = await renderAt('/category/3')

    await fireEvent.click(screen.getByTestId('heading-brand-5'))
    await waitFor(() => expect(router.currentRoute.value.query.brand).toBe('5'))
  })

  it('价格区间浮层：点击其它地方收起，点击浮层内部不收起', async () => {
    await renderAt('/category/3')

    await fireEvent.click(screen.getByTestId('price-filter-toggle'))
    await waitFor(() => expect(screen.getByTestId('price-filter-pop')).toBeTruthy())

    // 点击浮层内部（最低价输入框）→ 保持展开
    await fireEvent.click(screen.getByPlaceholderText('最低价'))
    expect(screen.queryByTestId('price-filter-pop')).toBeTruthy()

    // 点击页面其它位置 → 收起
    await fireEvent.click(document.body)
    await waitFor(() => expect(screen.queryByTestId('price-filter-pop')).toBeNull())
  })
})
