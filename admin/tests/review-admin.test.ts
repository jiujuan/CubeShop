import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const {
  getReviewsMock,
  approveReviewMock,
  rejectReviewMock,
  replyReviewMock,
  deleteReviewMock,
  setAuditModeMock,
} = vi.hoisted(() => ({
  getReviewsMock: vi.fn(),
  approveReviewMock: vi.fn(),
  rejectReviewMock: vi.fn(),
  replyReviewMock: vi.fn(),
  deleteReviewMock: vi.fn(),
  setAuditModeMock: vi.fn(),
}))

vi.mock('@/api/review', async () => {
  const actual = await vi.importActual<typeof import('@/api/review')>('@/api/review')
  return {
    ...actual,
    getReviews: getReviewsMock,
    approveReview: approveReviewMock,
    rejectReview: rejectReviewMock,
    replyReview: replyReviewMock,
    deleteReview: deleteReviewMock,
    setAuditMode: setAuditModeMock,
  }
})

import ReviewView from '@/views/operation/ReviewView.vue'

function freshPinia(permissions: string[] = ['review.manage']) {
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
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/reviews', component: ReviewView }],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia, makeRouter()],
  directives: { permission },
})

const row = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  order_id: 100,
  product_id: 8,
  product_title: '测试商品',
  user_id: 2,
  nickname: '小明',
  is_anonymous: false,
  rating: 4,
  content: '很好用',
  images: [],
  status: 'pending',
  status_label: '待审核',
  reject_reason: null,
  reply_content: null,
  reply_at: null,
  created_at: '2026-09-16 10:00:00',
  ...overrides,
})

function mockList(list: unknown[], auditMode = false) {
  getReviewsMock.mockResolvedValue({
    data: {
      data: {
        stats: { pending: 1, today: 2, total: 10, avg: 4.2 },
        audit_mode: auditMode,
        list,
        pagination: { page: 1, page_size: 20, total: list.length, total_pages: 1 },
      },
    },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
})

describe('评价管理页（T-017）', () => {
  it('渲染统计小卡与评价列表', async () => {
    mockList([row({ id: 7, product_title: '蓝牙耳机' })])
    const wrapper = mount(ReviewView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="review-stats"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('待审核')
    expect(wrapper.find('[data-testid="review-row-7"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('蓝牙耳机')
  })

  it('点击状态筛选携带 status 参数并回到第一页', async () => {
    mockList([row()])
    const wrapper = mount(ReviewView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="status-filter-pending"]').trigger('click')
    await flushPromises()

    const last = getReviewsMock.mock.calls.at(-1)!
    expect(last[0].status).toBe('pending')
    expect(last[0].page).toBe(1)
  })

  it('通过评价：二次确认后调用接口并刷新', async () => {
    mockList([row({ id: 11 })])
    approveReviewMock.mockResolvedValue({ data: { code: 0 } })

    const wrapper = mount(ReviewView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="approve-11"]').trigger('click')
    await flushPromises()

    const confirmBtn = document.body.querySelector('[data-testid="confirm-ok"]') as HTMLButtonElement
    expect(confirmBtn).toBeTruthy()
    confirmBtn.click()
    await flushPromises()

    expect(approveReviewMock).toHaveBeenCalledWith(11)
    // 初次加载 + 操作后刷新
    expect(getReviewsMock.mock.calls.length).toBeGreaterThanOrEqual(2)
  })

  it('驳回需填写原因且携带原因调用接口', async () => {
    mockList([row({ id: 12 })])
    rejectReviewMock.mockResolvedValue({ data: { code: 0 } })
    vi.stubGlobal('prompt', vi.fn(() => '含广告信息'))

    const wrapper = mount(ReviewView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="reject-12"]').trigger('click')
    await flushPromises()

    const confirmBtn = document.body.querySelector('[data-testid="confirm-ok"]') as HTMLButtonElement
    confirmBtn.click()
    await flushPromises()

    expect(rejectReviewMock).toHaveBeenCalledWith(12, '含广告信息')
  })

  it('回复商家评价', async () => {
    mockList([row({ id: 13 })])
    replyReviewMock.mockResolvedValue({ data: { code: 0 } })

    const wrapper = mount(ReviewView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="reply-13"]').trigger('click')
    await flushPromises()

    const textarea = wrapper.find('[data-testid="reply-text"]')
    await textarea.setValue('感谢支持')
    await wrapper.find('[data-testid="reply-submit"]').trigger('click')
    await flushPromises()

    expect(replyReviewMock).toHaveBeenCalledWith(13, '感谢支持')
  })

  it('删除评价需二次确认', async () => {
    mockList([row({ id: 14, status: 'approved', status_label: '已通过' })])
    deleteReviewMock.mockResolvedValue({ data: { code: 0 } })

    const wrapper = mount(ReviewView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="delete-14"]').trigger('click')
    await flushPromises()

    const confirmBtn = document.body.querySelector('[data-testid="confirm-ok"]') as HTMLButtonElement
    confirmBtn.click()
    await flushPromises()

    expect(deleteReviewMock).toHaveBeenCalledWith(14)
  })

  it('无 config.manage 权限时审核模式开关禁用并展示只读提示', async () => {
    mockList([row()], true)
    const wrapper = mount(ReviewView, { global: globalCfg(freshPinia(['review.manage'])) })
    await flushPromises()

    const toggle = wrapper.find('[data-testid="audit-mode-toggle"]')
    expect(toggle.attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="audit-mode-readonly"]').exists()).toBe(true)

    await toggle.trigger('click')
    await flushPromises()

    expect(setAuditModeMock).not.toHaveBeenCalled()
  })

  it('有 config.manage 权限时可切换审核模式', async () => {
    mockList([row()], false)
    setAuditModeMock.mockResolvedValue({ data: { data: { audit_mode: true } } })

    const wrapper = mount(ReviewView, { global: globalCfg(freshPinia(['review.manage', 'config.manage'])) })
    await flushPromises()

    await wrapper.find('[data-testid="audit-mode-toggle"]').trigger('click')
    await flushPromises()

    expect(setAuditModeMock).toHaveBeenCalledWith(true)
  })
})
