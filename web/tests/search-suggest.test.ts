import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const { getSuggestMock } = vi.hoisted(() => ({
  getSuggestMock: vi.fn(),
}))

vi.mock('@/api/search', () => ({
  getSuggest: getSuggestMock,
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getNav: vi.fn().mockResolvedValue({ data: { data: [] } }),
}))
vi.mock('@/api/user', () => ({
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
}))
vi.mock('@/api/notification', () => ({
  getUnreadCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  getNotifications: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
  markNotificationsRead: vi.fn(),
}))
vi.mock('@/api/auth', () => ({
  getMe: vi.fn(),
  logout: vi.fn(),
}))
vi.mock('@/api/announcement', () => ({
  getAnnouncements: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
}))

import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 顶栏联想下拉（V1.2 S1-10）
 *
 * 钉三条契约：
 * 1. **防抖** —— 输入 200ms 内只发一次请求（后端 suggest 同 IP 限流，前端必须克制）；
 * 2. **空输入不发请求**、候选为空不弹层；
 * 3. **候选点击进结果页**（mousedown 接管，不与 blur 收起互斥）。
 */
function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/search', component: { template: '<div />' } },
      { path: '/category/:id', component: { template: '<div />' } },
    ],
  })
}

async function mountHeader() {
  const router = makeRouter()
  await router.push('/')
  await router.isReady()
  render(ShopHeader, { global: { plugins: [router, createPinia()] } })
  return router
}

beforeEach(() => {
  vi.useFakeTimers()
  getSuggestMock.mockResolvedValue({ data: { data: ['保温杯', '保温壶'] } })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('ShopHeader 联想下拉（search/suggest）', () => {
  it('输入 200ms 防抖后请求联想，候选渲染到下拉', async () => {
    await mountHeader()
    const input = screen.getByTestId('search-input').querySelector('input') as HTMLInputElement
      ?? (screen.getByTestId('search-box').querySelector('input') as HTMLInputElement)

    await fireEvent.update(input, '保温')
    expect(getSuggestMock).not.toHaveBeenCalled()  // 防抖期内

    await vi.advanceTimersByTimeAsync(250)

    expect(getSuggestMock).toHaveBeenCalledTimes(1)
    expect(getSuggestMock).toHaveBeenCalledWith('保温', 8)

    await waitFor(() => expect(screen.getByTestId('header-suggest')).toBeTruthy())
    expect(screen.getByTestId('header-suggest-item-0').textContent).toContain('保温杯')
    expect(screen.getByTestId('header-suggest-item-1').textContent).toContain('保温壶')
  })

  it('清空输入 → 不再请求且下拉收起', async () => {
    await mountHeader()
    const input = screen.getByTestId('search-box').querySelector('input') as HTMLInputElement

    await fireEvent.update(input, '保温')
    await vi.advanceTimersByTimeAsync(250)
    await waitFor(() => expect(screen.getByTestId('header-suggest')).toBeTruthy())

    await fireEvent.update(input, '')
    await vi.advanceTimersByTimeAsync(250)

    expect(getSuggestMock).toHaveBeenCalledTimes(1)  // 没有第二次
    expect(screen.queryByTestId('header-suggest')).toBeNull()
  })

  it('点击候选 → 跳搜索结果页（mousedown 接管，blur 不吞点击）', async () => {
    const router = await mountHeader()
    const input = screen.getByTestId('search-box').querySelector('input') as HTMLInputElement

    await fireEvent.update(input, '保温')
    await vi.advanceTimersByTimeAsync(250)
    await waitFor(() => expect(screen.getByTestId('header-suggest')).toBeTruthy())

    await fireEvent.mouseDown(screen.getByTestId('header-suggest-item-1'))

    // router.push 是异步的：fake timers 下用 advanceTimersByTimeAsync 冲掉定时器与微任务
    await vi.advanceTimersByTimeAsync(0)

    expect(router.currentRoute.value.path).toBe('/search')
    expect(router.currentRoute.value.query.keyword).toBe('保温壶')
  })

  it('联想接口失败 → 静默收起，不抛错不弹层', async () => {
    await mountHeader()
    const input = screen.getByTestId('search-box').querySelector('input') as HTMLInputElement
    getSuggestMock.mockRejectedValue(new Error('boom'))

    await fireEvent.update(input, '保温')
    await vi.advanceTimersByTimeAsync(250)
    await waitFor(() => expect(getSuggestMock).toHaveBeenCalled())

    expect(screen.queryByTestId('header-suggest')).toBeNull()
  })
})
