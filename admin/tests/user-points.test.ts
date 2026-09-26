import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const { getUsersMock, getUserMock, getUserAddressesMock, getUserPointsMock, adjustUserPointsMock, backfillUserCheckinMock } = vi.hoisted(() => ({
  getUsersMock: vi.fn(),
  getUserMock: vi.fn(),
  getUserAddressesMock: vi.fn(),
  getUserPointsMock: vi.fn(),
  adjustUserPointsMock: vi.fn(),
  backfillUserCheckinMock: vi.fn(),
}))

vi.mock('@/api/user', () => ({
  getUsers: getUsersMock,
  getUser: getUserMock,
  getUserAddresses: getUserAddressesMock,
  updateAdminAddress: vi.fn(),
  updateUser: vi.fn(),
  updateUserStatus: vi.fn(),
  changeUserPassword: vi.fn(),
}))

vi.mock('@/api/points', () => ({
  getUserPoints: getUserPointsMock,
  adjustUserPoints: adjustUserPointsMock,
  backfillUserCheckin: backfillUserCheckinMock,
}))

vi.mock('@/lib/region', () => ({
  listProvinces: vi.fn().mockResolvedValue([]),
  listCities: vi.fn().mockResolvedValue([]),
  listDistricts: vi.fn().mockResolvedValue([]),
}))

import UserListView from '@/views/user/UserListView.vue'
import type { AdminUser } from '@/api/user'
import type { UserPointsDetail } from '@/api/points'

const user = (over: Partial<AdminUser> = {}): AdminUser => ({
  id: 100,
  username: 'buyer_demo',
  nickname: '小买',
  avatar: null,
  phone: '13800000000',
  email: 'buyer@test.com',
  status: 1,
  roles: [],
  order_count: 0,
  total_paid: '0',
  last_login_at: null,
  last_login_ip: null,
  created_at: '2026-09-01 00:00:00',
  ...over,
})

const pointsFixture = (over: Partial<UserPointsDetail> = {}): UserPointsDetail => ({
  user_id: 100,
  username: 'buyer_demo',
  nickname: '小买',
  account: { balance: 100, frozen: 20, total: 120, total_earn: 300, total_spend: 200 },
  logs: [
    {
      id: 9,
      type: 'admin_adjust',
      type_label: '后台调整',
      points: 50,
      frozen_points: 0,
      balance_before: 50,
      balance_after: 100,
      remark: '活动补偿',
      created_at: '2026-09-27 10:00:00',
    },
  ],
  ...over,
})

function freshPinia(permissions: string[] = ['user.manage', 'member.view', 'member.manage']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1,
    username: 'operator',
    nickname: null,
    avatar: null,
    phone: null,
    email: null,
    roles: ['operator'],
    permissions,
  }
  return pinia
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/users', component: UserListView },
    ],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia, makeRouter()],
  directives: { permission },
})

/**
 * 调整弹层走 ConfirmDialog（Teleport 到 body），必须查 document.body —— 与
 * category-view.test.ts 同体例：wrapper.find 看不到 teleport 出去的内容。
 */
function el(selector: string): HTMLElement | null {
  return document.body.querySelector(selector) as HTMLElement | null
}

async function click(selector: string) {
  const node = el(selector)
  if (!node) throw new Error(`未找到元素：${selector}`)
  node.click()
  await flushPromises()
}

async function typeInto(selector: string, value: string) {
  const node = el(selector) as HTMLInputElement | null
  if (!node) throw new Error(`未找到输入框：${selector}`)
  node.value = value
  node.dispatchEvent(new Event('input'))
  await flushPromises()
}

