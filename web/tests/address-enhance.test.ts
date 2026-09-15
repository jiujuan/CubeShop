import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getRegionsMock,
  parseAddressMock,
  createAddressMock,
  deleteAddressMock,
  setDefaultAddressMock,
  getAddressesMock,
  getCartMock,
  createOrderMock,
} = vi.hoisted(() => ({
  getRegionsMock: vi.fn(),
  parseAddressMock: vi.fn(),
  createAddressMock: vi.fn(),
  deleteAddressMock: vi.fn(),
  setDefaultAddressMock: vi.fn(),
  getAddressesMock: vi.fn(),
  getCartMock: vi.fn(),
  createOrderMock: vi.fn(),
}))

vi.mock('@/api/user', () => ({
  getRegions: getRegionsMock,
  parseAddress: parseAddressMock,
  createAddress: createAddressMock,
  updateAddress: vi.fn(),
  deleteAddress: deleteAddressMock,
  setDefaultAddress: setDefaultAddressMock,
  getAddresses: getAddressesMock,
  getCart: getCartMock,
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  addToCart: vi.fn(),
  changePassword: vi.fn(),
  getProfile: vi.fn(),
  updateProfile: vi.fn(),
  uploadImage: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  createOrder: createOrderMock,
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getProducts: vi.fn(),
  getProduct: vi.fn(),
  getHot: vi.fn(),
}))

vi.mock('@/api/auth', () => ({ getMe: vi.fn(), logout: vi.fn() }))

import AddressForm from '@/components/AddressForm.vue'
import AddressView from '@/views/AddressView.vue'
import CheckoutView from '@/views/CheckoutView.vue'
import { useAuthStore } from '@/stores/auth'

const regions = {
  version: 'v1',
  provinces: [
    { name: '广东省', cities: [{ name: '深圳市', districts: ['南山区', '福田区'] }, { name: '广州市', districts: ['天河区'] }] },
    { name: '北京市', cities: [{ name: '北京市', districts: ['朝阳区', '海淀区'] }] },
  ],
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/checkout', component: { template: '<div />' } },
      { path: '/account/addresses', component: { template: '<div />' } },
      { path: '/orders/:id/pay', component: { template: '<div />' } },
    ],
  })
}

const addr = (over: Partial<Record<string, unknown>> = {}) => ({
  id: 1,
  contact_name: '张三',
  contact_phone: '138****1111',
  contact_phone_full: '13800001111',
  province: '广东省',
  city: '深圳市',
  district: '南山区',
  detail_address: '科技路 1 号',
  label: null,
  is_default: false,
  used_count: 0,
  last_used_at: null,
  ...over,
})

describe('地址表单（AddressForm / T-029）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    getRegionsMock.mockResolvedValue({ data: { data: regions } })
  })

  it('级联选择：选省后市/区联动，切换手输模式出现自由输入框', async () => {
    render(AddressForm, { global: { plugins: [] } })
    await waitFor(() => expect(screen.getByTestId('address-province')).toBeTruthy())
    // 等待级联数据加载完成（省选项就位）
    await waitFor(() =>
      expect(
        Array.from((screen.getByTestId('address-province') as HTMLSelectElement).options).map((o) => o.value),
      ).toContain('广东省'),
    )

    // 选择省份后城市选项出现
    await fireEvent.update(screen.getByTestId('address-province'), '广东省')
    await waitFor(() => expect(screen.getByTestId('address-city')).toBeTruthy())
    await waitFor(() =>
      expect(
        Array.from((screen.getByTestId('address-city') as HTMLSelectElement).options).map((o) => o.value),
      ).toContain('深圳市'),
    )

    // 切换到手动输入
    await fireEvent.click(screen.getByTestId('address-toggle-manual'))
    await waitFor(() => expect(screen.getByTestId('address-province-manual')).toBeTruthy())
    expect(screen.getByTestId('address-city-manual')).toBeTruthy()
  })

  it('标签选择：点击「家」写回 label', async () => {
    const { container } = render(AddressForm, { global: { plugins: [] } })
    await waitFor(() => expect(screen.getByTestId('address-label-家')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('address-label-家'))
    await waitFor(() => expect(container.querySelector('[data-testid="address-label-家"]')!.className).toContain('text-[#1677ff]'))
  })

  it('粘贴识别：调用解析接口并预填各字段', async () => {
    parseAddressMock.mockResolvedValue({
      data: {
        data: {
          contact_name: '李四', contact_phone: '13900002222',
          province: '北京市', city: '北京市', district: '朝阳区',
          detail_address: '建国路 88 号', confidence: 1,
        },
      },
    })
    render(AddressForm, { global: { plugins: [] } })
    await waitFor(() => expect(screen.getByTestId('address-paste')).toBeTruthy())

    await fireEvent.update(screen.getByTestId('address-paste'), '李四 13900002222 北京市朝阳区建国路88号')
    await fireEvent.click(screen.getByTestId('address-parse-btn'))

    await waitFor(() => expect(parseAddressMock).toHaveBeenCalled())
    await waitFor(() => expect((screen.getByTestId('address-name') as HTMLInputElement).value).toBe('李四'))
    expect((screen.getByTestId('address-phone') as HTMLInputElement).value).toBe('13900002222')
    expect((screen.getByTestId('address-detail') as HTMLInputElement).value).toBe('建国路 88 号')
  })
})

