import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const {
  getCsFaqCategoriesMock, getCsFaqArticlesMock, createCsFaqCategoryMock, updateCsFaqCategoryMock,
  deleteCsFaqCategoryMock, sortCsFaqCategoriesMock, moveCsFaqCategoryMock, createCsFaqArticleMock,
  updateCsFaqArticleMock, deleteCsFaqArticleMock, publishCsFaqArticleMock, offlineCsFaqArticleMock,
  previewCsFaqArticleMock, getCsFaqPageMock, saveCsFaqPageMock, getCsFaqPageTemplatesMock,
  uploadCmsImageMock, uploadImageMock,
} = vi.hoisted(() => ({
  getCsFaqCategoriesMock: vi.fn(),
  getCsFaqArticlesMock: vi.fn(),
  createCsFaqCategoryMock: vi.fn(),
  updateCsFaqCategoryMock: vi.fn(),
  deleteCsFaqCategoryMock: vi.fn(),
  sortCsFaqCategoriesMock: vi.fn(),
  moveCsFaqCategoryMock: vi.fn(),
  createCsFaqArticleMock: vi.fn(),
  updateCsFaqArticleMock: vi.fn(),
  deleteCsFaqArticleMock: vi.fn(),
  publishCsFaqArticleMock: vi.fn(),
  offlineCsFaqArticleMock: vi.fn(),
  previewCsFaqArticleMock: vi.fn(),
  getCsFaqPageMock: vi.fn(),
  saveCsFaqPageMock: vi.fn(),
  getCsFaqPageTemplatesMock: vi.fn(),
  uploadCmsImageMock: vi.fn(),
  uploadImageMock: vi.fn(),
}))

vi.mock('@/api/cs', () => ({
  getCsFaqCategories: getCsFaqCategoriesMock,
  getCsFaqArticles: getCsFaqArticlesMock,
  createCsFaqCategory: createCsFaqCategoryMock,
  updateCsFaqCategory: updateCsFaqCategoryMock,
  deleteCsFaqCategory: deleteCsFaqCategoryMock,
  sortCsFaqCategories: sortCsFaqCategoriesMock,
  moveCsFaqCategory: moveCsFaqCategoryMock,
  createCsFaqArticle: createCsFaqArticleMock,
  updateCsFaqArticle: updateCsFaqArticleMock,
  deleteCsFaqArticle: deleteCsFaqArticleMock,
  publishCsFaqArticle: publishCsFaqArticleMock,
  offlineCsFaqArticle: offlineCsFaqArticleMock,
  previewCsFaqArticle: previewCsFaqArticleMock,
  getCsFaqPage: getCsFaqPageMock,
  saveCsFaqPage: saveCsFaqPageMock,
  getCsFaqPageTemplates: getCsFaqPageTemplatesMock,
  // CMS-203 区块接口：本文件不测区块，但组件 import 了就必须提供（否则访问即抛错）
  getCsFaqPageBlocks: vi.fn(),
  saveCsFaqPageBlocks: vi.fn(),
  uploadCmsImage: uploadCmsImageMock,
}))

vi.mock('@/api/product', () => ({ uploadImage: uploadImageMock }))

// md-editor-v3 内部是 CodeMirror 6，jsdom 下跑不起来 → 用轻量桩替换
vi.mock('md-editor-v3', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    MdEditor: defineComponent({
      name: 'MdEditor',
      props: { modelValue: { type: String, default: '' }, onUploadImg: { type: Function, default: null } },
      emits: ['update:modelValue'],
      setup(props, { emit }) {
        return () => h('textarea', {
          'data-testid': 'md-editor-stub',
          value: props.modelValue,
          onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLTextAreaElement).value),
        })
      },
    }),
  }
})

import CsFaqView from '@/views/cs/CsFaqView.vue'

function freshPinia(permissions: string[] = ['cs.faq.manage']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'operator', nickname: null, avatar: null, phone: null, email: null,
    roles: ['operator'], permissions,
  }
  return pinia
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/cs/faq', component: CsFaqView }],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia, makeRouter()],
  directives: { permission },
})

// 注意：CsFaqCategoryRow 现已含 list_style 字段，mock 必须带上以免 undefined 漂移
const category = (o: Record<string, unknown> = {}) => ({
  id: 1, name: '售后政策', sort: 1, is_active: true, articles_count: 2, published_count: 1,
  parent_id: 0, level: 1, path: '/1/', type: 'channel', slug: null, template: null,
  list_style: 'list', show_in_nav: false, icon: null, children: [],
  seo_title: null, seo_keywords: null, seo_description: null, ...o,
})
const article = (o: Record<string, unknown> = {}) => ({
  id: 1, category_id: 1, category: { id: 1, name: '售后政策' }, title: '如何退货', summary: '',
  cover_image: null, content_md: '正文', content: '<p>正文</p>', status: 'draft', sort: 0, is_hot: false,
  view_count: 0, helpful_count: 0, unhelpful_count: 0, helpful_rate: null, created_at: '2026-09-17 10:00:00', ...o,
})
const pageCategory = (o: Record<string, unknown> = {}) => category({
  id: 9, name: '关于我们', type: 'page', slug: 'about', template: 'about',
  articles_count: 0, published_count: 0, ...o,
})

