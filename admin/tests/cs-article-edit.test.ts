import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * 文章新增/编辑独立页（CsFaqArticleEditView）
 *
 * 原先是内容管理列表页里的侧边弹层，字段太多放不下 → 拆成独立路由。
 * 本文件承接原弹层的全部表单用例（markdown 回显、封面上传、SEO/标签、
 * 关联种草商品、公告语境文案），并补「两列排布」「不是浮层」「保存后回列表」。
 */
const {
  getCsFaqCategoriesMock, getCsFaqArticleMock, createCsFaqArticleMock, updateCsFaqArticleMock,
  uploadCmsImageMock, uploadImageMock, getProductsMock,
} = vi.hoisted(() => ({
  getCsFaqCategoriesMock: vi.fn(),
  getCsFaqArticleMock: vi.fn(),
  createCsFaqArticleMock: vi.fn(),
  updateCsFaqArticleMock: vi.fn(),
  uploadCmsImageMock: vi.fn(),
  uploadImageMock: vi.fn(),
  getProductsMock: vi.fn(),
}))

vi.mock('@/api/cs', () => ({
  getCsFaqCategories: getCsFaqCategoriesMock,
  getCsFaqArticle: getCsFaqArticleMock,
  createCsFaqArticle: createCsFaqArticleMock,
  updateCsFaqArticle: updateCsFaqArticleMock,
  uploadCmsImage: uploadCmsImageMock,
}))

vi.mock('@/api/product', () => ({ uploadImage: uploadImageMock, getProducts: getProductsMock }))

// md-editor-v3 内部是 CodeMirror 6，jsdom 下跑不起来 → 用轻量桩替换
vi.mock('md-editor-v3', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    MdEditor: defineComponent({
      name: 'MdEditor',
      props: { modelValue: { type: String, default: '' }, onUploadImg: { type: Function, default: null } },
      emits: ['update:modelValue'],
      setup(props, { emit, expose }) {
        // 光标插入的桩实现：真实编辑器按光标位置插，这里退化为「追加到末尾」——
        // 断言只需验证标记进了正文（content_md），位置由真实编辑器与后端共同保证。
        expose({
          insert: (generate: () => { targetValue: string }) => {
            emit('update:modelValue', (props.modelValue ?? '') + generate().targetValue)
          },
        })
        return () => h('textarea', {
          'data-testid': 'md-editor-stub',
          value: props.modelValue,
          onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLTextAreaElement).value),
        })
      },
    }),
  }
})

import CsFaqArticleEditView from '@/views/cs/CsFaqArticleEditView.vue'

const category = (o: Record<string, unknown> = {}) => ({
  id: 1, name: '售后政策', sort: 1, is_active: true, articles_count: 2, published_count: 1,
  parent_id: 0, level: 1, path: '/1/', type: 'channel', slug: null, template: null,
  list_style: 'list', show_in_nav: false, icon: null, children: [],
  seo_title: null, seo_keywords: null, seo_description: null, ...o,
})

const articleDetail = (o: Record<string, unknown> = {}) => ({
  id: 1, category_id: 1, category: { id: 1, name: '售后政策' }, title: '如何退货', summary: '',
  slug: null, seo_title: null, seo_keywords: null, seo_description: null,
  tags: null, product_ids: [], products: [],
  cover_image: null, content_md: '正文', content: '<p>正文</p>', status: 'draft', sort: 0, is_hot: false,
  view_count: 0, helpful_count: 0, unhelpful_count: 0, helpful_rate: null, created_at: '2026-09-17 10:00:00', ...o,
})

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'operator', nickname: null, avatar: null, phone: null, email: null,
    roles: ['operator'], permissions: ['cs.faq.manage'],
  }
  return pinia
}

/** 独立页按路由取 id，必须先把路由推到目标位置再 mount（否则 params.id 为 undefined） */
async function mountPage(path: string) {
  const router: Router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/cs/faq', name: 'cs-faq', component: { template: '<div />' } },
      { path: '/cs/faq/articles/new', name: 'cs-faq-article-create', component: CsFaqArticleEditView },
      { path: '/cs/faq/articles/:id/edit', name: 'cs-faq-article-edit', component: CsFaqArticleEditView },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(CsFaqArticleEditView, { global: { plugins: [freshPinia(), router] } })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getCsFaqCategoriesMock.mockResolvedValue({ data: { data: [category()] } })
  getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail() } })
  createCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail() } })
  updateCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail() } })
})

