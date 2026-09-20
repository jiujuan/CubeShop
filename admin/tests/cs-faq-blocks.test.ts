import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * 内容管理页 · 区块编辑器（CMS-203，AC-203.1）
 *
 * 关注点：区块模板走区块编排（增删/上下移/拖拽/保存 blocks），固定模板一行不受影响。
 * 后端 schema 真源已在 CmsBlockTest / CmsPageApiTest 覆盖，这里只验证后台的交互与接线。
 */
const {
  getCsFaqCategoriesMock, getCsFaqArticlesMock, getCsFaqPageMock, saveCsFaqPageMock,
  getCsFaqPageTemplatesMock, getCsFaqPageBlocksMock, saveCsFaqPageBlocksMock, uploadCmsImageMock,
} = vi.hoisted(() => ({
  getCsFaqCategoriesMock: vi.fn(),
  getCsFaqArticlesMock: vi.fn(),
  getCsFaqPageMock: vi.fn(),
  saveCsFaqPageMock: vi.fn(),
  getCsFaqPageTemplatesMock: vi.fn(),
  getCsFaqPageBlocksMock: vi.fn(),
  saveCsFaqPageBlocksMock: vi.fn(),
  uploadCmsImageMock: vi.fn(),
}))

vi.mock('@/api/cs', () => ({
  getCsFaqCategories: getCsFaqCategoriesMock,
  getCsFaqArticles: getCsFaqArticlesMock,
  createCsFaqCategory: vi.fn(),
  updateCsFaqCategory: vi.fn(),
  deleteCsFaqCategory: vi.fn(),
  sortCsFaqCategories: vi.fn(),
  moveCsFaqCategory: vi.fn(),
  createCsFaqArticle: vi.fn(),
  updateCsFaqArticle: vi.fn(),
  deleteCsFaqArticle: vi.fn(),
  publishCsFaqArticle: vi.fn(),
  offlineCsFaqArticle: vi.fn(),
  previewCsFaqArticle: vi.fn(),
  getCsFaqPage: getCsFaqPageMock,
  saveCsFaqPage: saveCsFaqPageMock,
  getCsFaqPageTemplates: getCsFaqPageTemplatesMock,
  getCsFaqPageBlocks: getCsFaqPageBlocksMock,
  saveCsFaqPageBlocks: saveCsFaqPageBlocksMock,
  uploadCmsImage: uploadCmsImageMock,
}))

vi.mock('@/api/product', () => ({ uploadImage: vi.fn() }))

// md-editor-v3 内部是 CodeMirror 6，jsdom 下跑不起来 → 与 cs-faq.test.ts 同一套轻量桩
vi.mock('md-editor-v3', async () => {
  const { defineComponent, h } = await import('vue')

  return {
    MdEditor: defineComponent({
      name: 'MdEditor',
      props: {
        modelValue: { type: String, default: '' },
        onUploadImg: { type: Function, default: null },
      },
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

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia, createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/cs/faq', component: CsFaqView }],
  })],
  directives: { permission },
})

/** 区块模板单页（brand-story） */
const blocksCategory = {
  id: 9, name: '品牌故事', sort: 1, is_active: true, articles_count: 0, published_count: 0,
  parent_id: 0, level: 1, path: '/9/', type: 'page', slug: 'brand-story', template: 'blocks',
  show_in_nav: false, icon: null, children: [],
  seo_title: null, seo_keywords: null, seo_description: null,
}

/** 后端 CmsBlock::options() 的最小投影（只保留测试用得到的字段） */
const BLOCK_OPTIONS = [
  { key: 'hero', label: '首屏横幅', description: '', fields: [
    { key: 'title', label: '主标题', type: 'text', required: true },
    { key: 'subtitle', label: '副标题', type: 'textarea' },
  ] },
  { key: 'rich_text', label: '富文本', description: '', fields: [
    { key: 'title', label: '标题', type: 'text' },
    { key: 'body', label: '正文', type: 'markdown', required: true },
  ] },
  { key: 'gallery', label: '图集', description: '', fields: [
    { key: 'images', label: '图片', type: 'image_list', required: true },
  ] },
  { key: 'text_image', label: '图文分栏', description: '', fields: [
    { key: 'text', label: '正文', type: 'markdown', required: true },
    { key: 'side', label: '图片位置', type: 'select', default: 'left',
      options: [{ value: 'left', label: '图片在左' }, { value: 'right', label: '图片在右' }] },
  ] },
  { key: 'faq_embed', label: '帮助中心嵌入', description: '', fields: [
    { key: 'category_id', label: '选取栏目', type: 'channels' },
    { key: 'limit', label: '显示条数', type: 'select', default: '5',
      options: [{ value: '3', label: '3 条' }, { value: '5', label: '5 条' }] },
  ] },
]

