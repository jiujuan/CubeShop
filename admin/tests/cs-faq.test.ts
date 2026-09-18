import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const {
  getCsFaqCategoriesMock, getCsFaqArticlesMock, createCsFaqCategoryMock, updateCsFaqCategoryMock,
  deleteCsFaqCategoryMock, sortCsFaqCategoriesMock, createCsFaqArticleMock, updateCsFaqArticleMock,
  deleteCsFaqArticleMock, publishCsFaqArticleMock, offlineCsFaqArticleMock, previewCsFaqArticleMock,
  uploadImageMock,
} = vi.hoisted(() => ({
  getCsFaqCategoriesMock: vi.fn(),
  getCsFaqArticlesMock: vi.fn(),
  createCsFaqCategoryMock: vi.fn(),
  updateCsFaqCategoryMock: vi.fn(),
  deleteCsFaqCategoryMock: vi.fn(),
  sortCsFaqCategoriesMock: vi.fn(),
  createCsFaqArticleMock: vi.fn(),
  updateCsFaqArticleMock: vi.fn(),
  deleteCsFaqArticleMock: vi.fn(),
  publishCsFaqArticleMock: vi.fn(),
  offlineCsFaqArticleMock: vi.fn(),
  previewCsFaqArticleMock: vi.fn(),
  uploadImageMock: vi.fn(),
}))

vi.mock('@/api/cs', () => ({
  getCsFaqCategories: getCsFaqCategoriesMock,
  getCsFaqArticles: getCsFaqArticlesMock,
  createCsFaqCategory: createCsFaqCategoryMock,
  updateCsFaqCategory: updateCsFaqCategoryMock,
  deleteCsFaqCategory: deleteCsFaqCategoryMock,
  sortCsFaqCategories: sortCsFaqCategoriesMock,
  createCsFaqArticle: createCsFaqArticleMock,
  updateCsFaqArticle: updateCsFaqArticleMock,
  deleteCsFaqArticle: deleteCsFaqArticleMock,
  publishCsFaqArticle: publishCsFaqArticleMock,
  offlineCsFaqArticle: offlineCsFaqArticleMock,
  previewCsFaqArticle: previewCsFaqArticleMock,
}))

vi.mock('@/api/product', () => ({ uploadImage: uploadImageMock }))

// md-editor-v3 内部是 CodeMirror 6，jsdom 下跑不起来 → 用轻量桩替换：
// 桩只保留「v-model 双向绑定」与「onUploadImg 回调」两个我们真正依赖的契约。
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

const category = (o: Record<string, unknown> = {}) => ({
  id: 1, name: '售后政策', sort: 1, is_active: true, articles_count: 2, published_count: 1, ...o,
})
const article = (o: Record<string, unknown> = {}) => ({
  id: 1, category_id: 1, category: { id: 1, name: '售后政策' }, title: '如何退货', summary: '',
  content_md: '正文', content: '<p>正文</p>', status: 'draft', sort: 0, is_hot: false, view_count: 0,
  helpful_count: 0, unhelpful_count: 0, helpful_rate: null, created_at: '2026-09-17 10:00:00', ...o,
})

function mockArticles(list: unknown[]) {
  getCsFaqArticlesMock.mockResolvedValue({ data: { data: { list, pagination: { page: 1, page_size: 15, total: list.length, total_pages: 1 } } } })
}

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getCsFaqCategoriesMock.mockResolvedValue({ data: { data: [category()] } })
  mockArticles([article()])
})

async function mountView(permissions?: string[]) {
  const wrapper = mount(CsFaqView, { global: globalCfg(freshPinia(permissions)) })
  await flushPromises()
  return wrapper
}