describe('文章独立编辑页（CsFaqArticleEditView）', () => {
  it('是一条独立页面而非侧边浮层，且基本信息按两列排布', async () => {
    const { wrapper } = await mountPage('/cs/faq/articles/new')
    expect(wrapper.find('[data-testid="cs-article-edit-view"]').exists()).toBe(true)
    // 浮层会带 fixed inset-0；独立页不该有
    expect(wrapper.find('div.fixed').exists()).toBe(false)

    // 「所属栏目」「标题」「slug」「摘要」「状态」同处一个 md:grid-cols-2 容器 ⇒ 两列成行
    const grid = wrapper.find('[data-testid="cs-article-form-grid"]')
    expect(grid.exists()).toBe(true)
    expect(grid.classes()).toContain('md:grid-cols-2')
    expect(grid.find('[data-testid="cs-article-form-category"]').exists()).toBe(true)
    expect(grid.find('[data-testid="cs-article-form-title"]').exists()).toBe(true)
    expect(grid.find('[data-testid="cs-article-form-slug"]').exists()).toBe(true)
    expect(grid.find('[data-testid="cs-article-form-summary"]').exists()).toBe(true)
    expect(grid.find('[data-testid="cs-article-form-status"]').exists()).toBe(true)
    expect(grid.find('[data-testid="cs-article-form-sort"]').exists()).toBe(true)
    expect(grid.find('[data-testid="cs-article-form-hot"]').exists()).toBe(true)
  })

  it('新增：状态下拉默认「草稿」，三个选项都带前台可见性提示', async () => {
    const { wrapper } = await mountPage('/cs/faq/articles/new')

    const sel = wrapper.find('[data-testid="cs-article-form-status"]')
    expect((sel.element as HTMLSelectElement).value).toBe('draft')

    // 选项文案必须写明前台可不可见（运营最容易存了草稿以为已上线）
    expect(sel.findAll('option').map((o) => o.text())).toEqual([
      '草稿（前台不可见）',
      '已发布（前台立即可见）',
      '已下架（前台不可见）',
    ])
  })

  it('新增：状态选「已发布」后随保存提交 status=published', async () => {
    const { wrapper } = await mountPage('/cs/faq/articles/new')

    await wrapper.find('[data-testid="cs-article-form-title"]').setValue('新品发布')
    await wrapper.find('[data-testid="cs-article-form-content"]').setValue('正文')
    await wrapper.find('[data-testid="cs-article-form-status"]').setValue('published')
    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()

    expect(createCsFaqArticleMock).toHaveBeenCalledWith(expect.objectContaining({ status: 'published' }))
  })

  it('编辑：按后端 status 回显（已发布不会被悄悄改回草稿）', async () => {
    getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail({ status: 'published' }) } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    expect((wrapper.find('[data-testid="cs-article-form-status"]').element as HTMLSelectElement).value).toBe('published')

    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()
    expect(updateCsFaqArticleMock).toHaveBeenCalledWith(1, expect.objectContaining({ status: 'published' }))
  })

  it('编辑：改成「已下架」后提交 status=offline', async () => {
    getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail({ status: 'published' }) } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    await wrapper.find('[data-testid="cs-article-form-status"]').setValue('offline')
    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()

    expect(updateCsFaqArticleMock).toHaveBeenCalledWith(1, expect.objectContaining({ status: 'offline' }))
  })

  it('新增时接收列表页传来的 ?category_id= 预选栏目', async () => {
    getCsFaqCategoriesMock.mockResolvedValue({ data: { data: [
      category({ id: 1, name: '图文新闻' }),
      category({ id: 2, name: '列表新闻' }),
    ] } })
    const { wrapper } = await mountPage('/cs/faq/articles/new?category_id=2')

    expect((wrapper.find('[data-testid="cs-article-form-category"]').element as HTMLSelectElement).value).toBe('2')
  })

  it('新增：正文是 markdown 编辑器并绑定 content_md，保存时提交 title/content_md', async () => {
    const { wrapper } = await mountPage('/cs/faq/articles/new')

    expect(wrapper.findComponent({ name: 'MdEditor' }).exists()).toBe(true)
    const editor = wrapper.find('[data-testid="cs-article-form-content"]')
    await editor.setValue('## 小节标题')
    await wrapper.find('[data-testid="cs-article-form-title"]').setValue('新文章')
    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()

    expect(createCsFaqArticleMock).toHaveBeenCalledWith(
      expect.objectContaining({ title: '新文章', content_md: '## 小节标题' }),
    )
    // 不再发送 content（HTML 由后端渲染派生）
    expect(createCsFaqArticleMock.mock.calls[0][0]).not.toHaveProperty('content')
  })

  it('新增：提交 slug、SEO 与标签（SEO 折叠区默认收起）', async () => {
    const { wrapper } = await mountPage('/cs/faq/articles/new')

    await wrapper.find('[data-testid="cs-article-form-title"]').setValue('新品发布')
    await wrapper.find('[data-testid="cs-article-form-content"]').setValue('正文')
    await wrapper.find('[data-testid="cs-article-form-slug"]').setValue('new-arrival')
    await wrapper.find('[data-testid="cs-article-form-tags"]').setValue('新品, 促销')

    await wrapper.find('[data-testid="cs-article-form-seo-toggle"]').trigger('click')
    await wrapper.find('[data-testid="cs-article-form-seo-title"]').setValue('新品 SEO 标题')
    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()

    expect(createCsFaqArticleMock).toHaveBeenCalledWith(expect.objectContaining({
      slug: 'new-arrival', tags: '新品, 促销', seo_title: '新品 SEO 标题',
    }))
  })

  it('编辑：按 id 回源并回填 slug / 标签 / 正文（markdown 源，不是 HTML 产物）', async () => {
    getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail({
      slug: 'how-to-refund', tags: ['售后', '退款'], content_md: '## 退货步骤', content: '<h2>退货步骤</h2>',
    }) } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    expect(getCsFaqArticleMock).toHaveBeenCalledWith(1)
    expect((wrapper.find('[data-testid="cs-article-form-slug"]').element as HTMLInputElement).value).toBe('how-to-refund')
    expect((wrapper.find('[data-testid="cs-article-form-tags"]').element as HTMLInputElement).value).toBe('售后, 退款')
    expect((wrapper.find('[data-testid="cs-article-form-content"]').element as HTMLTextAreaElement).value).toBe('## 退货步骤')

    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()
    expect(updateCsFaqArticleMock).toHaveBeenCalledWith(1, expect.objectContaining({ content_md: '## 退货步骤' }))
  })

  it('编辑：已有 SEO 内容时折叠区自动展开（避免运营以为丢了）', async () => {
    getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail({ seo_keywords: 'a,b' }) } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    expect(wrapper.find('[data-testid="cs-article-form-seo-keywords"]').exists()).toBe(true)
    expect((wrapper.find('[data-testid="cs-article-form-seo-keywords"]').element as HTMLInputElement).value).toBe('a,b')
  })

  it('存量未迁移的行（content_md 为空）退回 HTML 产物兜底，不至于打开就是空白', async () => {
    getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail({ content_md: null, content: '<p>历史正文</p>' }) } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    expect((wrapper.find('[data-testid="cs-article-form-content"]').element as HTMLTextAreaElement).value).toBe('<p>历史正文</p>')
  })

  it('封面上传后随保存提交 cover_image', async () => {
    uploadCmsImageMock.mockResolvedValue({ data: { data: { url: 'http://x/cover.png' } } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    expect(wrapper.find('[data-testid="cs-article-form-cover-preview"]').exists()).toBe(false)

    const file = new File(['img'], 'cover.png', { type: 'image/png' })
    const input = wrapper.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await flushPromises()

    expect(uploadCmsImageMock).toHaveBeenCalledWith(file)
    expect(wrapper.find('[data-testid="cs-article-form-cover-preview"]').exists()).toBe(true)

    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()
    expect(updateCsFaqArticleMock).toHaveBeenCalledWith(1, expect.objectContaining({ cover_image: 'http://x/cover.png' }))
  })

  it('编辑：已关联商品的标题由详情接口带出，直接渲染 chips', async () => {
    getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail({
      product_ids: [5], products: [{ id: 5, public_id: '01HXPRODUCT00000000000005', title: '种草商品' }],
    }) } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    expect(wrapper.find('[data-testid="cs-article-form-product-remove-5"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cs-article-form-products"]').text()).toContain('种草商品')
  })

  it('搜索并关联种草商品后随保存提交 product_ids', async () => {
    getProductsMock.mockResolvedValue({ data: { data: { list: [{ id: 5, public_id: '01HXPRODUCT00000000000005', title: '种草商品' }], pagination: { page: 1, page_size: 10, total: 1, total_pages: 1 } } } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    await wrapper.find('[data-testid="cs-article-form-product-search"]').setValue('种草')
    await wrapper.find('[data-testid="cs-article-form-product-search-btn"]').trigger('click')
    await flushPromises()

    expect(getProductsMock).toHaveBeenCalledWith(expect.objectContaining({ keyword: '种草' }))
    await wrapper.find('[data-testid="cs-article-form-product-option-5"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="cs-article-form-product-remove-5"]').exists()).toBe(true)

    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()
    expect(updateCsFaqArticleMock).toHaveBeenCalledWith(1, expect.objectContaining({ product_ids: [5] }))
  })

  it('商品搜索结果行：未选中显示「添加」，选中后「已选」变绿并带勾', async () => {
    getProductsMock.mockResolvedValue({ data: { data: { list: [{ id: 5, public_id: '01HXPRODUCT00000000000005', title: '种草商品' }], pagination: { page: 1, page_size: 10, total: 1, total_pages: 1 } } } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    await wrapper.find('[data-testid="cs-article-form-product-search"]').setValue('种草')
    await wrapper.find('[data-testid="cs-article-form-product-search-btn"]').trigger('click')
    await flushPromises()

    const option = wrapper.find('[data-testid="cs-article-form-product-option-5"]')
    expect(option.text()).toContain('添加')
    expect(wrapper.find('[data-testid="cs-article-form-product-selected-5"]').exists()).toBe(false)

    await option.trigger('click')
    await flushPromises()

    // 「已选」绿标 + 勾图标同处一个标记，且不再出现「添加」
    const mark = wrapper.find('[data-testid="cs-article-form-product-selected-5"]')
    expect(mark.exists()).toBe(true)
    expect(mark.text()).toBe('已选')
    expect(mark.classes()).toContain('text-green-600')
    expect(mark.find('svg').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cs-article-form-product-option-5"]').text()).not.toContain('添加')
  })

  it('商品搜索面板：面板内点击不关闭，点击外部即收起', async () => {
    getProductsMock.mockResolvedValue({ data: { data: { list: [{ id: 5, public_id: '01HXPRODUCT00000000000005', title: '种草商品' }], pagination: { page: 1, page_size: 10, total: 1, total_pages: 1 } } } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    // 搜索前没有面板
    expect(wrapper.find('[data-testid="cs-article-form-product-picker"]').exists()).toBe(false)

    await wrapper.find('[data-testid="cs-article-form-product-search"]').setValue('种草')
    await wrapper.find('[data-testid="cs-article-form-product-search-btn"]').trigger('click')
    await flushPromises()

    const picker = '[data-testid="cs-article-form-product-picker"]'
    expect(wrapper.find(picker).exists()).toBe(true)
    expect(wrapper.find(picker).text()).toContain('共 1 个结果')

    // 面板内部按下（搜索框）不该收起
    wrapper.find('[data-testid="cs-article-form-product-search"]').element.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    await flushPromises()
    expect(wrapper.find(picker).exists()).toBe(true)

    // 面板外部按下 → 收起
    document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    await flushPromises()
    expect(wrapper.find(picker).exists()).toBe(false)
  })

  it('商品搜索面板：面板内「取消」按钮与 Esc 都能收起', async () => {
    getProductsMock.mockResolvedValue({ data: { data: { list: [{ id: 5, public_id: '01HXPRODUCT00000000000005', title: '种草商品' }], pagination: { page: 1, page_size: 10, total: 1, total_pages: 1 } } } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')
    const picker = '[data-testid="cs-article-form-product-picker"]'

    const search = async () => {
      await wrapper.find('[data-testid="cs-article-form-product-search"]').setValue('种草')
      await wrapper.find('[data-testid="cs-article-form-product-search-btn"]').trigger('click')
      await flushPromises()
    }

    await search()
    await wrapper.find('[data-testid="cs-article-form-product-picker-cancel"]').trigger('click')
    await flushPromises()
    expect(wrapper.find(picker).exists()).toBe(false)

    // 再搜一次 → 面板回来 → Esc 收起
    await search()
    expect(wrapper.find(picker).exists()).toBe(true)
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()
    expect(wrapper.find(picker).exists()).toBe(false)
  })

  it('商品搜索无结果时面板给出空态并提供取消', async () => {
    getProductsMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 10, total: 0, total_pages: 0 } } } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    await wrapper.find('[data-testid="cs-article-form-product-search"]').setValue('不存在')
    await wrapper.find('[data-testid="cs-article-form-product-search-btn"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-article-form-product-picker-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cs-article-form-product-picker-cancel"]').exists()).toBe(true)
  })

  // ---- 把商品卡插到正文任意位置（标记独占一段，后端换成占位、前台渲染真卡片） ----

  it('已选商品 chip 上的「插入正文」把标记插进正文（独占一段、用 public_id）', async () => {
    getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail({
      product_ids: [5], products: [{ id: 5, public_id: '01HXPRODUCT00000000000005', title: '种草商品' }],
      content_md: '现有正文',
    }) } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    await wrapper.find('[data-testid="cs-article-form-product-insert-5"]').trigger('click')
    await flushPromises()

    const md = (wrapper.find('[data-testid="cs-article-form-content"]').element as HTMLTextAreaElement).value
    // 前后留空行 ⇒ 独占一段，后端才认（行内写法会按字面保留）
    expect(md).toContain('\n\n[[product:01HXPRODUCT00000000000005]]\n\n')
    // 正文里放的是对外标识，不是自增主键
    expect(md).not.toContain('[[product:5]]')
  })

  it('插入后 chip 标出「已插入正文」，保存时标记随 content_md 一起提交', async () => {
    getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail({
      product_ids: [5], products: [{ id: 5, public_id: '01HXPRODUCT00000000000005', title: '种草商品' }],
      content_md: '现有正文',
    }) } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    expect(wrapper.find('[data-testid="cs-article-form-product-inlined-5"]').exists()).toBe(false)

    await wrapper.find('[data-testid="cs-article-form-product-insert-5"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-article-form-product-inlined-5"]').exists()).toBe(true)

    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()

    expect(updateCsFaqArticleMock).toHaveBeenCalledWith(1, expect.objectContaining({
      content_md: expect.stringContaining('[[product:01HXPRODUCT00000000000005]]'),
    }))
  })

  it('搜索结果行也能直接插入正文，并顺带把该商品关联上', async () => {
    getProductsMock.mockResolvedValue({ data: { data: { list: [{ id: 5, public_id: '01HXPRODUCT00000000000005', title: '种草商品' }], pagination: { page: 1, page_size: 10, total: 1, total_pages: 1 } } } })
    const { wrapper } = await mountPage('/cs/faq/articles/new')

    await wrapper.find('[data-testid="cs-article-form-product-search"]').setValue('种草')
    await wrapper.find('[data-testid="cs-article-form-product-search-btn"]').trigger('click')
    await flushPromises()

    // 未关联时先插入：出口只认已发布的关联商品，不关联的话前台那处是空的
    expect(wrapper.find('[data-testid="cs-article-form-product-remove-5"]').exists()).toBe(false)

    await wrapper.find('[data-testid="cs-article-form-product-insert-option-5"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-article-form-product-remove-5"]').exists()).toBe(true)
    expect((wrapper.find('[data-testid="cs-article-form-content"]').element as HTMLTextAreaElement).value)
      .toContain('[[product:01HXPRODUCT00000000000005]]')
  })

  it('插入多张卡片时逐张追加，重复插入不会报错', async () => {
    getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail({
      product_ids: [5], products: [{ id: 5, public_id: '01HXPRODUCT00000000000005', title: '种草商品' }],
      content_md: '',
    }) } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    await wrapper.find('[data-testid="cs-article-form-product-insert-5"]').trigger('click')
    await wrapper.find('[data-testid="cs-article-form-product-insert-5"]').trigger('click')
    await flushPromises()

    const md = (wrapper.find('[data-testid="cs-article-form-content"]').element as HTMLTextAreaElement).value
    expect(md.split('[[product:01HXPRODUCT00000000000005]]').length - 1).toBe(2)
  })

  it('正文图片上传走后台统一上传接口，并回填 url 给编辑器', async () => {
    uploadImageMock.mockResolvedValue({ data: { data: { url: '/storage/uploads/a.png' } } })
    const { wrapper } = await mountPage('/cs/faq/articles/new')

    const handler = wrapper.findComponent({ name: 'MdEditor' }).props('onUploadImg') as
      (files: File[], cb: (urls: Array<{ url: string; alt: string; title: string }>) => void) => Promise<void>

    const callback = vi.fn()
    const file = new File(['x'], 'a.png', { type: 'image/png' })
    await handler([file], callback)
    await flushPromises()

    expect(uploadImageMock).toHaveBeenCalledWith(file)
    expect(callback).toHaveBeenCalledWith([
      { url: '/storage/uploads/a.png', alt: 'a.png', title: 'a.png' },
    ])
  })

  it('正文图片上传失败时回调空数组并提示，不打断编辑', async () => {
    uploadImageMock.mockRejectedValue(new Error('文件过大'))
    const { wrapper } = await mountPage('/cs/faq/articles/new')

    const handler = wrapper.findComponent({ name: 'MdEditor' }).props('onUploadImg') as
      (files: File[], cb: (urls: Array<{ url: string; alt: string; title: string }>) => void) => Promise<void>

    const callback = vi.fn()
    await handler([new File(['x'], 'big.png', { type: 'image/png' })], callback)
    await flushPromises()

    expect(callback).toHaveBeenCalledWith([])
    expect(wrapper.find('[data-testid="cs-article-tip"]').text()).toContain('文件过大')
  })

  it('公告栏目下「热门」标记文案为「置顶」', async () => {
    getCsFaqCategoriesMock.mockResolvedValue({ data: { data: [category({ id: 30, name: '公告' })] } })
    getCsFaqArticleMock.mockResolvedValue({ data: { data: articleDetail({ category_id: 30, category: { id: 30, name: '公告' } }) } })
    const { wrapper } = await mountPage('/cs/faq/articles/1/edit')

    const label = wrapper.find('[data-testid="cs-article-form-hot"]').element.parentElement
    expect(label?.textContent).toContain('置顶')
    expect(label?.textContent).not.toContain('热门')
  })

  it('非公告栏目下「热门」标记文案仍是「热门」', async () => {
    const { wrapper } = await mountPage('/cs/faq/articles/new')
    const label = wrapper.find('[data-testid="cs-article-form-hot"]').element.parentElement
    expect(label?.textContent).toContain('热门')
  })

  it('缺少标题或正文时给出提示且不提交', async () => {
    const { wrapper } = await mountPage('/cs/faq/articles/new')

    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()
    expect(createCsFaqArticleMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="cs-article-tip"]').text()).toContain('标题')

    await wrapper.find('[data-testid="cs-article-form-title"]').setValue('只有标题')
    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()
    expect(createCsFaqArticleMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="cs-article-tip"]').text()).toContain('正文')
  })

  it('保存成功后回到列表页并带上所属栏目', async () => {
    const { wrapper, router } = await mountPage('/cs/faq/articles/new?category_id=1')
    await wrapper.find('[data-testid="cs-article-form-title"]').setValue('新文章')
    await wrapper.find('[data-testid="cs-article-form-content"]').setValue('正文')
    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('cs-faq')
    expect(router.currentRoute.value.query.category_id).toBe('1')
  })

  it('「返回」不提交也不丢上下文，直接回到列表页', async () => {
    const { wrapper, router } = await mountPage('/cs/faq/articles/1/edit')
    await wrapper.find('[data-testid="cs-article-back"]').trigger('click')
    await flushPromises()

    expect(updateCsFaqArticleMock).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('cs-faq')
  })
})
