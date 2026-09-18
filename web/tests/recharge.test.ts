import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const api = vi.hoisted(() => ({
  getBalance: vi.fn(),
  createRecharge: vi.fn(),
  getRecharges: vi.fn(),
  getBalanceLogs: vi.fn(),
  getChannels: vi.fn(),
  uploadVoucher: vi.fn(),
  getProfile: vi.fn(),
  updateProfile: vi.fn(),
  changePassword: vi.fn(),
  uploadImage: vi.fn(),
  getUnreadCount: vi.fn(),
}))

vi.mock('@/api/balance', () => ({
  getBalance: api.getBalance,
  createRecharge: api.createRecharge,
  getRecharges: api.getRecharges,
  getBalanceLogs: api.getBalanceLogs,
}))

vi.mock('@/api/payment', () => ({
  getChannels: api.getChannels,
  uploadVoucher: api.uploadVoucher,
}))

vi.mock('@/api/user', () => ({
  getProfile: api.getProfile,
  updateProfile: api.updateProfile,
  changePassword: api.changePassword,
  uploadImage: api.uploadImage,
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
}))

vi.mock('@/api/notification', () => ({
  getUnreadCount: api.getUnreadCount,
  getNotifications: vi.fn(),
  markRead: vi.fn(),
  markAllRead: vi.fn(),
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getProducts: vi.fn(),
  getProduct: vi.fn(),
  getHot: vi.fn(),
  getAttributes: vi.fn(),
  getBrands: vi.fn(),
}))

vi.mock('@/api/auth', () => ({
  getMe: vi.fn(),
  logout: vi.fn().mockResolvedValue(undefined),
}))

import BalanceRechargeView from '@/views/BalanceRechargeView.vue'
import AccountCenterView from '@/views/AccountCenterView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/orders', component: { template: '<div />' } },
      { path: '/account', name: 'account', component: { template: '<div />' } },
      { path: '/account/favorites', component: { template: '<div />' } },
      { path: '/account/histories', component: { template: '<div />' } },
      { path: '/account/addresses', component: { template: '<div />' } },
      { path: '/notifications', component: { template: '<div />' } },
      { path: '/balance/recharge', name: 'balance-recharge', component: { template: '<div />' } },
      { path: '/pay/result/:payment_no', name: 'pay-result', component: { template: '<div />' } },
    ],
  })
}

const channelsData = {
  default_channel: 'wechat',
  channels: [
    { code: 'wechat', name: '微信支付', sort: 1, sandbox: true },
    { code: 'alipay', name: '支付宝', sort: 2, sandbox: true },
    {
      code: 'offline', name: '线下转账', sort: 3, sandbox: false,
      receipt: { bank_name: '工商银行', account_name: 'CubeShop', account_no: '6222 0000 1111' },
    },
  ],
  recharge: {
    enabled: true,
    amounts: ['50.00', '100.00', '200.00', '500.00'],
    min_amount: '10.00',
    max_single: '5000.00',
    max_daily: '20000.00',
    gift_rules: [{ amount: 100, gift: 10 }],
  },
}

const balanceData = { balance: '88.00', frozen: '0.00', total_recharge: '0.00', total_consume: '0.00' }

