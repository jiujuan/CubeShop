import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const api = vi.hoisted(() => ({
  getChannels: vi.fn(),
  createPayment: vi.fn(),
  uploadVoucher: vi.fn(),
  getPaymentStatus: vi.fn(),
  syncPayment: vi.fn(),
  sandboxPay: vi.fn(),
  getOrder: vi.fn(),
}))

vi.mock('@/api/payment', () => ({
  getChannels: api.getChannels,
  createPayment: api.createPayment,
  uploadVoucher: api.uploadVoucher,
  getPaymentStatus: api.getPaymentStatus,
  syncPayment: api.syncPayment,
  sandboxPay: api.sandboxPay,
}))

vi.mock('@/api/order', () => ({
  getOrder: api.getOrder,
}))

vi.mock('qrcode', () => ({
  default: { toDataURL: vi.fn().mockResolvedValue('data:image/png;base64,QR') },
}))

vi.mock('@/api/user', () => ({
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  getProfile: vi.fn(),
  updateProfile: vi.fn(),
  changePassword: vi.fn(),
  uploadImage: vi.fn(),
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
  getUnreadCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
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

import PayView from '@/views/PayView.vue'
import PayResultView from '@/views/PayResultView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/orders', component: { template: '<div />' } },
      { path: '/orders/:id', component: { template: '<div />' } },
      { path: '/orders/:id/pay', name: 'order-pay', component: { template: '<div />' } },
      { path: '/pay/result/:payment_no', name: 'pay-result', component: { template: '<div />' } },
      { path: '/balance/recharge', name: 'balance-recharge', component: { template: '<div />' } },
      { path: '/account', component: { template: '<div />' } },
    ],
  })
}

const order = {
  id: 1, order_no: 'CS20260916000001', status: 'pending_payment', pay_amount: '70.00',
  total_amount: '70.00', created_at: new Date().toISOString(), items: [], actions: { can_pay: true },
}

describe('收银台（PayView / P5）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    api.getOrder.mockResolvedValue({ data: { data: order } })
  })

  async function mount() {
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    router.push('/orders/1/pay')
    await router.isReady()
    return { router, ...render(PayView, { global: { plugins: [router] } }) }
  }

  it('渲染后端下发的渠道列表', async () => {
    api.getChannels.mockResolvedValue({
      data: {
        data: {
          default_channel: 'wechat',
          channels: [
            { code: 'wechat', name: '微信支付', sort: 1, sandbox: true },
            { code: 'alipay', name: '支付宝', sort: 2, sandbox: true },
            { code: 'balance', name: '余额支付', sort: 3, sandbox: false, balance: '500.00' },
            { code: 'offline', name: '线下转账', sort: 4, sandbox: false, receipt: { bank_name: '工商银行' } },
          ],
        },
      },
    })

    await mount()
    await waitFor(() => expect(screen.getByText('微信支付')).toBeTruthy())
    expect(screen.getByText('余额支付')).toBeTruthy()
    expect(screen.getByText('线下转账')).toBeTruthy()
    expect(screen.getByText('¥70.00')).toBeTruthy()
  })

  it('余额不足时置灰并提供「去充值」入口', async () => {
    api.getChannels.mockResolvedValue({
      data: {
        data: {
          default_channel: 'balance',
          channels: [
            { code: 'wechat', name: '微信支付', sort: 1, sandbox: true },
            { code: 'balance', name: '余额支付', sort: 2, sandbox: false, balance: '10.00' },
          ],
        },
      },
    })

    await mount()
    await waitFor(() => expect(screen.getByTestId('go-recharge')).toBeTruthy())
    expect(screen.getByText(/余额不足/)).toBeTruthy()
  })

  it('选择线下转账展开收款账户与凭证表单', async () => {
    api.getChannels.mockResolvedValue({
      data: {
        data: {
          default_channel: 'wechat',
          channels: [
            { code: 'wechat', name: '微信支付', sort: 1, sandbox: true },
            { code: 'offline', name: '线下转账', sort: 4, sandbox: false, receipt: { bank_name: '工商银行', account_name: 'CubeShop' } },
          ],
        },
      },
    })

    await mount()
    await waitFor(() => expect(screen.getByText('线下转账')).toBeTruthy())
    await fireEvent.click(screen.getByText('线下转账'))
    await waitFor(() => expect(screen.getByText('收款账户')).toBeTruthy())
    expect(screen.getByText('开户行：工商银行')).toBeTruthy()
  })
})

describe('支付结果页（PayResultView / P5）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  async function mountResult(path: string) {
    const auth = useAuthStore()
    auth.token = 'tk'
    const router = makeRouter()
    router.push(path)
    await router.isReady()
    return { router, ...render(PayResultView, { global: { plugins: [router] } }) }
  }

  it('充值成功：文案与「查看余额」按钮随 biz_type 切换', async () => {
    api.getPaymentStatus.mockResolvedValue({
      data: {
        data: {
          payment_no: 'PAY1', order_no: null, order_id: null, biz_type: 'recharge',
          channel: 'mock', amount: '100.00', status: 'success', paid_at: '2026-09-16 10:00:00',
        },
      },
    })
    const pp = encodeURIComponent(JSON.stringify({ type: 'mock', sandbox_pay_url: '/x' }))
    await mountResult(`/pay/result/PAY1?pp=${pp}`)

    await waitFor(() => expect(screen.getByText('充值成功')).toBeTruthy())
    expect(screen.getByText('余额已到账 ¥100.00')).toBeTruthy()
    expect(screen.getByText('查看余额')).toBeTruthy()
    expect(screen.getByText('返回账户中心')).toBeTruthy()
  })

  it('待核账：展示核账提示与备注', async () => {
    api.getPaymentStatus.mockResolvedValue({
      data: {
        data: {
          payment_no: 'PAY2', order_no: null, order_id: null, biz_type: 'order',
          channel: 'offline', amount: '70.00', status: 'reviewing',
          review_remark: '凭证不清',
        },
      },
    })
    const pp = encodeURIComponent(JSON.stringify({ type: 'voucher', receipt: { bank_name: '工商银行' } }))
    await mountResult(`/pay/result/PAY2?pp=${pp}`)

    await waitFor(() => expect(screen.getByText('待核账')).toBeTruthy())
    expect(screen.getByText('核账备注：凭证不清')).toBeTruthy()
    expect(screen.getByText('收款账户')).toBeTruthy()
  })

  it('处理中：轮询未完成时展示加载态', async () => {
    api.getPaymentStatus.mockResolvedValue({
      data: {
        data: {
          payment_no: 'PAY3', order_no: 'CS1', order_id: 1, biz_type: 'order',
          channel: 'wechat', amount: '70.00', status: 'pending',
        },
      },
    })
    const pp = encodeURIComponent(JSON.stringify({ type: 'qrcode', code_url: 'weixin://wxpay/abc' }))
    await mountResult(`/pay/result/PAY3?pp=${pp}`)

    await waitFor(() => expect(screen.getByText('支付处理中')).toBeTruthy())
    await waitFor(() => expect(screen.getByText('请使用微信扫码支付')).toBeTruthy())
  })
})