function buttonByText(text: string) {
  return Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === text)
}

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getUsersMock.mockResolvedValue({
    data: { data: { list: [user()], pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 } } },
  })
  // 详情接口必须带 recent_orders：详情弹层直接取 .length
  getUserMock.mockResolvedValue({ data: { data: { ...user(), recent_orders: [] } } })
  getUserAddressesMock.mockResolvedValue({ data: { data: [] } })
  getUserPointsMock.mockResolvedValue({ data: { data: pointsFixture() } })
  backfillUserCheckinMock.mockResolvedValue({
    data: {
      data: { date: '2026-09-20', streak: 4, points: 11, is_backfill: true },
    },
  })
  adjustUserPointsMock.mockResolvedValue({
    data: {
      data: {
        account: { balance: 150, frozen: 20, total: 170, total_earn: 350, total_spend: 200 },
        log: {
          id: 10,
          type: 'admin_adjust',
          type_label: '后台调整',
          points: 50,
          frozen_points: 0,
          balance_before: 100,
          balance_after: 150,
          remark: '活动补偿',
          created_at: '2026-09-27 11:00:00',
        },
      },
    },
  })
})

async function mountView(permissions?: string[]) {
  const wrapper = mount(UserListView, {
    attachTo: document.body,
    global: globalCfg(freshPinia(permissions)),
  })
  await flushPromises()
  return wrapper
}

/** 打开用户详情弹层（详情 / 地址 / 积分并行加载） */
async function openDetail() {
  const entry = buttonByText('详情')
  if (!entry) throw new Error('未找到「详情」入口')
  entry.click()
  await flushPromises()
  await flushPromises()
}

describe('用户管理 - 会员积分区块（S1）', () => {
  it('详情展示积分账户四项与最近流水', async () => {
    const wrapper = await mountView()
    await openDetail()

    expect(el('[data-testid="points-balance"]')?.textContent).toBe('100')
    expect(el('[data-testid="points-frozen"]')?.textContent).toBe('20')
    expect(wrapper.text()).toContain('累计获得：300')
    expect(wrapper.text()).toContain('累计消耗：200')
    expect(wrapper.text()).toContain('后台调整')
    expect(wrapper.text()).toContain('活动补偿')
  })

  it('无 member.view：积分接口失败时整块不展示，详情仍可用', async () => {
    getUserPointsMock.mockRejectedValueOnce(new Error('403'))
    const wrapper = await mountView(['user.manage'])
    await openDetail()

    expect(el('[data-testid="points-balance"]')).toBeNull()
    expect(wrapper.text()).not.toContain('会员积分')
    expect(wrapper.text()).toContain('buyer_demo')
  })

  it('有 member.manage：可见「调整积分」入口并打开弹窗', async () => {
    await mountView(['user.manage', 'member.view', 'member.manage'])
    await openDetail()

    expect(el('[data-testid="open-points-adjust"]')).not.toBeNull()
    await click('[data-testid="open-points-adjust"]')

    expect(el('[data-testid="points-input"]')).not.toBeNull()
    expect(buttonByText('确认调整')).toBeTruthy()
  })

  it('无 member.manage：有 member.view 也不出现调整入口', async () => {
    await mountView(['user.manage', 'member.view'])
    await openDetail()

    expect(el('[data-testid="points-balance"]')).not.toBeNull()
    expect(el('[data-testid="open-points-adjust"]')).toBeNull()
  })

  it('填写数量与原因后确认调整：调用接口并就地刷新余额与流水', async () => {
    await mountView()
    await openDetail()
    await click('[data-testid="open-points-adjust"]')

    await typeInto('[data-testid="points-input"]', '50')
    await typeInto('[data-testid="points-reason"]', '活动补偿')
    await click('[data-testid="confirm-ok"]')

    expect(adjustUserPointsMock).toHaveBeenCalledWith(100, { points: 50, reason: '活动补偿' })
    expect(el('[data-testid="points-balance"]')?.textContent).toBe('150')
    // 新流水插到最前
    const firstRow = document.body.querySelectorAll('[data-testid="points-logs"] tr')[0]
    expect(firstRow?.textContent).toContain('活动补偿')
  })

  it('未填原因不调用接口，提示必填', async () => {
    await mountView()
    await openDetail()
    await click('[data-testid="open-points-adjust"]')

    await typeInto('[data-testid="points-input"]', '-10')
    await click('[data-testid="confirm-ok"]')

    expect(adjustUserPointsMock).not.toHaveBeenCalled()
    expect(el('[data-testid="points-error"]')?.textContent).toContain('请填写调整原因')
  })

  it('数量为 0 时不调用接口', async () => {
    await mountView()
    await openDetail()
    await click('[data-testid="open-points-adjust"]')

    await typeInto('[data-testid="points-input"]', '0')
    await typeInto('[data-testid="points-reason"]', '误操作')
    await click('[data-testid="confirm-ok"]')

    expect(adjustUserPointsMock).not.toHaveBeenCalled()
    expect(el('[data-testid="points-error"]')?.textContent).toContain('非零整数')
  })
})

