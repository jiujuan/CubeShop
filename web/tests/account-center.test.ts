import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getProfileMock,
  updateProfileMock,
  changePasswordMock,
  uploadImageMock,
  getUnreadCountMock,
} = vi.hoisted(() => ({
  getProfileMock: vi.fn(),
  updateProfileMock: vi.fn(),
  changePasswordMock: vi.fn(),
  uploadImageMock: vi.fn(),
  getUnreadCountMock: vi.fn(),
}))

vi.mock('@/api/user', () => ({
  getProfile: getProfileMock,
  updateProfile: updateProfileMock,
  changePassword: changePasswordMock,
  uploadImage: uploadImageMock,
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  addToCart: vi.fn(),
  getCart: vi.fn(),
  getAddresses: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getRegions: vi.fn().mockResolvedValue({ data: { data: { regions: [] } } }),
  parseAddress: vi.fn(),
  createAddress: vi.fn(),
  updateAddress: vi.fn(),
  deleteAddress: vi.fn(),
  setDefaultAddress: vi.fn(),
  // 余额（P6）：AccountCenterView load() 会调用，缺失会走真实 axios 导致全量并发下偶发超时
  getBalance: vi.fn().mockResolvedValue({ data: { data: { balance: '0.00', total_recharge: '0.00', total_consume: '0.00' } } }),
  getRecharges: vi.fn().mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 5, total: 0, total_pages: 1 } } } }),
  getBalanceLogs: vi.fn().mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 5, total: 0, total_pages: 1 } } } }),
}))

vi.mock('@/api/notification', () => ({
  getUnreadCount: getUnreadCountMock,
  getNotifications: vi.fn(),
  markRead: vi.fn(),
  markAllRead: vi.fn(),
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getProducts: vi.fn(),
  getProduct: vi.fn(),
  getHot: vi.fn(),
}))

vi.mock('@/api/auth', () => ({
  getMe: vi.fn(),
  logout: vi.fn().mockResolvedValue(undefined),
}))

import AccountCenterView from '@/views/AccountCenterView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/orders', component: { template: '<div />' } },
      { path: '/account', component: { template: '<div />' } },
      { path: '/account/favorites', component: { template: '<div />' } },
      { path: '/account/histories', component: { template: '<div />' } },
      { path: '/account/addresses', component: { template: '<div />' } },
      { path: '/coupons/mine', component: { template: '<div />' } },
      { path: '/coupons/center', component: { template: '<div />' } },
      { path: '/notifications', component: { template: '<div />' } },
    ],
  })
}

const profile = {
  id: 1,
  username: 'buyer01',
  nickname: '小明',
  avatar: null,
  phone: '13800001111',
  email: null,
  roles: ['customer'],
}

describe('个人中心（AccountCenterView / T-026）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    getProfileMock.mockResolvedValue({ data: { data: profile } })
    getUnreadCountMock.mockResolvedValue({ data: { data: { count: 3 } } })
  })

  function mountCenter() {
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    router.push('/account')
    return { router, ...render(AccountCenterView, { global: { plugins: [router] } }) }
  }

  it('渲染概览分区、订单快捷入口与服务入口（含未读角标）', async () => {
    mountCenter()
    await waitFor(() => expect(screen.getByTestId('account-tabs')).toBeTruthy())
    expect(screen.getByTestId('shortcut-pending_receive')).toBeTruthy()
    expect(screen.getByTestId('entry-favorites')).toBeTruthy()
    await waitFor(() => expect(screen.getByTestId('entry-badge').textContent).toBe('3'))
  })

  it('点击待收货快捷入口跳转订单页并携带 tab 参数', async () => {
    const { router } = mountCenter()
    await waitFor(() => expect(screen.getByTestId('shortcut-pending_receive')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('shortcut-pending_receive'))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/orders'))
    expect(router.currentRoute.value.query.tab).toBe('pending_receive')
  })

  it('资料页保存昵称调用更新接口', async () => {
    updateProfileMock.mockResolvedValue({ data: { data: { ...profile, nickname: '新昵称' } } })
    mountCenter()
    await waitFor(() => expect(screen.getByTestId('account-tab-profile')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('account-tab-profile'))
    await waitFor(() => expect(screen.getByTestId('nickname-input')).toBeTruthy())
    await fireEvent.update(screen.getByTestId('nickname-input'), '新昵称')
    await fireEvent.click(screen.getByTestId('save-profile-btn'))
    await waitFor(() => expect(updateProfileMock).toHaveBeenCalledWith({ nickname: '新昵称' }))
  })
})