/**
 * 模拟后端 `showPage` 的归一化（`CmsBlock::filterPayload`）：按 schema 补齐缺失字段的默认值。
 * 真实接口下发的 data 一定是「满键」的，mock 也要照这个契约来，否则测试会验证到假状态。
 */
function normalizeBlocks(blocks: Array<{ type: string; data?: Record<string, unknown> }>) {
  return blocks.map((block) => {
    const fields = (BLOCK_OPTIONS.find((o) => o.key === block.type)?.fields ?? []) as
      Array<{ key: string; type: string; default?: unknown }>

    const data: Record<string, unknown> = {}
    for (const field of fields) {
      const empty = field.type === 'image_list' || field.type === 'repeater' ? [] : ''
      data[field.key] = block.data?.[field.key] ?? field.default ?? empty
    }

    return { type: block.type, data }
  })
}

function mockBlocksPage(blocks: Array<{ type: string; data?: Record<string, unknown> }> = []) {
  getCsFaqPageMock.mockResolvedValue({ data: { data: {
    category: { id: 9, name: '品牌故事', slug: 'brand-story', template: 'blocks', is_active: true },
    template: { key: 'blocks', label: '自由区块', fields: [], is_blocks: true },
    values: {},
    blocks: normalizeBlocks(blocks),
    updated_at: '2026-09-20 10:00:00',
  } } })
}

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getCsFaqCategoriesMock.mockResolvedValue({ data: { data: [blocksCategory] } })
  getCsFaqArticlesMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 15, total: 0, total_pages: 1 } } } })
  getCsFaqPageTemplatesMock.mockResolvedValue({ data: { data: [{ key: 'about', label: '关于我们' }] } })
  getCsFaqPageBlocksMock.mockResolvedValue({ data: { data: BLOCK_OPTIONS } })
  saveCsFaqPageBlocksMock.mockResolvedValue({ data: { code: 0 } })
  mockBlocksPage()
})

async function mountView() {
  const wrapper = mount(CsFaqView, { global: globalCfg(freshPinia()) })
  await flushPromises()
  return wrapper
}

/** 取卡片内某个字段的表单控件（各区块字段 key 会重复，必须限定在卡片内查） */
const inBlock = (wrapper: Awaited<ReturnType<typeof mountView>>, index: number, testid: string) =>
  wrapper.find(`[data-testid="page-block-${index}"] [data-testid="${testid}"]`)