function mockArticles(list: unknown[]) {
  getCsFaqArticlesMock.mockResolvedValue({ data: { data: { list, pagination: { page: 1, page_size: 15, total: list.length, total_pages: 1 } } } })
}

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getCsFaqCategoriesMock.mockResolvedValue({ data: { data: [category()] } })
  getCsFaqPageTemplatesMock.mockResolvedValue({ data: { data: [
    { key: 'about', label: '关于我们' }, { key: 'contact', label: '联系我们' },
  ] } })
  mockArticles([article()])
  createCsFaqCategoryMock.mockResolvedValue({ data: { data: category() } })
  updateCsFaqCategoryMock.mockResolvedValue({ data: { data: category() } })
  createCsFaqArticleMock.mockResolvedValue({ data: { data: article() } })
  updateCsFaqArticleMock.mockResolvedValue({ data: { data: article() } })
})

async function mountView(permissions?: string[]) {
  const wrapper = mount(CsFaqView, { global: globalCfg(freshPinia(permissions)) })
  await flushPromises()
  return wrapper
}

describe('CMS 新闻中心：后台栏目形态 + 文章封面（news-cms）', () => {
  it('channel 栏目编辑弹窗显示「列表形态」下拉且默认 list', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-cat-edit-1"]').trigger('click')
    await flushPromises()

    const sel = wrapper.find('[data-testid="cs-category-list-style"]')
    expect(sel.exists()).toBe(true)
    expect((sel.element as HTMLSelectElement).value).toBe('list')
    // 两个选项：list / card
    expect(sel.findAll('option').map((o) => o.attributes('value'))).toEqual(['list', 'card'])
  })

  it('单页栏目编辑弹窗不显示「列表形态」下拉', async () => {
    getCsFaqCategoriesMock.mockResolvedValue({ data: { data: [pageCategory()] } })
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-cat-edit-9"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-category-list-style"]').exists()).toBe(false)
  })

  it('保存栏目时把选中的列表形态提交给后端', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-cat-edit-1"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="cs-category-list-style"]').setValue('card')
    await wrapper.find('[data-testid="cs-category-save"]').trigger('click')
    await flushPromises()

    expect(updateCsFaqCategoryMock).toHaveBeenCalledWith(1, expect.objectContaining({ list_style: 'card' }))
  })

  it('新增栏目提交时携带默认 list_style', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-cat-create"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="cs-category-name"]').setValue('图文新闻')
    await wrapper.find('[data-testid="cs-category-save"]').trigger('click')
    await flushPromises()

    expect(createCsFaqCategoryMock).toHaveBeenCalledWith(expect.objectContaining({ list_style: 'list' }))
  })

  it('文章编辑弹窗上传封面图后随保存提交 cover_image', async () => {
    uploadCmsImageMock.mockResolvedValue({ data: { data: { url: 'http://x/cover.png' } } })
    const wrapper = await mountView()

    await wrapper.find('[data-testid="cs-article-edit-1"]').trigger('click')
    await flushPromises()

    // 封面预览初始不存在
    expect(wrapper.find('[data-testid="cs-article-form-cover-preview"]').exists()).toBe(false)

    const file = new File(['img'], 'cover.png', { type: 'image/png' })
    const input = wrapper.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await flushPromises()

    // 上传成功后预览出现
    expect(uploadCmsImageMock).toHaveBeenCalledWith(file)
    expect(wrapper.find('[data-testid="cs-article-form-cover-preview"]').exists()).toBe(true)

    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()

    expect(updateCsFaqArticleMock).toHaveBeenCalledWith(1, expect.objectContaining({ cover_image: 'http://x/cover.png' }))
  })

  it('文章列表提供「所属栏目」筛选下拉', async () => {
    getCsFaqCategoriesMock.mockResolvedValue({ data: { data: [
      category({ id: 1, name: '图文新闻' }),
      category({ id: 2, name: '列表新闻' }),
    ] } })
    const wrapper = await mountView()

    const sel = wrapper.find('[data-testid="cs-article-category-filter"]')
    expect(sel.exists()).toBe(true)
    expect(sel.findAll('option').map((o) => o.text())).toEqual(['图文新闻', '列表新闻'])
  })
})
