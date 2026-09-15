import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  submitReviewMock,
  updateReviewMock,
  getProductReviewsMock,
  getMyReviewsMock,
  uploadImageMock,
  getOrderMock,
} = vi.hoisted(() => ({
  submitReviewMock: vi.fn(),
  updateReviewMock: vi.fn(),
  getProductReviewsMock: vi.fn(),
  getMyReviewsMock: vi.fn(),
  uploadImageMock: vi.fn(),
  getOrderMock: vi.fn(),
}))

vi.mock('@/api/review', () => ({
  submitReview: submitReviewMock,
  updateReview: updateReviewMock,
  getProductReviews: getProductReviewsMock,
  getMyReviews: getMyReviewsMock,
}))

vi.mock('@/api/user', () => ({
  uploadImage: uploadImageMock,
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  addToCart: vi.fn(),
  getCart: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  getOrder: getOrderMock,
  getOrders: vi.fn().mockResolvedValue({ data: { data: { list: [], pagination: { page: 1, page_size: 10, total: 0, total_pages: 1 } } } }),
  confirmOrder: vi.fn(),
  cancelOrder: vi.fn(),
  rebuyOrder: vi.fn(),
  applyRefund: vi.fn(),
  ORDER_TABS: [{ value: 'all', label: '全部', empty: '暂无订单' }],
}))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
  getProducts: vi.fn(),
  getProduct: vi.fn(),
  getHot: vi.fn(),
}))

import ReviewForm from '@/components/ReviewForm.vue'
import ReviewSection from '@/components/ReviewSection.vue'
import OrderDetailView from '@/views/OrderDetailView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/cart', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/orders', component: { template: '<div />' } },
      { path: '/orders/:id', component: { template: '<div />' } },
      { path: '/orders/:id/pay', component: { template: '<div />' } },
    ],
  })
}

function summaryStub(overrides: Record<string, unknown> = {}) {
  return {
    avg: 3.6,
    total: 5,
    star_counts: { 1: 1, 2: 0, 3: 1, 4: 1, 5: 2 },
    good_rate: 60,
    ...overrides,
  }
}

function reviewRow(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    rating: 5,
    content: '很好用',
    images: [],
    is_anonymous: false,
    status: 'approved',
    reply_content: null,
    reply_at: null,
    edited_at: null,
    created_at: '2026-09-16 10:00:00',
    nickname: '小明',
    avatar: null,
    ...overrides,
  }
}

// ---------- ReviewForm（T-016） ----------