describe('用户管理 - 后台补签入口（S2）', () => {
  it('有 member.manage：积分区块出现「补签」入口并打开日期弹窗', async () => {
    await mountView(['user.manage', 'member.view', 'member.manage'])
    await openDetail()

    expect(el('[data-testid="open-backfill"]')).not.toBeNull()
    await click('[data-testid="open-backfill"]')

    expect(el('[data-testid="backfill-date"]')).not.toBeNull()
    expect(buttonByText('确认补签')).toBeTruthy()
  })

  it('无 member.manage：不出现补签入口', async () => {
    await mountView(['user.manage', 'member.view'])
    await openDetail()

    expect(el('[data-testid="points-balance"]')).not.toBeNull()
    expect(el('[data-testid="open-backfill"]')).toBeNull()
  })

  it('选择历史日期确认补签：调用接口、回拉积分、提示成功并关闭弹窗', async () => {
    // 详情首次拉取（账户余额 100）+ 补签成功后回拉（余额体现新增签到积分）
    getUserPointsMock
      .mockResolvedValueOnce({ data: { data: pointsFixture({ account: { balance: 100, frozen: 20, total: 120, total_earn: 300, total_spend: 200 } }) } })
      .mockResolvedValueOnce({
        data: {
          data: pointsFixture({
            account: { balance: 111, frozen: 20, total: 131, total_earn: 311, total_spend: 200 },
            logs: [
              {
                id: 11, type: 'signin', type_label: '签到奖励', points: 11, frozen_points: 0,
                balance_before: 100, balance_after: 111, remark: '后台补签奖励', created_at: '2026-09-20 00:00:00',
              },
              ...pointsFixture().logs,
            ],
          }),
        },
      })

    await mountView()
    await openDetail()
    await click('[data-testid="open-backfill"]')

    await typeInto('[data-testid="backfill-date"]', '2026-09-20')
    await click('[data-testid="confirm-ok"]')

    expect(backfillUserCheckinMock).toHaveBeenCalledWith(100, '2026-09-20')
    // 成功后回拉积分：getUserPoints 至少被调用两次（详情一次 + 补签一次）
    expect(getUserPointsMock).toHaveBeenCalledTimes(2)
    expect(el('[data-testid="points-toast"]')?.textContent).toContain('补签成功')
    expect(el('[data-testid="points-toast"]')?.textContent).toContain('发放 11 积分')
    // 弹窗关闭，日期输入框已不在 body 中
    expect(el('[data-testid="backfill-date"]')).toBeNull()
    // 积分余额随补签刷新为 111
    expect(el('[data-testid="points-balance"]')?.textContent).toBe('111')
  })

  it('补签未来日期（含今天）前端拦截，不调用接口', async () => {
    await mountView()
    await openDetail()
    await click('[data-testid="open-backfill"]')

    await typeInto('[data-testid="backfill-date"]', '2026-09-27')
    await click('[data-testid="confirm-ok"]')

    expect(backfillUserCheckinMock).not.toHaveBeenCalled()
    expect(el('[data-testid="backfill-error"]')?.textContent).toContain('不能晚于今天')
  })
})
