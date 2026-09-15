import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * T-023 账号管理 / 角色权限（Vitest）
 * 覆盖：列表与角色标签、表单校验、自我保护、权限树半选、保存请求参数。
 */
const {
  getAccountsMock, createAccountMock, updateAccountMock, setAccountStatusMock,
  resetAccountPasswordMock, getRolesMock, createRoleMock, updateRoleMock, deleteRoleMock,
} = vi.hoisted(() => ({
  getAccountsMock: vi.fn(), createAccountMock: vi.fn(), updateAccountMock: vi.fn(),
  setAccountStatusMock: vi.fn(), resetAccountPasswordMock: vi.fn(),
  getRolesMock: vi.fn(), createRoleMock: vi.fn(), updateRoleMock: vi.fn(), deleteRoleMock: vi.fn(),
}))

vi.mock('@/api/account', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/account')>()
  return {
    ...actual,
    getAccounts: getAccountsMock,
    createAccount: createAccountMock,
    updateAccount: updateAccountMock,
    setAccountStatus: setAccountStatusMock,
    resetAccountPassword: resetAccountPasswordMock,
    getRoles: getRolesMock,
    createRole: createRoleMock,
    updateRole: updateRoleMock,
    deleteRole: deleteRoleMock,
  }
})

import AccountView from '@/views/system/AccountView.vue'
import RoleView from '@/views/system/RoleView.vue'

function freshPinia(currentUserId = 1) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: currentUserId, username: 'admin', nickname: '超管', avatar: null, phone: null, email: null,
    roles: ['super_admin'], permissions: [],
  }
  return pinia
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/accounts', component: AccountView },
      { path: '/roles', component: RoleView },
    ],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>, router = makeRouter()) => ({
  plugins: [pinia, router],
  directives: { permission },
})

const accountRows = [
  { id: 1, username: 'admin', nickname: '超管', email: null, phone: null, status: 1, roles: ['super_admin'], last_login_at: '2026-09-16 09:00:00', created_at: '2026-09-01 00:00:00' },
  { id: 2, username: 'op1', nickname: '运营甲', email: null, phone: null, status: 1, roles: ['operator'], last_login_at: null, created_at: '2026-09-02 00:00:00' },
]

const permissionGroups = [
  { module: 'order', label: '订单', permissions: ['order.view', 'order.ship'] },
  { module: 'product', label: '商品', permissions: ['product.view', 'product.create'] },
]

const rolesFixture = [
  { id: 1, name: 'super_admin', display_name: '超级管理员', label: '超级管理员', builtin: true, permissions: ['order.view', 'order.ship', 'product.view', 'product.create'], user_count: 1 },
  { id: 2, name: 'operator', display_name: '运营', label: '运营', builtin: true, permissions: ['order.view'], user_count: 1 },
  { id: 3, name: 'svc', display_name: '客服', label: '客服', builtin: false, permissions: [], user_count: 0 },
]

describe('T-023 账号管理 AccountView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getAccountsMock.mockResolvedValue({ data: { data: { list: accountRows, pagination: { page: 1, page_size: 20, total: 2, total_pages: 1 } } } })
  })

  it('渲染账号列表与角色中文标签', async () => {
    const wrapper = mount(AccountView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="account-row-1"]').text()).toContain('超级管理员')
    expect(wrapper.find('[data-testid="account-row-2"]').text()).toContain('运营')
    expect(wrapper.find('[data-testid="account-row-2"]').text()).toContain('从未登录')
  })

  it('当前登录账号的禁用入口为不可点击的自我保护态', async () => {
    const wrapper = mount(AccountView, { global: globalCfg(freshPinia(1)) })
    await flushPromises()

    const guard = wrapper.find('[data-testid="self-disable-guard"]')
    expect(guard.exists()).toBe(true)
    // 自我保护：不渲染可点击按钮，点击不会触发状态变更请求
    await guard.trigger('click')
    expect(setAccountStatusMock).not.toHaveBeenCalled()
  })

  it('新增账号：密码不合规时拦截且不发请求', async () => {
    const wrapper = mount(AccountView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="account-create-btn"]').trigger('click')
    await wrapper.find('[data-testid="account-password"]').setValue('abc') // 太短
    await wrapper.find('[data-testid="account-username"]').setValue('newop')
    await wrapper.findAll('button').find((b) => b.text().includes('保存'))!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="form-error"]').text()).toContain('8~32 位')
    expect(createAccountMock).not.toHaveBeenCalled()
  })

  it('新增账号：合法表单提交正确参数', async () => {
    createAccountMock.mockResolvedValue({ data: { data: { id: 9 } } })
    const wrapper = mount(AccountView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="account-create-btn"]').trigger('click')
    await wrapper.find('[data-testid="account-username"]').setValue('newop')
    await wrapper.find('[data-testid="account-password"]').setValue('Abcd1234')
    await wrapper.findAll('button').find((b) => b.text().includes('保存'))!.trigger('click')
    await flushPromises()

    expect(createAccountMock).toHaveBeenCalledWith(expect.objectContaining({
      username: 'newop',
      password: 'Abcd1234',
      roles: ['operator'],
    }))
  })
})

