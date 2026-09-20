import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

/**
 * 区块化单页前台渲染（CMS-203，AC-203.2 / AC-203.3）
 *
 * 关注点：区块按后台编排顺序渲染、缺字段优雅降级、未知区块被跳过、
 * 以及「区块内容全部来自单次 /cms/pages 响应」—— faq_embed 的列表由后端带出，前台零请求。
 */
const { getCmsPageMock, getCmsNavMock, getCategoriesMock, getCartCountMock, getUnreadCountMock, getAnnouncementsMock } = vi.hoisted(() => ({
  getCmsPageMock: vi.fn(),
  getCmsNavMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getCartCountMock: vi.fn(),
  getUnreadCountMock: vi.fn(),
  getAnnouncementsMock: vi.fn(),
}))

vi.mock('@/api/cms', () => ({ getCmsPage: getCmsPageMock, getCmsNav: getCmsNavMock }))
vi.mock('@/api/shop', () => ({ getCategories: getCategoriesMock, getProducts: vi.fn(), getProduct: vi.fn(), getHot: vi.fn() }))
vi.mock('@/api/user', () => ({ getCartCount: getCartCountMock, getProfile: vi.fn(), addToCart: vi.fn(), getCart: vi.fn(), uploadImage: vi.fn() }))
vi.mock('@/api/notification', () => ({ getNotifications: vi.fn(), getUnreadCount: getUnreadCountMock, markNotificationsRead: vi.fn() }))
vi.mock('@/api/announcement', () => ({ getAnnouncements: getAnnouncementsMock, getAnnouncement: vi.fn() }))
// 帮助中心文章接口：区块化单页理应一次都不调（列表由后端随单页响应带出）
const { getFaqArticlesMock } = vi.hoisted(() => ({ getFaqArticlesMock: vi.fn() }))
vi.mock('@/api/cs', () => ({ getFaqArticles: getFaqArticlesMock, getFaqCategories: vi.fn() }))

import PageView from '@/views/PageView.vue'
import { useAuthStore } from '@/stores/auth'
import type { CmsPageBlock, CmsPageContent } from '@/api/cms'

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().token = ''
  return pinia
}

async function renderPage(slug = 'brand-story') {
  const pinia = freshPinia()
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/register', component: { template: '<div />' } },
      { path: '/cart', component: { template: '<div />' } },
      { path: '/p/:slug', component: PageView },
      { path: '/service-center/faq', component: { template: '<div />' } },
      { path: '/service-center/faq/:id', component: { template: '<div />' } },
    ],
  })
  router.push(`/p/${slug}`)
  await router.isReady()
  return render(PageView, { global: { plugins: [pinia, router] } })
}

/** 造一个区块（后端下发的形状：type + data + html + items） */
function block(type: string, data: Record<string, unknown> = {}, extra: Partial<CmsPageBlock> = {}): CmsPageBlock {
  return { type, data, html: {}, items: [], ...extra }
}

function mockPage(blocks: CmsPageBlock[]) {
  const page: CmsPageContent = {
    name: '品牌故事', slug: 'brand-story', template: 'blocks',
    fields: {}, html: {}, blocks,
    seo: { title: '品牌故事', keywords: '', description: '' },
    updated_at: '2026-09-21 10:00:00',
  }
  getCmsPageMock.mockResolvedValue({ data: { data: page } })
}

beforeEach(() => {
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({ data: { data: [] } })
  getCartCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getUnreadCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  getAnnouncementsMock.mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 10, total: 0, total_pages: 1 } } } })
})

