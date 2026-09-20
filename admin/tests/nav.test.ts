import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const {
  getNavItemsMock, createNavItemMock, updateNavItemMock, deleteNavItemMock, getCategoriesMock,
} = vi.hoisted(() => ({
  getNavItemsMock: vi.fn(),
  createNavItemMock: vi.fn(),
  updateNavItemMock: vi.fn(),
  deleteNavItemMock: vi.fn(),
  getCategoriesMock: vi.fn(),
}))

vi.mock('@/api/nav', () => ({
  getNavItems: getNavItemsMock,
  createNavItem: createNavItemMock,
  updateNavItem: updateNavItemMock,
  deleteNavItem: deleteNavItemMock,
}))
vi.mock('@/api/product', () => ({ getCategories: getCategoriesMock }))

import NavView from '@/views/site/NavView.vue'

/**
 * ⚠️ 新增/编辑弹窗走 `<Teleport to="body">`，内容不在 wrapper 的 DOM 树里，
 * wrapper.find 搜不到 —— 必须直接查 document.body。
 * 这是本文件不操作弹窗时不直接用 `wrapper.find` 的原因。
 */
function el(selector: string): HTMLElement {
  const node = document.body.querySelector(selector)
  if (!node) throw new Error(`未找到元素：${selector}`)
  return node as HTMLElement
}

/** 设值并派发事件：文本/数字/下拉监听 input，单选按钮监听 change */
async function setValue(selector: string, value: string) {
  const input = el(selector) as HTMLInputElement | HTMLSelectElement
  input.value = value
  input.dispatchEvent(new Event('input'))
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function click(selector: string) {
  el(selector).click()
  await flushPromises()
}

/** 分类引用型条目（标题由后端带出 category_name，条目本身 title 为 null） */
function categoryRow(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    type: 'category',
    type_label: '商品分类',
    title: null,
    url: null,
    category_id: 11,
    category_name: '运动户外',
    category_missing: false,
    target: '_self',
    sort: 30,
    is_active: true,
    ...overrides,
  }
}

function customRow(overrides: Record<string, unknown> = {}) {
  return {
    id: 2,
    type: 'custom',
    type_label: '自定义链接',
    title: '新闻中心',
    url: '/news',
    category_id: null,
    category_name: null,
    category_missing: false,
    target: '_self',
    sort: 20,
    is_active: true,
    ...overrides,
  }
}

function mockData(items: unknown[] = [categoryRow(), customRow()]) {
  getNavItemsMock.mockResolvedValue({ data: { data: items } })
  getCategoriesMock.mockResolvedValue({
    data: {
      data: [
        { id: 11, parent_id: 0, name: '运动户外', sort: 30, status: 1, children: [] },
        { id: 12, parent_id: 0, name: '美妆个护', sort: 20, status: 1, children: [] },
      ],
    },
  })
}

async function mountView() {
  const wrapper = mount(NavView, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function openCreate() {
  await click('[data-testid="nav-create"]')
}

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = '' // 清掉上个用例 Teleport 的残留
  createNavItemMock.mockResolvedValue({ data: { data: {}, code: 0 } })
  updateNavItemMock.mockResolvedValue({ data: { data: {}, code: 0 } })
  deleteNavItemMock.mockResolvedValue({ data: { data: null, code: 0 } })
  mockData()
})

describe('导航管理页（后台）', () => {
  it('TC-NAV-A01 列表按 sort 展示，引用型显示分类名而非自己的 title', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="nav-title-1"]').text()).toContain('运动户外')
    expect(wrapper.find('[data-testid="nav-title-2"]').text()).toContain('新闻中心')
    expect(wrapper.find('[data-testid="nav-url-1"]').text()).toBe('/category/11')
  })

  it('TC-NAV-A02 分类已删除时标记「已失效」', async () => {
    mockData([categoryRow({ category_name: null, category_missing: true })])
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="nav-title-1"]').text()).toContain('（分类已删除）')
    expect(wrapper.find('[data-testid="nav-title-1"]').text()).toContain('已失效')
  })

  it('TC-NAV-A03 空列表显示占位', async () => {
    mockData([])
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="nav-empty"]').exists()).toBe(true)
  })

  it('TC-NAV-A04 新增自定义链接：提交 title/url/target', async () => {
    await mountView()
    await openCreate()

    await setValue('[data-testid="nav-type-custom"]', 'custom')
    await setValue('[data-testid="nav-title-input"]', '客服中心')
    await setValue('[data-testid="nav-url-input"]', '/service-center')
    await setValue('[data-testid="nav-sort-input"]', '15')
    await click('[data-testid="nav-submit"]')

    expect(createNavItemMock).toHaveBeenCalledWith(expect.objectContaining({
      type: 'custom', title: '客服中心', url: '/service-center', sort: 15,
    }))
  })

  it('TC-NAV-A05 新增分类引用：只提交 category_id，不带 title/url', async () => {
    await mountView()
    await openCreate()

    await setValue('[data-testid="nav-category-select"]', '12')
    await click('[data-testid="nav-submit"]')

    expect(createNavItemMock).toHaveBeenCalledWith(expect.objectContaining({
      type: 'category', category_id: 12,
    }))
    expect(createNavItemMock.mock.calls[0][0]).not.toHaveProperty('title')
    expect(createNavItemMock.mock.calls[0][0]).not.toHaveProperty('url')
  })

  it('TC-NAV-A06 已在导航中的分类在下拉里被禁用', async () => {
    await mountView()
    await openCreate()

    expect(el('[data-testid="nav-category-option-11"]').hasAttribute('disabled')).toBe(true)
    expect(el('[data-testid="nav-category-option-11"]').textContent).toContain('（已在导航中）')
    expect(el('[data-testid="nav-category-option-12"]').hasAttribute('disabled')).toBe(false)
  })

  it('TC-NAV-A07 类型切换：从分类改自定义后不提交残留的 category_id', async () => {
    await mountView()
    await openCreate()

    await setValue('[data-testid="nav-category-select"]', '12')
    await setValue('[data-testid="nav-type-custom"]', 'custom')

    expect(document.body.querySelector('[data-testid="nav-title-input"]')).not.toBeNull()
    expect(document.body.querySelector('[data-testid="nav-category-select"]')).toBeNull()

    await setValue('[data-testid="nav-title-input"]', '新闻中心')
    await setValue('[data-testid="nav-url-input"]', 'https://example.com')
    await click('[data-testid="nav-submit"]')

    expect(createNavItemMock.mock.calls[0][0]).not.toHaveProperty('category_id')
    expect(createNavItemMock).toHaveBeenCalledWith(expect.objectContaining({
      type: 'custom', title: '新闻中心', url: 'https://example.com',
    }))
  })

  it('TC-NAV-A08 未选分类时保存按钮禁用', async () => {
    await mountView()
    await openCreate()

    expect(el('[data-testid="nav-submit"]').hasAttribute('disabled')).toBe(true)
  })

  it('TC-NAV-A09 点击状态徽标切换启停', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="nav-toggle-2"]').trigger('click')

    expect(updateNavItemMock).toHaveBeenCalledWith(2, { is_active: false })
  })

  it('TC-NAV-A10 删除走二次确认，确认后才调删除接口', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="nav-delete"]').trigger('click')
    await flushPromises()

    expect(deleteNavItemMock).not.toHaveBeenCalled()

    const confirmBtn = [...document.body.querySelectorAll('button')]
      .find((b) => b.textContent?.includes('确定删除'))
    confirmBtn?.click()
    await flushPromises()

    expect(deleteNavItemMock).toHaveBeenCalledWith(1)
  })
})
