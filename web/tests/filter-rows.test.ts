import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/vue'
import { nextTick } from 'vue'
import FilterRows from '@/components/FilterRows.vue'

/**
 * FilterRows：品牌/属性按钮行折叠容器。
 * jsdom 无真实布局且 Element.prototype 上不存在 offsetHeight/scrollHeight
 * 访问器，用 Object.defineProperty 注入 mock getter 模拟实测行高与内容
 * 自然高度（默认行高 30、间距 8 → 收起 144px、展开 296px）。
 */
let mockRowHeight = 30
let mockContentHeight = 0

describe('FilterRows 折叠容器', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    mockRowHeight = 30
    mockContentHeight = 0
  })

  afterEach(() => {
    // 移除注入的 mock getter，避免污染其它测试文件
    delete (Element.prototype as unknown as Record<string, unknown>).offsetHeight
    delete (Element.prototype as unknown as Record<string, unknown>).scrollHeight
  })

  async function mountWithHeights(contentHeight: number, rowHeight = 30) {
    mockRowHeight = rowHeight
    mockContentHeight = contentHeight
    Object.defineProperty(Element.prototype, 'offsetHeight', {
      configurable: true,
      get: () => mockRowHeight,
    })
    Object.defineProperty(Element.prototype, 'scrollHeight', {
      configurable: true,
      get: () => mockContentHeight,
    })
    const utils = render(FilterRows, {
      props: { testId: 'fr' },
      slots: {
        default: '<button class="chip">选项A</button><button class="chip">选项B</button>',
      },
    })
    // onMounted 在 post 队列：等一个 tick 让行高/内容高度测量生效
    await nextTick()
    return utils
  }

  it('内容不足收起行数时不显示「更多/收起」', async () => {
    await mountWithHeights(100) // 100 < 收起上限 144+2

    expect(screen.queryByTestId('fr-toggle')).toBeNull()
    // 收起态：overflow-hidden、max-height 为 4 行高度
    const viewport = screen.getByTestId('fr-viewport')
    expect(viewport.className).toContain('overflow-hidden')
    expect(viewport.style.maxHeight).toBe('144px')
  })

  it('内容超收起行数时显示「更多」，点击展开、再点收起', async () => {
    await mountWithHeights(600) // 600 > 展开上限 296+2 → 展开后可滚动

    // 初始收起
    const toggle = screen.getByTestId('fr-toggle')
    expect(toggle.textContent).toContain('更多')
    expect(screen.getByTestId('fr-viewport').style.maxHeight).toBe('144px')

    // 展开：max-height 变为 8 行高度，超出滚动
    await fireEvent.click(toggle)
    const viewport = screen.getByTestId('fr-viewport')
    await screen.findByTestId('fr-toggle')
    expect(screen.getByTestId('fr-toggle').textContent).toContain('收起')
    expect(viewport.style.maxHeight).toBe('296px')
    expect(viewport.className).toContain('overflow-y-auto')

    // 再点收起还原
    await fireEvent.click(screen.getByTestId('fr-toggle'))
    expect(screen.getByTestId('fr-viewport').style.maxHeight).toBe('144px')
    expect(screen.getByTestId('fr-viewport').className).not.toContain('overflow-y-auto')
  })

  it('展开后内容不超过 8 行时不显示滚动条', async () => {
    await mountWithHeights(200) // 介于 144 与 296 之间：可展开但无需滚动

    await fireEvent.click(screen.getByTestId('fr-toggle'))
    const viewport = screen.getByTestId('fr-viewport')
    expect(viewport.style.maxHeight).toBe('296px')
    expect(viewport.className).not.toContain('overflow-y-auto')
  })
})
