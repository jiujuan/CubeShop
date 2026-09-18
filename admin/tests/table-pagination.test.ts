import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import TablePagination from '@/components/TablePagination.vue'

function make(totalPages: number, page = 1, extra: Record<string, unknown> = {}) {
  return {
    page,
    page_size: 20,
    total: totalPages * 20,
    total_pages: totalPages,
    ...extra,
  }
}

function mountPager(pagination: Record<string, unknown>, totalText?: string) {
  return mount(TablePagination, { props: { pagination: pagination as never, totalText } })
}

const pageNumbers = (w: ReturnType<typeof mountPager>) =>
  [...w.element.querySelectorAll('button')]
    .map((b) => b.textContent!.trim())
    .filter((t) => /^\d+$/.test(t))
    .map(Number)

describe('TablePagination 分页条', () => {
  it('总页数 ≤ 7 时展示全部页码', () => {
    const w = mountPager(make(5, 3))
    expect(pageNumbers(w)).toEqual([1, 2, 3, 4, 5])
    expect(w.html()).not.toContain('…')
  })

  it('首页时折叠为「1 2 3 4 5 … 29」', () => {
    const w = mountPager(make(29, 1))
    expect(pageNumbers(w)).toEqual([1, 2, 3, 4, 5, 29])
    expect(w.html()).toContain('…')
  })

  it('中间页折叠为「1 … 14 15 16 … 29」', () => {
    const w = mountPager(make(29, 15))
    expect(pageNumbers(w)).toEqual([1, 14, 15, 16, 29])
  })

  it('末页折叠为「1 … 25 26 27 28 29」', () => {
    const w = mountPager(make(29, 29))
    expect(pageNumbers(w)).toEqual([1, 25, 26, 27, 28, 29])
  })

  it('页码不再全量铺开：29 页最多渲染 6 个页码按钮', () => {
    const buttons = mountPager(make(29, 15)).findAll('button')
    // 5 个页码 + 上一页 + 下一页
    expect(buttons.length).toBe(7)
    expect(mountPager(make(29, 1)).findAll('button').length).toBeLessThanOrEqual(8)
  })

  it('点击页码 emit change', async () => {
    const w = mountPager(make(29, 15))
    await w.find('[data-testid="pager-page-16"]').trigger('click')
    expect(w.emitted('change')?.[0]).toEqual([16])
  })

  it('首/末页时上一页/下一页禁用', () => {
    expect(mountPager(make(3, 1)).find('[data-testid="pager-prev"]').attributes('disabled')).toBeDefined()
    expect(mountPager(make(3, 3)).find('[data-testid="pager-next"]').attributes('disabled')).toBeDefined()
  })

  it('上一页 / 下一页按钮可用时 emit 相邻页', async () => {
    const w = mountPager(make(29, 15))
    await w.find('[data-testid="pager-prev"]').trigger('click')
    await w.find('[data-testid="pager-next"]').trigger('click')
    expect(w.emitted('change')?.map((c) => c[0])).toEqual([14, 16])
  })

  it('输入页码回车直达', async () => {
    const w = mountPager(make(29, 1))
    const input = w.find('[data-testid="pager-jump"]')
    await input.setValue('7')
    await input.trigger('keyup.enter')
    expect(w.emitted('change')?.[0]).toEqual([7])
    // 提交后清空
    expect((input.element as HTMLInputElement).value).toBe('')
  })

  it('越界页码钳制到末页，非法输入或当前页不触发', async () => {
    const w = mountPager(make(29, 5))
    const input = w.find('[data-testid="pager-jump"]')

    await input.setValue('999')
    await input.trigger('keyup.enter')
    expect(w.emitted('change')?.[0]).toEqual([29])

    await input.setValue('abc')
    await input.trigger('keyup.enter')
    expect(w.emitted('change')).toHaveLength(1)

    await input.setValue('5')
    await input.trigger('keyup.enter')
    expect(w.emitted('change')).toHaveLength(1)
  })

  it('默认统计文案可被 total-text 覆写', () => {
    const w = mountPager(make(3, 1), '共 42 个账号')
    expect(w.find('[data-testid="pager-summary"]').text()).toBe('共 42 个账号')

    const d = mountPager(make(3, 1))
    expect(d.find('[data-testid="pager-summary"]').text()).toBe('共 60 条记录 / 每页 20 条')
  })
})
