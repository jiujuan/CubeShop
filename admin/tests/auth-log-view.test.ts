import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const { getAuthLogsMock, getAuthLogMock } = vi.hoisted(() => ({
  getAuthLogsMock: vi.fn(),
  getAuthLogMock: vi.fn(),
}))

vi.mock('@/api/admin', () => ({
  getAuthLogs: getAuthLogsMock,
  getAuthLog: getAuthLogMock,
}))

import AuthLogView from '@/views/system/AuthLogView.vue'
import type { AuthLog } from '@/api/admin'

const authLog = (over: Partial<AuthLog> = {}): AuthLog => ({
  id: 1,
  event: 'login',
  event_label: '登录',
  actor_type: 'customer',
  actor_label: '买家',
  user_id: 10,
  identifier: '13800000000',
  success: false,
  success_label: '失败',
  fail_reason: 'invalid_credential',
  ip: '127.0.0.1',
  user_agent: 'Mozilla/5.0 (Test)',
  device_id: 5,
  token_id: null,
  detail: { reason: 'wrong password' },
  created_at: '2026-09-21 10:00:00',
  ...over,
})

const listFixture = () => ({
  list: [
    authLog({ id: 1, event: 'login', success: false, fail_reason: 'invalid_credential' }),
    authLog({ id: 2, event: 'register', success: true, fail_reason: null, actor_type: 'customer' }),
    authLog({ id: 3, event: 'logout', success: true, fail_reason: null, actor_type: 'admin', identifier: 'admin01' }),
  ],
  pagination: { page: 1, page_size: 20, total: 3, total_pages: 1 },
})

function freshPinia(permissions: string[] = ['log.auth.view']) {
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
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/auth-logs', component: AuthLogView }],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia, makeRouter()],
  directives: { permission },
})

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getAuthLogsMock.mockResolvedValue({ data: { data: listFixture() } })
  getAuthLogMock.mockResolvedValue({ data: { data: authLog({ id: 1 }) } })
})

async function mountView(permissions?: string[]) {
  const wrapper = mount(AuthLogView, { global: globalCfg(freshPinia(permissions)) })
  await flushPromises()
  return wrapper
}

describe('认证日志页 AuthLogView（log.auth.view）', () => {
  it('挂载即拉取列表并按列渲染 事件/身份/标识/结果/失败原因/IP', async () => {
    const wrapper = await mountView()

    // 表头 + 3 行数据
    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(3)

    expect(wrapper.text()).toContain('登录')
    expect(wrapper.text()).toContain('注册')
    expect(wrapper.text()).toContain('登出')
    // 标识列（买家手机号 / 管理员账号）
    expect(wrapper.text()).toContain('13800000000')
    expect(wrapper.text()).toContain('admin01')
    // 失败原因
    expect(wrapper.text()).toContain('invalid_credential')
    // IP
    expect(wrapper.text()).toContain('127.0.0.1')
    // 结果徽标文案
    expect(wrapper.text()).toContain('成功')
    expect(wrapper.text()).toContain('失败')
  })

  it('结果徽标按 success 着色（成功绿 / 失败红）', async () => {
    const wrapper = await mountView()
    const badges = wrapper.findAll('tbody tr span.rounded')
    // 3 行 => 3 个结果徽标
    expect(badges).toHaveLength(3)
    const classes = badges.map((b) => b.classes().join(' '))
    // 行1失败 / 行2成功 / 行3成功
    expect(classes[0]).toContain('text-red-600')
    expect(classes[1]).toContain('text-green-600')
    expect(classes[2]).toContain('text-green-600')
  })

  it('筛选事件后点搜索，以所选条件重新请求', async () => {
    const wrapper = await mountView()

    // 选「登录」
    const eventSelect = wrapper.find('[data-testid="auth-log-filter-event"]')
    await eventSelect.setValue('login')
    await wrapper.find('[data-testid="auth-log-search"]').trigger('click')
    await flushPromises()

    // 初次 onMounted + 本次搜索 = 至少两次调用，最近一次带 event=login
    expect(getAuthLogsMock.mock.calls.length).toBeGreaterThanOrEqual(2)
    const lastCall = getAuthLogsMock.mock.calls.at(-1)![0] as Record<string, unknown>
    expect(lastCall.event).toBe('login')
  })

  it('标识输入框回车触发搜索', async () => {
    const wrapper = await mountView()
    const before = getAuthLogsMock.mock.calls.length

    const input = wrapper.find('[data-testid="auth-log-filter-identifier"]')
    await input.setValue('13800000000')
    await input.trigger('keyup.enter')
    await flushPromises()

    expect(getAuthLogsMock.mock.calls.length).toBeGreaterThan(before)
    const lastCall = getAuthLogsMock.mock.calls.at(-1)![0] as Record<string, unknown>
    expect(lastCall.identifier).toBe('13800000000')
  })

  it('重置清空所有筛选条件并重新请求', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="auth-log-filter-event"]').setValue('login')
    await wrapper.find('[data-testid="auth-log-search"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="auth-log-reset"]').trigger('click')
    await flushPromises()

    const lastCall = getAuthLogsMock.mock.calls.at(-1)![0] as Record<string, unknown>
    expect(lastCall.event).toBeUndefined()
    expect(lastCall.identifier).toBeUndefined()
  })

  it('行内展开拉取详情并展示 用户ID/设备ID/Token/UA/扩展明细', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="auth-log-expand-1"]').trigger('click')
    await flushPromises()

    expect(getAuthLogMock).toHaveBeenCalledWith(1)

    // 详情行出现
    expect(wrapper.text()).toContain('用户ID：')
    expect(wrapper.text()).toContain('设备ID：')
    expect(wrapper.text()).toContain('Token ID：')
    expect(wrapper.text()).toContain('User-Agent：')
    // 扩展明细（detail JSON）
    expect(wrapper.text()).toContain('wrong password')
  })

  it('再次点击同一行收起详情', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="auth-log-expand-1"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('用户ID：')

    await wrapper.find('[data-testid="auth-log-expand-1"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).not.toContain('用户ID：')
  })

  it('无 log.auth.view 权限时仍由路由守卫拦截（组件不自带无权限提示）', async () => {
    // 组件内部不设权限守卫，菜单/路由层拦截；此处仅确认组件在有/无权限下都能正常渲染表格骨架
    const wrapper = await mountView([])
    expect(wrapper.find('[data-testid="auth-log-table"]').exists()).toBe(true)
  })
})