describe('余额充值页（BalanceRechargeView / P6）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    api.getChannels.mockResolvedValue({ data: { data: channelsData } })
    api.getBalance.mockResolvedValue({ data: { data: balanceData } })
  })

  function mountRecharge() {
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    router.push('/balance/recharge')
    return { router, ...render(BalanceRechargeView, { global: { plugins: [router] } }) }
  }

  it('渲染当前余额、面额按钮，选中面额命中赠送规则', async () => {
    mountRecharge()
    await waitFor(() => expect(screen.getByTestId('balance-display').textContent).toContain('88.00'))
    expect(screen.getByTestId('amount-100.00')).toBeTruthy()

    await fireEvent.click(screen.getByTestId('amount-100.00'))
    await waitFor(() => expect(screen.getByTestId('gift-hint').textContent).toContain('送 ¥10.00'))
  })

  it('提交充值调用接口并跳转结果页', async () => {
    api.createRecharge.mockResolvedValue({
      data: {
        data: {
          recharge_no: 'RC1', payment_no: 'PAY1', biz_type: 'recharge', amount: '50.00',
          gift_amount: '0.00', channel: 'wechat', status: 'pending',
          pay_params: { type: 'mock', sandbox_pay_url: '/api/payments/sandbox/PAY1' },
        },
      },
    })
    const { router } = mountRecharge()
    await waitFor(() => expect(screen.getByTestId('recharge-submit')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('recharge-submit'))

    await waitFor(() => expect(api.createRecharge).toHaveBeenCalledWith('50.00', 'wechat', undefined))
    await waitFor(() => expect(router.currentRoute.value.name).toBe('pay-result'))
    expect(router.currentRoute.value.params.payment_no).toBe('PAY1')
  })

  it('线下充值展开收款账户，缺必填项时拦截', async () => {
    mountRecharge()
    await waitFor(() => expect(screen.getByTestId('channel-offline')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('channel-offline'))
    await waitFor(() => expect(screen.getByTestId('offline-block')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('recharge-submit'))
    await waitFor(() => expect(screen.getByTestId('recharge-tip').textContent).toContain('付款人'))
    expect(api.createRecharge).not.toHaveBeenCalled()
  })

  it('充值总开关关闭时不展示表单', async () => {
    api.getChannels.mockResolvedValue({
      data: { data: { ...channelsData, recharge: { ...channelsData.recharge, enabled: false } } },
    })
    mountRecharge()
    await waitFor(() => expect(screen.getByText('余额充值功能暂未开放')).toBeTruthy())
  })
})

describe('账户中心余额区块（AccountCenterView / P6）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    api.getProfile.mockResolvedValue({
      data: {
        data: {
          id: 1, username: 'buyer01', nickname: '小明', avatar: null,
          phone: '13800001111', email: null, roles: ['customer'],
        },
      },
    })
    api.getUnreadCount.mockResolvedValue({ data: { data: { count: 0 } } })
    api.getBalance.mockResolvedValue({
      data: { data: { balance: '120.50', frozen: '0.00', total_recharge: '100.00', total_consume: '0.00' } },
    })
    api.getRecharges.mockResolvedValue({
      data: {
        data: {
          list: [{
            id: 1, recharge_no: 'RC1', amount: '100.00', gift_amount: '10.00', total: '110.00',
            channel: 'wechat', channel_label: '微信支付', status: 'success', status_label: '充值成功',
            paid_at: '2026-09-16 10:00:00', created_at: '2026-09-16 10:00:00',
          }],
          pagination: { page: 1, page_size: 5, total: 1, total_pages: 1 },
        },
      },
    })
    api.getBalanceLogs.mockResolvedValue({
      data: {
        data: {
          list: [{
            id: 1, type: 'recharge', type_label: '充值', amount: '110.00',
            balance_before: '0.00', balance_after: '110.00', related_type: 'recharge',
            remark: '余额充值 RC1', created_at: '2026-09-16 10:00:00',
          }],
          pagination: { page: 1, page_size: 5, total: 1, total_pages: 1 },
        },
      },
    })
  })

  function mountCenter() {
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    router.push('/account')
    return { router, ...render(AccountCenterView, { global: { plugins: [router] } }) }
  }

  it('概览展示余额，「充值」按钮跳转充值页', async () => {
    const { router } = mountCenter()
    await waitFor(() => expect(screen.getByTestId('balance-card')).toBeTruthy())
    expect(screen.getByTestId('balance-value').textContent).toContain('120.50')

    await fireEvent.click(screen.getByTestId('recharge-btn'))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/balance/recharge'))
  })

  it('余额 Tab 按需加载充值记录与流水', async () => {
    mountCenter()
    await waitFor(() => expect(screen.getByTestId('account-tab-balance')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('account-tab-balance'))

    await waitFor(() => expect(screen.getByTestId('recharge-row')).toBeTruthy())
    expect(api.getRecharges).toHaveBeenCalled()
    expect(screen.getByTestId('recharge-row').textContent).toContain('微信支付')
    await waitFor(() => expect(screen.getByTestId('log-row')).toBeTruthy())
    expect(screen.getByTestId('log-row').textContent).toContain('充值')
  })
})
