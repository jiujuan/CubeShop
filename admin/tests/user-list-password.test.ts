import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const { getUsersMock, getUserMock, getUserAddressesMock, updateAdminAddressMock, updateUserMock, updateUserStatusMock, changeUserPasswordMock } = vi.hoisted(() => ({
  getUsersMock: vi.fn(),
  getUserMock: vi.fn(),
  getUserAddressesMock: vi.fn(),
  updateAdminAddressMock: vi.fn(),
  updateUserMock: vi.fn(),
  updateUserStatusMock: vi.fn(),
  changeUserPasswordMock: vi.fn(),
}))

vi.mock('@/api/user', () => ({
  getUsers: getUsersMock,
  getUser: getUserMock,
  getUserAddresses: getUserAddressesMock,
  updateAdminAddress: updateAdminAddressMock,
  updateUser: updateUserMock,
  updateUserStatus: updateUserStatusMock,
  changeUserPassword: changeUserPasswordMock,
}))

vi.mock('@/lib/region', () => ({
  listProvinces: vi.fn().mockResolvedValue([]),
  listCities: vi.fn().mockResolvedValue([]),
  listDistricts: vi.fn().mockResolvedValue([]),
}))

import UserListView from '@/views/user/UserListView.vue'
import type { AdminUser } from '@/api/user'

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

const listFixture = () => ({
  list: [user({ id: 100, username: 'buyer_demo', nickname: '小买' })],
  pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
})

function freshPinia(permissions: string[] = ['user.manage']) {
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
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/users', component: UserListView }],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia, makeRouter()],
  directives: { permission },
})

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getUsersMock.mockResolvedValue({ data: { data: listFixture() } })
  getUserMock.mockResolvedValue({ data: { data: user() } })
  getUserAddressesMock.mockResolvedValue({ data: { data: [] } })
  updateAdminAddressMock.mockResolvedValue({ data: { data: {} } })
  updateUserMock.mockResolvedValue({ data: { data: user() } })
  updateUserStatusMock.mockResolvedValue({ data: { data: user() } })
  changeUserPasswordMock.mockResolvedValue({ data: { data: user(), message: '密码已重置，用户需使用新密码重新登录' } })
})

async function mountView(permissions?: string[]) {
  const wrapper = mount(UserListView, { global: globalCfg(freshPinia(permissions)) })
  await flushPromises()
  return wrapper
}

function findButton(wrapper: ReturnType<typeof mountView>, text: string) {
  return wrapper.findAll('button').find((b) => b.text().trim() === text)
}

describe('用户管理 - 修改密码弹窗', () => {
  it('列表渲染买家行，且操作列含「改密」入口', async () => {
    const wrapper = await mountView()
    expect(wrapper.text()).toContain('buyer_demo')
    expect(findButton(wrapper, '改密')).toBeTruthy()
  })

  it('点击「改密」打开弹窗，初始确认按钮因未填而禁用', async () => {
    const wrapper = await mountView()
    await findButton(wrapper, '改密')!.trigger('click')
    await flushPromises()

    const confirm = findButton(wrapper, '确认重置')!
    expect(confirm.exists()).toBe(true)
    expect(confirm.attributes('disabled')).toBeDefined()
  })

  it('两次密码不一致时确认按钮保持禁用', async () => {
    const wrapper = await mountView()
    await findButton(wrapper, '改密')!.trigger('click')
    await flushPromises()

    const inputs = wrapper.findAll('input[type="password"]')
    expect(inputs).toHaveLength(2)
    await inputs[0].setValue('NewPass@2024')
    await inputs[1].setValue('NewPass@2025')
    await flushPromises()

    expect(findButton(wrapper, '确认重置')!.attributes('disabled')).toBeDefined()
  })

  it('填入合法密码后点击确认，调用接口并关闭弹窗', async () => {
    const wrapper = await mountView()
    await findButton(wrapper, '改密')!.trigger('click')
    await flushPromises()

    const inputs = wrapper.findAll('input[type="password"]')
    await inputs[0].setValue('NewPass@2024')
    await inputs[1].setValue('NewPass@2024')
    await flushPromises()

    const confirm = findButton(wrapper, '确认重置')!
    expect(confirm.attributes('disabled')).toBeUndefined()
    await confirm.trigger('click')
    await flushPromises()

    expect(changeUserPasswordMock).toHaveBeenCalledWith(100, {
      password: 'NewPass@2024',
      password_confirmation: 'NewPass@2024',
    })
    // 弹窗已关闭
    expect(findButton(wrapper, '确认重置')).toBeFalsy()
    // 显示成功提示
    expect(wrapper.text()).toContain('密码已重置')
  })
})
