import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const {
  getCouponsMock,
  createCouponMock,
  updateCouponMock,
  stopCouponMock,
  getCouponStatsMock,
  exportCouponMock,
  getPromotionsMock,
  createPromotionMock,
  updatePromotionMock,
  togglePromotionMock,
  getCategoriesMock,
  getProductsMock,
} = vi.hoisted(() => ({
  getCouponsMock: vi.fn(),
  createCouponMock: vi.fn(),
  updateCouponMock: vi.fn(),
  stopCouponMock: vi.fn(),
  getCouponStatsMock: vi.fn(),
  exportCouponMock: vi.fn(),
  getPromotionsMock: vi.fn(),
  createPromotionMock: vi.fn(),
  updatePromotionMock: vi.fn(),
  togglePromotionMock: vi.fn(),
  getCategoriesMock: vi.fn(),
  getProductsMock: vi.fn(),
}))

vi.mock('@/api/marketing', async () => {
  const actual = await vi.importActual<typeof import('@/api/marketing')>('@/api/marketing')
  return {
    ...actual,
    getCoupons: getCouponsMock,
    createCoupon: createCouponMock,
    updateCoupon: updateCouponMock,
    stopCoupon: stopCouponMock,
    getCouponStats: getCouponStatsMock,
    exportCoupon: exportCouponMock,
    getPromotions: getPromotionsMock,
    createPromotion: createPromotionMock,
    updatePromotion: updatePromotionMock,
    togglePromotion: togglePromotionMock,
  }
})

vi.mock('@/api/product', () => ({
  getCategories: getCategoriesMock,
  getProducts: getProductsMock,
  getBrands: vi.fn(),
}))

import CouponPanel from '@/components/marketing/CouponPanel.vue'
import MarketingView from '@/views/operation/MarketingView.vue'
import PromotionPanel from '@/components/marketing/PromotionPanel.vue'

const pag = { page: 1, page_size: 20, total: 0, total_pages: 1 }

function freshPinia(permissions: string[] = ['marketing.manage']) {
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
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/marketing', component: MarketingView }],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia, makeRouter()],
  directives: { permission },
})

const couponRow = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  name: '新人立减券',
  type: 'fixed',
  type_label: '满减券',
  amount: 10,
  percent: null,
  max_discount: null,
  min_spend: 50,
  scope: 'all',
  scope_label: '全场通用',
  scope_refs: [],
  total_count: 100,
  issued_count: 0,
  used_count: 0,
  per_user_limit: 1,
  valid_type: 'relative',
  valid_from: null,
  valid_to: null,
  valid_days: 30,
  status: 'active',
  issued: false,
  created_at: '2026-09-16 10:00:00',
  ...overrides,
})

function mockCoupons(list: unknown[]) {
  getCouponsMock.mockResolvedValue({ data: { data: { list, pagination: { ...pag, total: list.length } } } })
}

const promotionRow = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  name: '暑期满减',
  rules: [{ min: 100, discount: 10 }, { min: 300, discount: 40 }],
  scope: 'all',
  scope_refs: [],
  start_at: '2026-09-01 00:00:00',
  end_at: '2026-10-01 00:00:00',
  status: 'active',
  running: true,
  created_at: '2026-09-01 00:00:00',
  ...overrides,
})

function mockPromotions(list: unknown[]) {
  getPromotionsMock.mockResolvedValue({ data: { data: { list, pagination: { ...pag, total: list.length } } } })
}

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  mockCoupons([])
  mockPromotions([])
  getCategoriesMock.mockResolvedValue({ data: { data: [{ id: 3, name: '数码配件', parent_id: 0 }, { id: 4, name: '服装鞋包', parent_id: 0 }] } })
  getProductsMock.mockResolvedValue({ data: { data: { list: [{ id: 2, title: '无线蓝牙耳机' }, { id: 5, title: '运动跑步鞋' }], pagination: pag } } })
})