describe('区块化单页 PageBlocks（CMS-203）', () => {
  it('按后台编排顺序渲染各类区块', async () => {
    mockPage([
      block('hero', { title: '品牌主张', subtitle: '更好用的商城', image: '', button_text: '', button_link: '' }),
      block('text_image', { title: '我们做什么', text: '正文', image: '/a.png', side: 'left' }, { html: { text: '<p>正文</p>' } }),
      block('gallery', { title: '实拍', images: ['/1.png', '/2.png'] }),
      block('rich_text', { title: '结尾', body: '尾部', }, { html: { body: '<p>尾部</p>' } }),
    ])

    await renderPage()
    // 区块组件是异步加载的：等到四块都挂载再断言
    await waitFor(() => {
      for (const testid of ['block-hero', 'block-text-image', 'block-gallery', 'block-rich-text']) {
        expect(screen.getByTestId(testid)).toBeTruthy()
      }
    })

    expect(screen.getByTestId('block-hero-title').textContent).toBe('品牌主张')
    expect(screen.getByTestId('block-text-image').textContent).toContain('我们做什么')
    expect(screen.getAllByTestId(/block-gallery-image-/)).toHaveLength(2)
    expect(screen.getByTestId('block-rich-text-body').innerHTML).toContain('<p>尾部</p>')

    // 顺序由 blocks 数组决定（DOM 里 hero 在 rich_text 之前）
    const container = screen.getByTestId('page-blocks')
    const testids = Array.from(container.querySelectorAll('[data-testid^="block-"]'))
      .map((el) => el.getAttribute('data-testid'))
      .filter((id) => ['block-hero', 'block-text-image', 'block-gallery', 'block-rich-text'].includes(id ?? ''))
    expect(testids).toEqual(['block-hero', 'block-text-image', 'block-gallery', 'block-rich-text'])
  })

  it('缺字段时优雅降级：无图 hero 用渐变底、正文为空的区块整块不渲染', async () => {
    mockPage([
      block('hero', { title: '只有标题', subtitle: '', image: '', button_text: '', button_link: '' }),
      // body 为空 → rich_text 整块不渲染（不留空卡片）
      block('rich_text', { title: '空正文', body: '' }, { html: { body: '' } }),
      // 一张图都没有 → gallery 整块不渲染
      block('gallery', { title: '空图集', images: [] }),
      // 栏目没文章（后端返回 items: []）→ faq_embed 整块不渲染
      block('faq_embed', { title: '空嵌入', category_id: '', limit: '5' }),
    ])

    await renderPage()
    await waitFor(() => expect(screen.getByTestId('block-hero')).toBeTruthy())

    expect(screen.getByTestId('block-hero-title').textContent).toBe('只有标题')
    // 没有背景图时用品牌渐变，而不是留一块空白
    expect(screen.getByTestId('block-hero').querySelector('img')).toBeNull()
    expect(screen.queryByTestId('block-rich-text')).toBeNull()
    expect(screen.queryByTestId('block-gallery')).toBeNull()
    expect(screen.queryByTestId('block-faq-embed')).toBeNull()
  })

  it('hero 按钮：文案与链接缺任一则不渲染；站外链接用 a 标签', async () => {
    mockPage([block('hero', { title: 'T', button_text: '去官网', button_link: 'https://example.com' })])
    const { unmount } = await renderPage()
    await waitFor(() => expect(screen.getByTestId('block-hero')).toBeTruthy())

    const btn = screen.getByTestId('block-hero-button')
    expect(btn.getAttribute('href')).toBe('https://example.com')
    unmount()

    // 只填文案不填链接 → 不渲染按钮
    mockPage([block('hero', { title: 'T', button_text: '去官网', button_link: '' })])
    await renderPage()
    await waitFor(() => expect(screen.getByTestId('block-hero')).toBeTruthy())
    expect(screen.queryByTestId('block-hero-button')).toBeNull()
  })

  it('未知区块类型被跳过，不影响其余区块渲染', async () => {
    mockPage([
      block('hero', { title: '正常块' }),
      block('legacy_block_removed', { title: '真源已删的旧数据' }),
    ])

    await renderPage()
    await waitFor(() => expect(screen.getByTestId('block-hero')).toBeTruthy())

    expect(screen.getByTestId('block-hero')).toBeTruthy()
    // 只剩一个渲染块（未知类型没有组件，静默跳过）
    expect(screen.getByTestId('page-blocks').querySelectorAll(':scope > div')).toHaveLength(1)
  })

  it('faq_embed 用响应里带出的 items 渲染，前台不再发文章请求', async () => {
    mockPage([
      block('faq_embed', { title: '常见问题', category_id: '20', limit: '5' }, {
        items: [
          { id: 11, title: '如何退货', summary: '七天无理由' },
          { id: 12, title: '运费怎么算', summary: null },
        ],
      }),
    ])

    await renderPage()
    await waitFor(() => expect(screen.getByTestId('block-faq-embed')).toBeTruthy())

    expect(screen.getAllByTestId(/block-faq-embed-item-/)).toHaveLength(2)
    const first = screen.getByTestId('block-faq-embed-item-0')
    expect(within(first).getByText('如何退货')).toBeTruthy()
    expect(first.querySelector('a')?.getAttribute('href')).toBe('/service-center/faq/11')

    // 零请求：列表来自单页响应，不需要再调帮助中心接口
    expect(getFaqArticlesMock).not.toHaveBeenCalled()
  })

  it('固定模板单页（about）不渲染区块容器（AC-203.3 零回归）', async () => {
    getCmsPageMock.mockResolvedValue({ data: { data: {
      name: '关于我们', slug: 'about', template: 'about',
      fields: { banner: '', intro: '简介', milestones: [], values: [] },
      html: { intro: '<p>简介</p>' },
      blocks: [],
      seo: { title: '关于我们', keywords: '', description: '' },
      updated_at: null,
    } } })

    await renderPage('about')
    await waitFor(() => expect(screen.getByTestId('page-about')).toBeTruthy())

    expect(screen.queryByTestId('page-blocks')).toBeNull()
    // props 契约统一也不该在根元素留下垃圾属性
    expect(screen.getByTestId('page-about').hasAttribute('blocks')).toBe(false)
  })
})