describe('FAQ 管理页 CsFaqView（CS-115）', () => {
  it('无 cs.faq.manage 时隐藏所有写操作入口', async () => {
    const wrapper = await mountView([])
    expect(wrapper.find('[data-testid="cs-article-create"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="cs-article-edit-1"]').exists()).toBe(false)

    await wrapper.find('[data-testid="cs-faq-tab-categories"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="cs-cat-create"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="cs-cat-delete-1"]').exists()).toBe(false)
  })

  it('文章状态标签（草稿/已发布/已下架）渲染正确', async () => {
    mockArticles([
      article({ id: 1, status: 'draft' }),
      article({ id: 2, status: 'published' }),
      article({ id: 3, status: 'offline' }),
    ])
    const wrapper = await mountView()
    expect(wrapper.find('[data-testid="cs-article-status-1"]').text()).toBe('草稿')
    expect(wrapper.find('[data-testid="cs-article-status-2"]').text()).toBe('已发布')
    expect(wrapper.find('[data-testid="cs-article-status-3"]').text()).toBe('已下架')
  })

  it('有帮助率分母为 0 时展示「—」', async () => {
    mockArticles([article({ id: 1, helpful_count: 0, unhelpful_count: 0, helpful_rate: null })])
    const wrapper = await mountView()
    expect(wrapper.find('[data-testid="cs-article-rate-1"]').text()).toBe('—')
  })

  it('有帮助率有数据时展示百分比', async () => {
    mockArticles([article({ id: 1, helpful_count: 3, unhelpful_count: 1, helpful_rate: 0.75 })])
    const wrapper = await mountView()
    expect(wrapper.find('[data-testid="cs-article-rate-1"]').text()).toBe('75%')
  })

  it('分类删除被拒时展示后端返回的冲突原因', async () => {
    deleteCsFaqCategoryMock.mockRejectedValue(new Error('该分类下存在已发布文章，请先下架或迁移后再删除'))
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-faq-tab-categories"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="cs-cat-delete-1"]').trigger('click')
    await flushPromises()
    const confirmBtn = document.body.querySelector('[data-testid="confirm-ok"]') as HTMLButtonElement
    confirmBtn.click()
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-faq-tip"]').text()).toContain('已发布文章')
  })

  it('发布操作触发二次确认并调用发布接口', async () => {
    publishCsFaqArticleMock.mockResolvedValue({ data: { data: article({ status: 'published' }) } })
    const wrapper = await mountView()

    await wrapper.find('[data-testid="cs-article-publish-1"]').trigger('click')
    await flushPromises()
    const confirmBtn = document.body.querySelector('[data-testid="confirm-ok"]') as HTMLButtonElement
    confirmBtn.click()
    await flushPromises()

    expect(publishCsFaqArticleMock).toHaveBeenCalledWith(1)
    expect(getCsFaqArticlesMock.mock.calls.length).toBeGreaterThanOrEqual(2)
  })

  it('下架操作触发二次确认并调用下架接口', async () => {
    mockArticles([article({ id: 1, status: 'published' })])
    offlineCsFaqArticleMock.mockResolvedValue({ data: { data: article({ status: 'offline' }) } })
    const wrapper = await mountView()

    await wrapper.find('[data-testid="cs-article-offline-1"]').trigger('click')
    await flushPromises()
    const confirmBtn = document.body.querySelector('[data-testid="confirm-ok"]') as HTMLButtonElement
    confirmBtn.click()
    await flushPromises()

    expect(offlineCsFaqArticleMock).toHaveBeenCalledWith(1)
  })

  it('预览弹窗按富文本渲染正文（不是字面量标签）', async () => {
    previewCsFaqArticleMock.mockResolvedValue({ data: { data: {
      title: '如何退货', content: '<p>第一步：查订单</p><ul><li>商品完好</li></ul>',
      category_name: '售后政策', status: 'published',
    } } })
    const wrapper = await mountView()

    await wrapper.find('[data-testid="cs-article-preview-1"]').trigger('click')
    await flushPromises()

    const body = wrapper.find('[data-testid="cs-article-preview-content"]')
    expect(body.find('p').text()).toBe('第一步：查订单')
    expect(body.findAll('li')).toHaveLength(1)
    expect(body.text()).not.toContain('<p>')
  })

  // ---------- markdown 编辑器（缺陷 #1 改造） ----------

  it('正文编辑器是 markdown 编辑器，且绑定 content_md（不再是 HTML textarea + 标签工具条）', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-article-create"]').trigger('click')
    await flushPromises()

    // 旧实现的两个标志物都不应再出现
    expect(wrapper.find('[data-testid="cs-article-toolbar"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="cs-article-tb-段落"]').exists()).toBe(false)

    // markdown 编辑器已在位（真实组件；测试环境里用同契约的桩替代）
    expect(wrapper.findComponent({ name: 'MdEditor' }).exists()).toBe(true)

    // 编辑器已在位，且输入会写进表单的 content_md
    const editor = wrapper.find('[data-testid="cs-article-form-content"]')
    expect(editor.exists()).toBe(true)
    await editor.setValue('## 小节标题')
    await flushPromises()

    createCsFaqArticleMock.mockResolvedValue({ data: { data: article() } })
    await wrapper.find('[data-testid="cs-article-form-title"]').setValue('新文章')
    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()

    expect(createCsFaqArticleMock).toHaveBeenCalledWith(
      expect.objectContaining({ title: '新文章', content_md: '## 小节标题' }),
    )
    // 不再发送 content（HTML 由后端渲染派生）
    expect(createCsFaqArticleMock.mock.calls[0][0]).not.toHaveProperty('content')
  })

  it('打开已有文章时用 content_md 回显（markdown 源，不是 HTML 产物）', async () => {
    mockArticles([article({ id: 1, content_md: '## 退货步骤', content: '<h2>退货步骤</h2>' })])
    const wrapper = await mountView()

    await wrapper.find('[data-testid="cs-article-edit-1"]').trigger('click')
    await flushPromises()

    const editor = wrapper.find('[data-testid="cs-article-form-content"]')
    expect((editor.element as HTMLTextAreaElement).value).toBe('## 退货步骤')

    updateCsFaqArticleMock.mockResolvedValue({ data: { data: article() } })
    await wrapper.find('[data-testid="cs-article-save"]').trigger('click')
    await flushPromises()

    expect(updateCsFaqArticleMock).toHaveBeenCalledWith(1, expect.objectContaining({ content_md: '## 退货步骤' }))
  })

  it('存量未迁移的行（content_md 为空）退回 HTML 产物兜底，不至于打开就是空白', async () => {
    mockArticles([article({ id: 1, content_md: null, content: '<p>历史正文</p>' })])
    const wrapper = await mountView()

    await wrapper.find('[data-testid="cs-article-edit-1"]').trigger('click')
    await flushPromises()

    const editor = wrapper.find('[data-testid="cs-article-form-content"]')
    expect((editor.element as HTMLTextAreaElement).value).toBe('<p>历史正文</p>')
  })

  it('图片上传走后台统一上传接口，并回填 url 给编辑器', async () => {
    uploadImageMock.mockResolvedValue({ data: { data: { url: '/storage/uploads/a.png' } } })
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-article-create"]').trigger('click')
    await flushPromises()

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

  it('图片上传失败时回调空数组并提示，不打断编辑', async () => {
    uploadImageMock.mockRejectedValue(new Error('文件过大'))
    const wrapper = await mountView()
    await wrapper.find('[data-testid="cs-article-create"]').trigger('click')
    await flushPromises()

    const handler = wrapper.findComponent({ name: 'MdEditor' }).props('onUploadImg') as
      (files: File[], cb: (urls: Array<{ url: string; alt: string; title: string }>) => void) => Promise<void>

    const callback = vi.fn()
    await handler([new File(['x'], 'big.png', { type: 'image/png' })], callback)
    await flushPromises()

    expect(callback).toHaveBeenCalledWith([])
    expect(wrapper.find('[data-testid="cs-faq-tip"]').text()).toContain('文件过大')
  })
})
