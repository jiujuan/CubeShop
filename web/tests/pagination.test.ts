import { describe, expect, it, vi } from 'vitest'
import { render, fireEvent, screen, within } from '@testing-library/vue'
import Pagination from '@/components/Pagination.vue'

/** 收集当前页按钮（排除省略号与跳页输入框） */
function pageButtons() {
  return screen.queryAllByTestId(/^pager-page-\d+$/).map((b) => Number(b.textContent))
}

describe('Pagination 统一分页条', () => {
  it('总页数 ≤ 7 时全部展示', () => {
    render(Pagination, { props: { pagination: { page: 1, page_size: 10, total: 100, total_pages: 5 } } })
    expect(pageButtons().sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5])
    expect(screen.getByTestId('pager-summary').textContent).toContain('共 100 条记录 / 每页 10 条')
  })

  it('首页折叠：1 2 3 4 5 … 末页', () => {
    render(Pagination, { props: { pagination: { page: 1, page_size: 20, total: null, total_pages: 29 } } })
    expect(pageButtons()).toEqual([1, 2, 3, 4, 5, 29])
    // 跳页框仅在有精确总页数时出现
    expect(screen.getByTestId('pager-jump')).toBeTruthy()
  })

  it('中间页折叠：1 … cur-1 cur cur+1 … 末页', () => {
    render(Pagination, { props: { pagination: { page: 15, page_size: 20, total: null, total_pages: 29 } } })
    expect(pageButtons()).toEqual([1, 14, 15, 16, 29])
  })

  it('末页折叠：1 … 末页-4..末页', () => {
    render(Pagination, { props: { pagination: { page: 29, page_size: 20, total: null, total_pages: 29 } } })
    expect(pageButtons()).toEqual([1, 25, 26, 27, 28, 29])
  })

  it('最多 7 个页码按钮（含省略号）', () => {
    render(Pagination, { props: { pagination: { page: 1, page_size: 20, total: null, total_pages: 29 } } })
    const btns = pageButtons().length
    expect(btns).toBe(6) // 1 2 3 4 5 … 29 中的数字按钮
  })

  it('点击页码触发 change 且为合法页', async () => {
    const { emitted } = render(Pagination, {
      props: { pagination: { page: 1, page_size: 20, total: null, total_pages: 29 } },
    })
    await fireEvent.click(screen.getByTestId('pager-page-5'))
    expect(emitted('change')).toEqual([[5]])
  })

  it('首页时上一页禁用、末页时下一页禁用', () => {
    const { rerender } = render(Pagination, {
      props: { pagination: { page: 1, page_size: 10, total: 100, total_pages: 5 } },
    })
    expect(screen.getByTestId('pager-prev').hasAttribute('disabled')).toBe(true)
    expect(screen.getByTestId('pager-next').hasAttribute('disabled')).toBe(false)
  })

  it('跳页输入框回车跳转，越界钳制、非法忽略', async () => {
    const { emitted } = render(Pagination, {
      props: { pagination: { page: 1, page_size: 20, total: null, total_pages: 29 } },
    })
    const input = screen.getByTestId('pager-jump') as HTMLInputElement

    await fireEvent.update(input, '15')
    await fireEvent.keyUp(input, { key: 'Enter' })
    expect(emitted('change')?.[emitted('change')!.length - 1]).toEqual([15])

    await fireEvent.update(input, '999')
    await fireEvent.keyUp(input, { key: 'Enter' })
    expect(emitted('change')?.[emitted('change')!.length - 1]).toEqual([29])

    await fireEvent.update(input, 'abc')
    await fireEvent.keyUp(input, { key: 'Enter' })
    expect(emitted('change')!.length).toBe(2) // 非法输入未触发新 change
  })

  it('SEC-04：无精确总页数时退化为上下页 + 摘要，不渲染页码与跳页框', () => {
    render(Pagination, {
      props: { pagination: { page: 1, page_size: 20, total: null, total_pages: null, has_more: true } },
    })
    expect(screen.queryByTestId('pager-jump')).toBeNull()
    expect(pageButtons()).toEqual([])
    expect(screen.getByTestId('pager-summary').textContent).toContain('每页 20 条')
    // 有下一页时 next 可用
    expect(screen.getByTestId('pager-next').hasAttribute('disabled')).toBe(false)
    // 第一页 prev 禁用
    expect(screen.getByTestId('pager-prev').hasAttribute('disabled')).toBe(true)
  })

  it('SEC-04：无更多页时下一页禁用', () => {
    render(Pagination, {
      props: { pagination: { page: 3, page_size: 20, total: null, total_pages: null, has_more: false } },
    })
    expect(screen.getByTestId('pager-next').hasAttribute('disabled')).toBe(true)
    expect(screen.getByTestId('pager-prev').hasAttribute('disabled')).toBe(false)
  })

  it('无精确总数但有总页数时摘要展示总页数', () => {
    render(Pagination, {
      props: { pagination: { page: 1, page_size: 20, total: null, total_pages: 8 } },
    })
    expect(screen.getByTestId('pager-summary').textContent).toContain('共 8 页 / 每页 20 条')
  })
})