describe('评价表单 ReviewForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function renderForm(props: Record<string, unknown> = {}) {
    return render(ReviewForm, { props: { orderId: 1, itemId: 10, ...props } })
  }

  it('星级选择更新文案提示', async () => {
    renderForm()
    await fireEvent.click(screen.getByTestId('review-star-4'))
    expect(screen.getByTestId('review-rating-text').textContent).toBe('满意')

    await fireEvent.click(screen.getByTestId('review-star-5'))
    expect(screen.getByTestId('review-rating-text').textContent).toBe('非常满意')
  })

  it('未选评分时拦截提交且不调用接口', async () => {
    renderForm()
    await fireEvent.click(screen.getByTestId('review-submit'))

    expect(screen.getByTestId('review-tip').textContent).toContain('请先选择评分')
    expect(submitReviewMock).not.toHaveBeenCalled()
  })

  it('提交成功展示「评价成功」并携带评分/内容/匿名', async () => {
    submitReviewMock.mockResolvedValue({ data: { data: { id: 1, rating: 4, status: 'approved' } } })
    renderForm()

    await fireEvent.click(screen.getByTestId('review-star-4'))
    await fireEvent.update(screen.getByTestId('review-content'), '性价比高')
    await fireEvent.click(screen.getByTestId('review-anonymous'))
    await fireEvent.click(screen.getByTestId('review-submit'))

    await waitFor(() => expect(screen.getByTestId('review-tip').textContent).toBe('评价成功'))
    expect(submitReviewMock).toHaveBeenCalledWith(1, 10, {
      rating: 4,
      content: '性价比高',
      images: undefined,
      is_anonymous: true,
    })
  })

  it('审核模式下提示待审核', async () => {
    submitReviewMock.mockResolvedValue({ data: { data: { id: 2, rating: 5, status: 'pending' } } })
    renderForm()

    await fireEvent.click(screen.getByTestId('review-star-5'))
    await fireEvent.click(screen.getByTestId('review-submit'))

    await waitFor(() =>
      expect(screen.getByTestId('review-tip').textContent).toBe('评价已提交，审核通过后展示'),
    )
  })

  it('编辑模式回填并调用修改接口', async () => {
    updateReviewMock.mockResolvedValue({ data: { data: reviewRow({ id: 9 }) } })
    render(ReviewForm, {
      props: {
        initial: reviewRow({ id: 9, rating: 3, content: '初版内容', is_anonymous: false }),
      },
    })

    // 回填
    expect((screen.getByTestId('review-content') as HTMLTextAreaElement).value).toBe('初版内容')

    await fireEvent.update(screen.getByTestId('review-content'), '改版内容')
    await fireEvent.click(screen.getByTestId('review-submit'))

    await waitFor(() => expect(updateReviewMock).toHaveBeenCalled())
    expect(updateReviewMock.mock.calls[0][0]).toBe(9)
    expect(updateReviewMock.mock.calls[0][1].content).toBe('改版内容')
  })

  it('上传图片后展示缩略图且可删除', async () => {
    uploadImageMock.mockResolvedValue({ data: { data: { url: 'https://cdn.example.com/x.jpg' } } })
    renderForm()

    // 直接触发 label 内 input 的 change
    const picker = screen.getByTestId('review-image-picker')
    const input = picker.querySelector('input[type="file"]') as HTMLInputElement
    const file = new File(['x'], 'x.jpg', { type: 'image/jpeg' })
    Object.defineProperty(input, 'files', { value: [file] })
    await fireEvent.change(input)

    await waitFor(() => expect(screen.getByTestId('review-image-remove-0')).toBeTruthy())
    expect(uploadImageMock).toHaveBeenCalled()

    await fireEvent.click(screen.getByTestId('review-image-remove-0'))
    expect(screen.queryByTestId('review-image-remove-0')).toBeNull()
  })
})

// ---------- ReviewSection（T-016） ----------

describe('商品评价区 ReviewSection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function mockReviews(list: unknown[]) {
    getProductReviewsMock.mockResolvedValue({
      data: { data: { summary: summaryStub(), list, pagination: { page: 1, page_size: 5, total: list.length, total_pages: 1 } } },
    })
  }

  it('渲染评分汇总与评价列表', async () => {
    mockReviews([reviewRow({ id: 1, content: '很好用', reply_content: '感谢支持' })])
    render(ReviewSection, { props: { productId: 8 } })

    await waitFor(() => expect(screen.getByTestId('review-summary')).toBeTruthy())
    expect(screen.getByTestId('review-avg').textContent).toBe('3.6')
    expect(screen.getByTestId('review-good-rate').textContent).toBe('60%')
    expect(screen.getAllByTestId('review-item')).toHaveLength(1)
    expect(screen.getByTestId('review-reply').textContent).toContain('感谢支持')
  })

  it('无评价时展示空态', async () => {
    mockReviews([])
    render(ReviewSection, { props: { productId: 8 } })

    await waitFor(() => expect(screen.getByTestId('review-empty')).toBeTruthy())
    expect(screen.getByTestId('review-empty').textContent).toBe('暂无评价，期待你的第一条评价')
  })

  it('点击星级筛选携带 rating 参数', async () => {
    mockReviews([reviewRow()])
    render(ReviewSection, { props: { productId: 8 } })
    await waitFor(() => expect(screen.getByTestId('review-summary')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('review-filter-5'))

    await waitFor(() => {
      const last = getProductReviewsMock.mock.calls.at(-1)!
      expect(last[1].rating).toBe(5)
    })
  })

  it('切换排序携带 sort 参数', async () => {
    mockReviews([reviewRow()])
    render(ReviewSection, { props: { productId: 8 } })
    await waitFor(() => expect(screen.getByTestId('review-summary')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('review-sort-rating_desc'))

    await waitFor(() => {
      const last = getProductReviewsMock.mock.calls.at(-1)!
      expect(last[1].sort).toBe('rating_desc')
    })
  })

  it('点击图片放大并可关闭', async () => {
    mockReviews([reviewRow({ images: ['https://cdn.example.com/a.jpg'] })])
    render(ReviewSection, { props: { productId: 8 } })

    await waitFor(() => expect(screen.getByTestId('review-item')).toBeTruthy())
    const img = screen.getByTestId('review-item').querySelector('img[src="https://cdn.example.com/a.jpg"]') as HTMLImageElement
    await fireEvent.click(img)

    expect(screen.getByTestId('review-lightbox')).toBeTruthy()
  })
})