describe('营销管理 · 优惠券（T-040）', () => {
  it('① 类型切换动态字段：fixed 显示面额，percent 显示折扣+封顶', async () => {
    const wrapper = mount(CouponPanel, { global: globalCfg(freshPinia()) })
    await flushPromises()

    wrapper.find('[data-testid="coupon-create-btn"]').trigger('click')
    await flushPromises()
    const q = (t: string) => document.querySelector(`[data-testid="${t}"]`) as HTMLElement | null
    expect(q('coupon-form-dialog')).toBeTruthy()

    // fixed → 面额字段可见，折扣字段不可见
    expect(q('coupon-form-amount')).toBeTruthy()
    expect(q('coupon-form-percent')).toBeNull()

    // 切换 percent → 折扣 + 封顶可见，面额消失（Teleport 在 body，需原生 setValue）
    ;(q('coupon-form-type') as HTMLSelectElement).value = 'percent'
    ;(q('coupon-form-type') as HTMLSelectElement).dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()
    expect(q('coupon-form-amount')).toBeNull()
    expect(q('coupon-form-percent')).toBeTruthy()
    expect(q('coupon-form-max-discount')).toBeTruthy()
  })

  it('② 已发放券编辑时核心字段置灰并提示锁定原因', async () => {
    const wrapper = mount(CouponPanel, { global: globalCfg(freshPinia()) })
    await flushPromises()
    ;(wrapper.vm as unknown as { openEdit: (c: unknown) => void }).openEdit(couponRow({ id: 9, issued: true, issued_count: 20 }))
    await flushPromises()

    const q = (t: string) => document.querySelector(`[data-testid="${t}"]`) as HTMLElement | null
    expect(q('coupon-locked-tip')?.textContent).toContain('已发放')
    expect((q('coupon-form-type') as HTMLSelectElement).disabled).toBe(true)
    expect((q('coupon-form-amount') as HTMLInputElement).disabled).toBe(true)
    expect((q('coupon-form-min-spend') as HTMLInputElement).disabled).toBe(true)
    expect((q('coupon-form-total') as HTMLInputElement).disabled).toBe(true)
    // 名称仍可编辑
    expect((q('coupon-form-name') as HTMLInputElement).disabled).toBe(false)

    // 保存载荷仅含白名单字段
    ;(q('coupon-form-save') as HTMLElement).click()
    await flushPromises()
    expect(updateCouponMock).toHaveBeenCalledWith(9, expect.not.objectContaining({ amount: expect.anything() }))
  })

  it('④ 适用范围选择器：指定分类时勾选组装 scope_refs，未选时本地拦截', async () => {
    const wrapper = mount(CouponPanel, { global: globalCfg(freshPinia()) })
    await flushPromises()
    ;(wrapper.vm as unknown as { openCreate: () => void }).openCreate()
    await flushPromises()

    const vm = wrapper.vm as unknown as { form: { scope: string; scope_refs: number[] }; save: () => Promise<void> }
    const q = (t: string) => document.querySelector(`[data-testid="${t}"]`) as HTMLElement | null
    ;(q('coupon-form-scope') as HTMLSelectElement).value = 'category'
    ;(q('coupon-form-scope') as HTMLSelectElement).dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()
    // 未勾选 → 保存被本地拦截
    ;(q('coupon-form-name') as HTMLInputElement).value = '分类券'
    ;(q('coupon-form-name') as HTMLInputElement).dispatchEvent(new Event('input', { bubbles: true }))
    ;(q('coupon-form-amount') as HTMLInputElement).value = '10'
    ;(q('coupon-form-amount') as HTMLInputElement).dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()
    ;(q('coupon-form-save') as HTMLElement).click()
    await flushPromises()
    expect(q('coupon-form-error')?.textContent).toContain('选择具体对象')
    expect(createCouponMock).not.toHaveBeenCalled()

    // 勾选分类 3 → 载荷携带 scope_refs=[3]
    vm.form.scope_refs = [3]
    ;(q('coupon-form-save') as HTMLElement).click()
    await flushPromises()
    expect(createCouponMock).toHaveBeenCalledWith(expect.objectContaining({ scope: 'category', scope_refs: [3] }))
  })

  it('⑤ 统计抽屉渲染：领取率/核销率/带来订单/优惠金额', async () => {
    getCouponStatsMock.mockResolvedValue({
      data: {
        data: {
          id: 1, name: '新人立减券', total_count: 100, issued_count: 50,
          received_count: 50, used_count: 20, available_count: 50,
          issue_rate: 0.5, use_rate: 0.4, order_count: 20,
          discount_sum: 200, order_amount_sum: 3980,
        },
      },
    })
    const wrapper = mount(CouponPanel, { global: globalCfg(freshPinia()) })
    await flushPromises()
    ;(wrapper.vm as unknown as { openStats: (c: unknown) => void }).openStats(couponRow())
    await flushPromises()

    const q = (t: string) => document.querySelector(`[data-testid="${t}"]`)
    expect(q('coupon-stats-drawer')).toBeTruthy()
    expect(q('coupon-stats-received')?.textContent).toBe('50')
    expect(q('coupon-stats-used')?.textContent).toBe('20')
    expect(q('coupon-stats-use-rate')?.textContent).toBe('40.0%')
    expect(q('coupon-stats-orders')?.textContent).toBe('20')
    expect(document.body.textContent).toContain('¥200.00')
  })

  it('核销列表列与操作按钮渲染 + 无权限时新建按钮被移除（⑥ 权限码）', async () => {
    mockCoupons([couponRow({ id: 7, used_count: 12 })])
    const wrapper = mount(CouponPanel, { global: globalCfg(freshPinia(['marketing.manage'])) })
    await flushPromises()

    expect(wrapper.find('[data-testid="coupon-row-7"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="coupon-stats-7"]').exists()).toBe(true)

    // 无 marketing.manage 权限 → 新建按钮被 v-permission 移除
    const noPerm = mount(CouponPanel, { global: globalCfg(freshPinia([])) })
    await flushPromises()
    expect(noPerm.find('[data-testid="coupon-create-btn"]').exists()).toBe(false)
  })

  it('停发需二次确认且调用 stop 接口', async () => {
    mockCoupons([couponRow({ id: 8 })])
    stopCouponMock.mockResolvedValue({ data: { data: null } })
    const wrapper = mount(CouponPanel, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="coupon-stop-8"]').trigger('click')
    await flushPromises()
    expect(stopCouponMock).not.toHaveBeenCalled()

    document.querySelector('[data-testid="confirm-ok"]')?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    expect(stopCouponMock).toHaveBeenCalledWith(8)
  })
})