describe('地址列表（AddressView / T-029）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    getRegionsMock.mockResolvedValue({ data: { data: regions } })
  })

  it('常用地址按使用次数排序、默认地址置顶并展示标签', async () => {
    getAddressesMock.mockResolvedValue({
      data: {
        data: [
          addr({ id: 1, label: '家', used_count: 1, is_default: false }),
          addr({ id: 2, label: '公司', used_count: 9, is_default: true }),
          addr({ id: 3, used_count: 5, is_default: false }),
        ],
      },
    })
    const auth = useAuthStore()
    auth.token = 'tk'
    render(AddressView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByTestId('address-list')).toBeTruthy())
    const cards = Array.from(document.querySelectorAll('[data-testid^="address-card-"]'))
    // 默认地址(2)置顶；其余按 used_count 倒序：3(5) → 1(1)
    expect(cards[0].getAttribute('data-testid')).toBe('address-card-2')
    expect(cards[1].getAttribute('data-testid')).toBe('address-card-3')
    expect(cards[2].getAttribute('data-testid')).toBe('address-card-1')
    expect(screen.getAllByTestId('address-label-badge').length).toBe(2)
  })

  it('删除默认地址时提示会重新设置默认', async () => {
    getAddressesMock.mockResolvedValue({ data: { data: [addr({ id: 1, is_default: true })] } })
    deleteAddressMock.mockResolvedValue({ data: { data: null } })
    const auth = useAuthStore()
    auth.token = 'tk'
    render(AddressView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByTestId('address-card-1')).toBeTruthy())
    const delBtn = Array.from(screen.getByTestId('address-card-1').querySelectorAll('button')).find((b) => b.textContent?.includes('删除'))!
    await fireEvent.click(delBtn)
    await waitFor(() => expect(screen.getByTestId('confirm-dialog')).toBeTruthy())
    expect(screen.getByTestId('confirm-dialog').textContent).toContain('默认地址')
  })
})

describe('结算页内联新增地址（CheckoutView / T-029）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    getRegionsMock.mockResolvedValue({ data: { data: regions } })
    getCartMock.mockResolvedValue({
      data: {
        data: {
          items: [{ id: 1, valid: true, title: '耳机', specs: {}, price: '199.00', quantity: 1, subtotal: '199.00', image: null }],
        },
      },
    })
  })

  it('内联新增地址保存后自动选中且不丢失已填备注', async () => {
    getAddressesMock.mockResolvedValue({ data: { data: [addr({ id: 1 })] } })
    createAddressMock.mockResolvedValue({ data: { data: addr({ id: 99, contact_name: '王五' }) } })
    const auth = useAuthStore()
    auth.token = 'tk'
    render(CheckoutView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByTestId('checkout-address-1')).toBeTruthy())

    // 填备注
    const textarea = document.querySelector('textarea') as HTMLTextAreaElement
    await fireEvent.update(textarea, '尽快发货')

    // 打开内联新增
    await fireEvent.click(screen.getByTestId('checkout-add-address'))
    await waitFor(() => expect(screen.getByTestId('checkout-address-dialog')).toBeTruthy())

    // 填表并保存
    await fireEvent.update(screen.getByTestId('address-name'), '王五')
    await fireEvent.update(screen.getByTestId('address-phone'), '13700003333')
    await fireEvent.update(screen.getByTestId('address-province'), '广东省')
    await fireEvent.update(screen.getByTestId('address-city'), '深圳市')
    await fireEvent.update(screen.getByTestId('address-detail'), '深南大道 100 号')
    await fireEvent.click(screen.getByTestId('address-save'))

    await waitFor(() => expect(createAddressMock).toHaveBeenCalled())
    // 弹层关闭、新地址出现在列表并被选中
    await waitFor(() => expect(screen.queryByTestId('checkout-address-dialog')).toBeNull())
    await waitFor(() => expect(screen.getByTestId('checkout-address-99')).toBeTruthy())
    const radio = screen.getByTestId('checkout-address-99').querySelector('input[type="radio"]') as HTMLInputElement
    expect(radio.checked).toBe(true)
    // 备注保留
    expect((document.querySelector('textarea') as HTMLTextAreaElement).value).toBe('尽快发货')
  })

  it('无地址时展示空态并阻断下单', async () => {
    getAddressesMock.mockResolvedValue({ data: { data: [] } })
    const auth = useAuthStore()
    auth.token = 'tk'
    render(CheckoutView, { global: { plugins: [makeRouter()] } })

    await waitFor(() => expect(screen.getByTestId('checkout-address-empty')).toBeTruthy())
    const submit = screen.getByTestId('checkout-submit') as HTMLButtonElement
    expect(submit.disabled).toBe(true)
    await fireEvent.click(submit)
    expect(createOrderMock).not.toHaveBeenCalled()
  })
})