describe('内容管理页 · 区块编辑器（CMS-203）', () => {
  it('区块模板渲染区块编辑器（而非固定字段表单），并按顺序渲染每一块', async () => {
    mockBlocksPage([
      { type: 'hero', data: { title: '第一块', subtitle: '' } },
      { type: 'rich_text', data: { title: '', body: '第二块' } },
    ])
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="page-block-editor"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="page-block-list"] > div')).toHaveLength(2)

    // 每块的表单按**该块自己的** schema 渲染，不串味
    expect(inBlock(wrapper, 0, 'page-field-input-title').exists()).toBe(true)
    expect(inBlock(wrapper, 0, 'page-field-markdown-body').exists()).toBe(false)
    expect(inBlock(wrapper, 1, 'page-field-markdown-body').exists()).toBe(true)

    // 区块库随区块模板一并拉取（真源在后端）
    expect(getCsFaqPageBlocksMock).toHaveBeenCalledTimes(1)
  })

  it('添加区块：按所选类型追加到末尾，数据带该块 schema 的默认值', async () => {
    const wrapper = await mountView()
    expect(wrapper.find('[data-testid="page-block-empty"]').exists()).toBe(true)

    await wrapper.find('[data-testid="page-block-add-type"]').setValue('text_image')
    await wrapper.find('[data-testid="page-block-add"]').trigger('click')

    expect(wrapper.findAll('[data-testid="page-block-list"] > div')).toHaveLength(1)
    // select 字段的默认值来自后端 schema（left），前端不硬编码
    expect((inBlock(wrapper, 0, 'page-field-select-side').element as HTMLSelectElement).value).toBe('left')
  })

  it('上移/下移按钮交换区块顺序', async () => {
    mockBlocksPage([
      { type: 'hero', data: { title: 'A' } },
      { type: 'rich_text', data: { body: 'B' } },
    ])
    const wrapper = await mountView()

    await wrapper.find('[data-testid="page-block-down-0"]').trigger('click')
    await flushPromises()

    expect(inBlock(wrapper, 0, 'page-field-markdown-body').exists()).toBe(true)
    expect(inBlock(wrapper, 1, 'page-field-input-title').exists()).toBe(true)
  })

  it('删除按钮移除对应区块', async () => {
    mockBlocksPage([
      { type: 'hero', data: { title: 'A' } },
      { type: 'rich_text', data: { body: 'B' } },
    ])
    const wrapper = await mountView()

    await wrapper.find('[data-testid="page-block-remove-0"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-testid="page-block-list"] > div')).toHaveLength(1)
    expect(inBlock(wrapper, 0, 'page-field-markdown-body').exists()).toBe(true)
  })

  it('拖拽排序：把第 1 块拖到第 2 块位置', async () => {
    mockBlocksPage([
      { type: 'hero', data: { title: 'A' } },
      { type: 'rich_text', data: { body: 'B' } },
    ])
    const wrapper = await mountView()

    await wrapper.find('[data-testid="page-block-drag-0"]').trigger('dragstart')
    await wrapper.find('[data-testid="page-block-1"]').trigger('drop')
    await flushPromises()

    // 原第 0 块（hero）落到了第 1 位
    expect(inBlock(wrapper, 1, 'page-field-input-title').exists()).toBe(true)
    expect(inBlock(wrapper, 0, 'page-field-markdown-body').exists()).toBe(true)
  })

  it('保存区块模板走 blocks 载体，不误调固定字段接口', async () => {
    mockBlocksPage([{ type: 'hero', data: { title: '标题' } }])
    const wrapper = await mountView()

    await wrapper.find('[data-testid="cs-page-save"]').trigger('click')
    await flushPromises()

    expect(saveCsFaqPageBlocksMock).toHaveBeenCalledTimes(1)
    expect(saveCsFaqPageBlocksMock.mock.calls[0][0]).toBe(9)
    expect(saveCsFaqPageBlocksMock.mock.calls[0][1]).toEqual([{ type: 'hero', data: { title: '标题', subtitle: '' } }])
    expect(saveCsFaqPageMock).not.toHaveBeenCalled()
  })

  it('固定模板单页不渲染区块编辑器，也不拉区块库（零回归）', async () => {
    getCsFaqCategoriesMock.mockResolvedValue({ data: { data: [
      { ...blocksCategory, id: 5, name: '关于我们', slug: 'about', template: 'about' },
    ] } })
    getCsFaqPageMock.mockResolvedValue({ data: { data: {
      category: { id: 5, name: '关于我们', slug: 'about', template: 'about', is_active: true },
      template: { key: 'about', label: '关于我们', fields: [
        { key: 'intro', label: '公司简介', type: 'markdown', required: true },
      ], is_blocks: false },
      values: { intro: '正文' },
      blocks: [],
      updated_at: null,
    } } })

    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="page-block-editor"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="page-field-markdown-intro"]').exists()).toBe(true)
    expect(getCsFaqPageBlocksMock).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="cs-page-save"]').trigger('click')
    await flushPromises()

    expect(saveCsFaqPageMock).toHaveBeenCalledWith(5, { intro: '正文' })
    expect(saveCsFaqPageBlocksMock).not.toHaveBeenCalled()
  })

  it('channels 字段列出栏目名（不含单页），提交的是栏目 id 字符串', async () => {
    getCsFaqCategoriesMock.mockResolvedValue({ data: { data: [
      { ...blocksCategory, id: 9, name: '品牌故事', slug: 'brand-story', template: 'blocks' },
      { ...blocksCategory, id: 20, name: '售后栏目', type: 'channel', slug: null, template: null, level: 2 },
    ] } })
    mockBlocksPage([{ type: 'faq_embed', data: { category_id: '' } }])
    const wrapper = await mountView()

    // 存在非单页栏目时默认选中它，需显式点回区块化单页
    await wrapper.find('[data-testid="cs-faq-category-row-9"]').trigger('click')
    await flushPromises()

    const select = inBlock(wrapper, 0, 'page-field-channels-category_id')
    const labels = select.findAll('option').map((o) => o.text())

    expect(labels).toContain('售后栏目')
    // 单页（品牌故事）不进候选：后端 faqEmbedItems 对单页只会返回空列表
    expect(labels).not.toContain('品牌故事')

    await select.setValue('20')
    await wrapper.find('[data-testid="cs-page-save"]').trigger('click')
    await flushPromises()

    expect(saveCsFaqPageBlocksMock.mock.calls[0][1][0].data.category_id).toBe('20')
  })
})