// ---------- 订单详情评价入口（T-016） ----------

describe('订单详情评价入口', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    const auth = useAuthStore()
    auth.token = 'test-token'
  })

  function orderWithItem(overrides: Record<string, unknown> = {}) {
    return {
      id: 100,
      order_no: 'CS100',
      status: 'completed',
      status_label: '已完成',
      total_amount: '58.00',
      freight_amount: '0.00',
      pay_amount: '58.00',
      item_count: 1,
      items_preview: [],
      items: [
        {
          id: 501,
          product_id: 8,
          sku_id: 9,
          product_title: '测试商品',
          sku_specs: { 颜色: '黑' },
          sku_image: null,
          price: '58.00',
          quantity: 1,
          total_amount: '58.00',
          review: null,
        },
      ],
      actions: { can_pay: false, can_cancel: false, can_confirm: false, can_refund: true, can_review: true, can_rebuy: true },
      remark: null,
      address_snapshot: { contact_name: '张三', contact_phone: '13800000000', full_address: '深圳市' },
      cancel_reason: null,
      refunds: [],
      logs: [],
      paid_at: null,
      shipped_at: null,
      completed_at: '2026-09-16 10:00:00',
      cancelled_at: null,
      created_at: '2026-09-16 10:00:00',
      ...overrides,
    }
  }

  it('已完成且未评价的行项目显示「评价」按钮，点击展开表单', async () => {
    getOrderMock.mockResolvedValue({ data: { data: orderWithItem() } })
    const router = makeRouter()
    router.push('/orders/100')
    await router.isReady()

    render(OrderDetailView, { global: { plugins: [router] } })

    await waitFor(() => expect(screen.getByTestId('review-item-501')).toBeTruthy())
    await fireEvent.click(screen.getByTestId('review-item-501'))

    expect(screen.getByTestId('review-form')).toBeTruthy()
  })

  it('已评价且可修改的行项目显示星级与「修改评价」', async () => {
    getOrderMock.mockResolvedValue({
      data: {
        data: orderWithItem({
          items: [
            {
              id: 502,
              product_id: 8,
              sku_id: 9,
              product_title: '测试商品',
              sku_specs: {},
              sku_image: null,
              price: '58.00',
              quantity: 1,
              total_amount: '58.00',
              review: { id: 77, rating: 4, content: '不错', status: 'approved', can_edit: true },
            },
          ],
        }),
      },
    })
    const router = makeRouter()
    router.push('/orders/100')
    await router.isReady()

    render(OrderDetailView, { global: { plugins: [router] } })

    await waitFor(() => expect(screen.getByTestId('item-reviewed')).toBeTruthy())
    expect(screen.getByTestId('edit-review-502')).toBeTruthy()
    // 不再显示「评价」按钮
    expect(screen.queryByTestId('review-item-502')).toBeNull()
  })
})