describe('营销管理 · 满减活动（T-040）', () => {
  it('③ 梯度编辑器增删行与递增校验', async () => {
    const wrapper = mount(PromotionPanel, { global: globalCfg(freshPinia()) })
    await flushPromises()

    const vm = wrapper.vm as unknown as {
      openCreate: () => void
      form: { name: string; rules: Array<{ min: number; discount: number }>; start_at: string; end_at: string }
      validateTiers: () => string | null
    }
    vm.openCreate()
    await flushPromises()

    // 加一档 → 2 行；再删一行 → 1 行
    vm.addTier()
    expect(vm.form.rules.length).toBe(2)
    vm.removeTier(1)
    expect(vm.form.rules.length).toBe(1)

    // 非递增 → 校验失败
    vm.form.rules = [{ min: 200, discount: 10 }, { min: 100, discount: 20 }]
    expect(vm.validateTiers()).toContain('从小到大')

    // 严格递增 → 通过
    vm.form.rules = [{ min: 100, discount: 10 }, { min: 200, discount: 25 }]
    expect(vm.validateTiers()).toBeNull()

    // 保存提交 payload（后台会再排序归一化）
    vm.form.name = '双梯度活动'
    vm.form.start_at = '2026-09-01 00:00'
    vm.form.end_at = '2026-10-01 00:00'
    ;(document.querySelector('[data-testid="promotion-form-save"]') as HTMLElement).click()
    await flushPromises()
    expect(createPromotionMock).toHaveBeenCalledWith(expect.objectContaining({ name: '双梯度活动', rules: [{ min: 100, discount: 10 }, { min: 200, discount: 25 }] }))
  })

  it('活动列表渲染梯度文案与启停', async () => {
    mockPromotions([promotionRow({ id: 3, status: 'active', running: true }), promotionRow({ id: 4, status: 'stopped', running: false })])
    togglePromotionMock.mockResolvedValue({ data: { data: { status: 'stopped' } } })
    const wrapper = mount(PromotionPanel, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="promotion-row-3"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="promotion-rules-3"]').text()).toContain('满100减10')
    expect(wrapper.find('[data-testid="promotion-rules-3"]').text()).toContain('满300减40')
    expect(wrapper.text()).toContain('进行中')
    expect(wrapper.text()).toContain('已停用')

    await wrapper.find('[data-testid="promotion-toggle-3"]').trigger('click')
    await flushPromises()
    document.querySelector('[data-testid="confirm-ok"]')?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    expect(togglePromotionMock).toHaveBeenCalledWith(3)
  })

  it('⑥ 营销管理页双 Tab 切换与权限菜单', async () => {
    const wrapper = mount(MarketingView, { global: globalCfg(freshPinia(['marketing.manage'])) })
    await flushPromises()

    // 默认优惠券 Tab（CouponPanel 渲染，PromotionPanel 被 v-show 隐藏但已挂载）
    expect(wrapper.find('[data-testid="marketing-tab-coupons"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="marketing-tab-promotions"]').exists()).toBe(true)

    await wrapper.find('[data-testid="marketing-tab-promotions"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="promotion-create-btn"]').exists()).toBe(true)
    expect(getPromotionsMock).toHaveBeenCalled()
  })
})
