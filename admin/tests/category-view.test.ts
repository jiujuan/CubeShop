import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const {
  getCategoriesMock, createCategoryMock, updateCategoryMock, deleteCategoryMock,
} = vi.hoisted(() => ({
  getCategoriesMock: vi.fn(),
  createCategoryMock: vi.fn(),
  updateCategoryMock: vi.fn(),
  deleteCategoryMock: vi.fn(),
}))

vi.mock('@/api/product', () => ({
  getCategories: getCategoriesMock,
  createCategory: createCategoryMock,
  updateCategory: updateCategoryMock,
  deleteCategory: deleteCategoryMock,
}))

import CategoryView from '@/views/product/CategoryView.vue'

/** 删除确认弹层 Teleport 到 body，必须查 document.body */
function el(selector: string): HTMLElement {
  const node = document.body.querySelector(selector)
  if (!node) throw new Error(`未找到元素：${selector}`)
  return node as HTMLElement
}

async function click(selector: string) {
  el(selector).click()
  await flushPromises()
}

function tree(overrides: Record<string, unknown> = {}) {
  return [{
    id: 1, parent_id: 0, name: '运动户外', sort: 10, status: 1, children: [],
    ...overrides,
  }]
}

async function mountView() {
  const wrapper = mount(CategoryView, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  document.body.innerHTML = ''
  vi.clearAllMocks()
  getCategoriesMock.mockResolvedValue({ data: { code: 0, message: 'ok', data: tree() } })
})

describe('分类管理 · 删除（软删除）', () => {
  it('TC-CATV-01 点击确定删除：调用删除接口并刷新列表', async () => {
    deleteCategoryMock.mockResolvedValue({ data: { code: 0, message: '删除成功', data: null } })

    await mountView()
    await click('[data-testid="category-delete"]')
    await click('[data-testid="confirm-ok"]')

    expect(deleteCategoryMock).toHaveBeenCalledWith(1)
    expect(getCategoriesMock.mock.calls.length).toBeGreaterThanOrEqual(2)
  })

  it('TC-CATV-02 删除被后端拒绝（如存在子分类）：页面显示失败原因，不能静默', async () => {
    // ⚠️ request 层的 toast handler 未注册，业务错误只 console.warn，
    //    页面必须自己 catch 并呈现，否则用户点了「确定删除」毫无反馈。
    deleteCategoryMock.mockRejectedValue(new Error('请先删除子分类'))

    await mountView()
    await click('[data-testid="category-delete"]')
    await click('[data-testid="confirm-ok"]')

    expect(el('[data-testid="category-tip"]').textContent).toContain('请先删除子分类')
  })

  it('TC-CATV-03 删除成功：显示成功提示', async () => {
    deleteCategoryMock.mockResolvedValue({ data: { code: 0, message: '删除成功', data: null } })

    await mountView()
    await click('[data-testid="category-delete"]')
    await click('[data-testid="confirm-ok"]')

    expect(el('[data-testid="category-tip"]').textContent).toContain('已删除')
  })
})
