import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getOrderMock,
  applyRefundMock,
  getCategoriesMock,
  getAnnouncementsMock,
  getCartCountMock,
  uploadImageMock,
} = vi.hoisted(() => ({
  getOrderMock: vi.fn(),
  applyRefundMock: vi.fn(),
  getCategoriesMock: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getAnnouncementsMock: vi.fn().mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 5, total: 0, total_pages: 1 } } } }),
  getCartCountMock: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  uploadImageMock: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  getOrder: getOrderMock,
  applyRefund: applyRefundMock,
}))
vi.mock('@/api/shop', () => ({ getCategories: getCategoriesMock }))
vi.mock('@/api/announcement', () => ({ getAnnouncements: getAnnouncementsMock }))
vi.mock('@/api/user', () => ({ getCartCount: getCartCountMock, uploadImage: uploadImageMock }))

import RefundApplyView from '@/views/RefundApplyView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/orders/:id', component: { template: '<div />' } },
      { path: '/orders/:id/refund', component: { template: '<div />' } },
    ],
  })
}

const ORDER = {
  id: 'ord_abc123',
  order_no: 'CS20260915000123',
  status: 'shipped',
  pay_amount: '100.00',
  items: [
    {
      id: 'oi_1',
      sku_id: 'sku_pub_1',
      product_title: '测试商品A',
      sku_specs: { 规格: '标准' },
      sku_image: null,
      price: '50.00',
      quantity: 2,
      total_amount: '100.00',
    },
  ],
}

async function renderApply() {
  getOrderMock.mockResolvedValue({ data: { data: ORDER } })
  const router = makeRouter()
  router.push('/orders/ord_abc123/refund')
  await router.isReady()

  const utils = render(RefundApplyView, { global: { plugins: [router] } })
  await waitFor(() => expect(screen.getByTestId('refund-submit')).toBeTruthy())
  return { ...utils, router }
}

describe('售后申请页：仅退款 / 退货退款（WMS 退货基础）', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    const auth = useAuthStore()
    auth.token = 'fake-token'
    applyRefundMock.mockResolvedValue({
      data: { data: { refund_id: 'r1', refund_no: 'RF1', type: 'refund', amount: '100.00', status: 'pending', return_status: null } },
    })
    uploadImageMock.mockResolvedValue({ data: { data: { url: '/storage/uploads/products/20260919/u.jpg' } } })
  })

  it('默认「仅退款」，提交时调用 applyRefund(type=refund)', async () => {
    const { router } = await renderApply()
    const push = vi.spyOn(router, 'push')

    // 选原因
    await fireEvent.update(screen.getByTestId('refund-reason') as HTMLSelectElement, '商品质量问题')

    await fireEvent.click(screen.getByTestId('refund-submit'))

    await waitFor(() => expect(applyRefundMock).toHaveBeenCalledTimes(1))
    const [id, payload] = applyRefundMock.mock.calls[0]
    expect(id).toBe('ord_abc123')
    expect((payload as Record<string, unknown>).type).toBe('refund')
    expect((payload as Record<string, unknown>).return_details).toBeUndefined()
    await waitFor(() => expect(push).toHaveBeenCalledWith(expect.objectContaining({ query: { refund_ok: '1' } })))
  })

  it('切换到「退货退款」展示退货明细与物流单号，提交携带 return_details', async () => {
    const { router } = await renderApply()

    // 切到退货退款
    const returnBtn = Array.from(document.querySelectorAll('button')).find((b) => b.textContent?.trim() === '退货退款')
    expect(returnBtn).toBeTruthy()
    await fireEvent.click(returnBtn!)

    // 退货明细行出现（默认整件退 quantity=2）
    const qtyInput = screen.getByTestId('return-qty-0') as HTMLInputElement
    expect(qtyInput.value).toBe('2')

    // 填物流单号 + 原因
    await fireEvent.update(screen.getByTestId('return-tracking-no') as HTMLInputElement, 'SF1234567890')
    await fireEvent.update(screen.getByTestId('refund-reason') as HTMLSelectElement, '商品质量问题')

    await fireEvent.click(screen.getByTestId('refund-submit'))

    await waitFor(() => expect(applyRefundMock).toHaveBeenCalledTimes(1))
    const [, payload] = applyRefundMock.mock.calls[0]
    expect((payload as Record<string, unknown>).type).toBe('return_refund')
    expect((payload as Record<string, unknown>).return_tracking_no).toBe('SF1234567890')
    expect(Array.isArray((payload as Record<string, unknown>).return_details)).toBe(true)
    const details = (payload as Record<string, unknown>).return_details as Array<Record<string, unknown>>
    expect(details[0].sku_id).toBe('sku_pub_1')
    expect(details[0].quantity).toBe(2)
  })

  it('退货退款未填物流单号时禁用提交', async () => {
    await renderApply()

    const returnBtn = Array.from(document.querySelectorAll('button')).find((b) => b.textContent?.trim() === '退货退款')
    await fireEvent.click(returnBtn!)

    await fireEvent.update(screen.getByTestId('refund-reason') as HTMLSelectElement, '商品质量问题')
    // 不填物流单号

    const submit = screen.getByTestId('refund-submit') as HTMLButtonElement
    expect(submit.disabled).toBe(true)
  })

  it('上传凭证图片后提交携带 images', async () => {
    await renderApply()

    await fireEvent.update(screen.getByTestId('refund-reason') as HTMLSelectElement, '商品质量问题')

    // 模拟选择图片 → 触发上传
    const input = screen.getByTestId('refund-image-input') as HTMLInputElement
    Object.defineProperty(input, 'files', { value: [new File(['x'], 'a.png', { type: 'image/png' })] })
    await fireEvent(input, new Event('change'))

    await waitFor(() => expect(uploadImageMock).toHaveBeenCalled())

    await fireEvent.click(screen.getByTestId('refund-submit'))

    await waitFor(() => expect(applyRefundMock).toHaveBeenCalledTimes(1))
    const [, payload] = applyRefundMock.mock.calls[0]
    expect((payload as Record<string, unknown>).images).toEqual(['/storage/uploads/products/20260919/u.jpg'])
  })
})