describe('修改密码（T-027 前端校验）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    getProfileMock.mockResolvedValue({ data: { data: profile } })
    getUnreadCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  })

  function mountSecurity() {
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    router.push('/account')
    return render(AccountCenterView, { global: { plugins: [router] } })
  }

  async function gotoSecurity() {
    // 全量并发时 jsdom 负载高，默认 1s 偶发不够（单跑稳定），放宽到 3s
    await waitFor(() => expect(screen.getByTestId('account-tab-security')).toBeTruthy(), { timeout: 3000 })
    await fireEvent.click(screen.getByTestId('account-tab-security'))
    await waitFor(() => expect(screen.getByTestId('change-password-btn')).toBeTruthy(), { timeout: 3000 })
  }

  it('新密码不符合强度规则时本地拦截，不调用接口', async () => {
    mountSecurity()
    await gotoSecurity()
    await fireEvent.update(screen.getByTestId('old-password'), 'OldPass123')
    await fireEvent.update(screen.getByTestId('new-password'), '12345678') // 纯数字
    await fireEvent.update(screen.getByTestId('confirm-password'), '12345678')
    await fireEvent.click(screen.getByTestId('change-password-btn'))

    await waitFor(() => expect(screen.getByTestId('pwd-error').textContent).toContain('字母与数字'))
    expect(changePasswordMock).not.toHaveBeenCalled()
  })

  it('两次密码不一致时本地拦截', async () => {
    mountSecurity()
    await gotoSecurity()
    await fireEvent.update(screen.getByTestId('old-password'), 'OldPass123')
    await fireEvent.update(screen.getByTestId('new-password'), 'NewPass123')
    await fireEvent.update(screen.getByTestId('confirm-password'), 'NewPass999')
    await fireEvent.click(screen.getByTestId('change-password-btn'))

    await waitFor(() => expect(screen.getByTestId('pwd-error').textContent).toContain('不一致'))
    expect(changePasswordMock).not.toHaveBeenCalled()
  })

  it('合法输入提交成功并提示其他设备已退出', async () => {
    changePasswordMock.mockResolvedValue({ data: { data: { revoked_tokens: 2 } } })
    mountSecurity()
    await gotoSecurity()
    await fireEvent.update(screen.getByTestId('old-password'), 'OldPass123')
    await fireEvent.update(screen.getByTestId('new-password'), 'NewPass123')
    await fireEvent.update(screen.getByTestId('confirm-password'), 'NewPass123')
    await fireEvent.click(screen.getByTestId('change-password-btn'))

    await waitFor(() => expect(changePasswordMock).toHaveBeenCalledWith({
      old_password: 'OldPass123',
      password: 'NewPass123',
      password_confirmation: 'NewPass123',
    }))
    await waitFor(() => expect(screen.getByTestId('account-tip').textContent).toContain('2 台其他设备'))
  })
})

import ShopHeader from '@/components/ShopHeader.vue'

describe('顶栏用户菜单（ShopHeader / T-026 入口）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    getUnreadCountMock.mockResolvedValue({ data: { data: { count: 0 } } })
  })

  it('登录后展开菜单，渲染个人中心/收藏/足迹/地址/通知入口', async () => {
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    router.push('/account')
    await router.isReady()
    render(ShopHeader, { global: { plugins: [router] } })

    await waitFor(() => expect(screen.getByTestId('user-menu-trigger')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('user-menu-trigger'))

    await waitFor(() => expect(screen.getByTestId('user-menu')).toBeTruthy())
    const menu = screen.getByTestId('user-menu')
    const hrefs = Array.from(menu.querySelectorAll('a')).map((a) => a.getAttribute('href'))
    expect(hrefs).toContain('/account')
    expect(hrefs).toContain('/account/favorites')
    expect(hrefs).toContain('/account/histories')
    expect(hrefs).toContain('/account/addresses')
    expect(hrefs).toContain('/notifications')
  })
})