describe('T-023 角色权限 RoleView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getRolesMock.mockResolvedValue({ data: { data: { roles: rolesFixture, permission_groups: permissionGroups } } })
    updateRoleMock.mockResolvedValue({ data: { data: null } })
  })

  it('渲染角色列表并默认选中首个角色', async () => {
    const wrapper = mount(RoleView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="role-list"]').text()).toContain('超级管理员')
    expect(wrapper.find('[data-testid="role-list"]').text()).toContain('运营')
    expect(wrapper.find('[data-testid="permission-tree"]').exists()).toBe(true)
  })

  it('权限树展示分组与半选状态', async () => {
    const wrapper = mount(RoleView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    // 选中角色 operator 仅有 order.view → order 组半选
    await wrapper.find('[data-testid="role-item-operator"]').trigger('click')
    await flushPromises()

    const group = wrapper.find('[data-testid="group-checkbox-order"]')
    expect((group.element as HTMLInputElement).indeterminate).toBe(true)
    expect(wrapper.find('[data-testid="group-partial-order"]').exists()).toBe(true)
  })

  it('勾选权限后保存请求携带正确权限集合', async () => {
    const wrapper = mount(RoleView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="role-item-operator"]').trigger('click')
    await flushPromises()

    // 追加 order.ship（operator 原本只有 order.view）
    await wrapper.find('[data-testid="perm-order.ship"]').setValue(true)
    await wrapper.find('[data-testid="role-save-btn"]').trigger('click')
    await flushPromises()

    // 二次确认后提交
    const confirmBtn = Array.from(document.querySelectorAll('button')).find((b) => b.textContent?.trim() === '确认保存')
    expect(confirmBtn).toBeTruthy()
    await (confirmBtn as HTMLButtonElement).click()
    await flushPromises()

    expect(updateRoleMock).toHaveBeenCalledWith(2, { permissions: expect.arrayContaining(['order.view', 'order.ship']) })
  })

  it('权限未变化时提示并不发请求', async () => {
    const wrapper = mount(RoleView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="role-item-operator"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="role-save-btn"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="role-error"]').text()).toContain('未发生变化')
    expect(updateRoleMock).not.toHaveBeenCalled()
  })

  it('新增角色：标识 + 中文名 + 权限一页提交', async () => {
    createRoleMock.mockResolvedValue({ data: { data: { id: 10 } } })
    const wrapper = mount(RoleView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="role-create-btn"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="role-name-input"]').setValue('cs_agent')
    await wrapper.find('[data-testid="role-display-name-create-input"]').setValue('客服专员')
    await wrapper.find('[data-testid="create-perm-order.view"]').setValue(true)
    await wrapper.find('[data-testid="role-create-submit"]').trigger('click')
    await flushPromises()

    expect(createRoleMock).toHaveBeenCalledWith({
      name: 'cs_agent',
      display_name: '客服专员',
      permissions: ['order.view'],
    })
  })

  it('新增角色：缺少中文名时拦截且不发请求', async () => {
    const wrapper = mount(RoleView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="role-create-btn"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="role-name-input"]').setValue('cs_agent')
    await wrapper.find('[data-testid="role-create-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="role-create-error"]').text()).toContain('中文名')
    expect(createRoleMock).not.toHaveBeenCalled()
  })
})
