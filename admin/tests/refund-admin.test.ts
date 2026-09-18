import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

/**
 * 后台退款处理（Vitest）
 * 覆盖：列表渲染、详情弹层（商品明细/流水/用户凭证图）、同意/拒绝弹层（理由 + 图片上传）。
 */
const { getRefundsMock, getRefundDetailMock, processRefundMock, receiveRefundMock, uploadImageMock } = vi.hoisted(() => ({
  getRefundsMock: vi.fn(),
  getRefundDetailMock: vi.fn(),
  processRefundMock: vi.fn(),
  receiveRefundMock: vi.fn(),
  uploadImageMock: vi.fn(),
}))

vi.mock('@/api/refund', () => ({
  getRefunds: getRefundsMock,
  getRefundDetail: getRefundDetailMock,
  processRefund: processRefundMock,
  receiveRefund: receiveRefundMock,
  REFUND_STATUS_LABELS: { pending: '待审核', approved: '已同意', rejected: '已拒绝', success: '退款成功', failed: '退款失败' },
  REFUND_STATUS_CLASS: {
    pending: 'bg-orange-100 text-orange-500', approved: 'bg-blue-100 text-blue-500',
    rejected: 'bg-slate-100 text-slate-500', success: 'bg-green-100 text-green-600', failed: 'bg-red-100 text-red-500',
  },
  REFUND_TYPE_LABELS: { refund: '仅退款', return_refund: '退货退款' },
  RETURN_STATUS_LABELS: { waiting_return: '待退货', shipping: '退货中', received: '已收货', exception: '异常' },
  REFUND_ACTION_LABELS: {
    apply: '用户提交申请', process_approve: '后台同意退款', process_reject: '后台拒绝退款',
    return_received: '后台确认收货', coupon_returned: '返还优惠券',
  },
}))

vi.mock('@/api/product', () => ({ uploadImage: uploadImageMock }))

import RefundView from '@/views/refund/RefundView.vue'

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'admin', nickname: '管理员', avatar: null, phone: null, email: null,
    roles: ['super_admin'], permissions: ['refund.view', 'refund.process'],
  }
  return pinia
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/products/:id', component: { template: '<div />' } },
    ],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia, makeRouter()],
  directives: { permission },
})

const refundFixture = {
  id: 21,
  refund_no: 'RF20260919001',
  order_id: 5,
  order_no: 'CS20260919001',
  user_id: 3,
  type: 'refund' as const,
  amount: '88.00',
  reason: '商品破损',
  images: ['/storage/uploads/products/20260919/u1.jpg'],
  status: 'pending' as const,
  return_status: null,
  return_tracking_no: null,
  return_express_company: null,
  return_details: null,
  return_received_details: null,
  return_received_at: null,
  return_exception_reason: null,
  admin_remark: null,
  admin_images: [],
  processed_by: null,
  processed_by_name: null,
  processed_at: null,
  created_at: '2026-09-19 10:00:00',
  order_status: 'pending_ship',
}

const listResult = {
  list: [refundFixture],
  pagination: { page: 1, page_size: 20, total: 1, total_pages: 1 },
}

function mockDetail() {
  const applyData = {
    order_no: 'CS20260919001',
    refund_no: 'RF20260919001',
    type: 'refund',
    amount: '88.00',
    reason: '商品破损',
    images: ['/storage/uploads/products/20260919/u1.jpg'],
  }
  const approveData = {
    refund_no: 'RF20260919001',
    order_no: 'CS20260919001',
    type: 'refund',
    amount: '88.00',
    admin_remark: '核对无误，同意退款',
    admin_images: ['/storage/uploads/products/20260919/ok.jpg'],
  }
  getRefundDetailMock.mockResolvedValue({
    data: {
      data: {
        ...refundFixture,
        user: { id: 3, username: 'buyer', nickname: '买家甲', phone: '13800000000' },
        order: { order_no: 'CS20260919001', status: 'pending_ship', pay_amount: '88.00', created_at: '2026-09-19 09:59:00' },
        items: [
          {
            product_id: 77, product_public_id: 'p77', product_title: '测试商品A', sku_id: 8, sku_public_id: 's8',
            sku_specs: { 规格: '标准' }, sku_image: null, price: '88.00', quantity: 1, total_amount: '88.00',
          },
        ],
        logs: [
          {
            id: 1, actor_type: 'customer' as const, operator: { id: 3, username: 'buyer', nickname: '买家甲' },
            action: 'apply', content: JSON.stringify(applyData), content_data: applyData,
            created_at: '2026-09-19 10:00:00',
          },
          {
            id: 2, actor_type: 'admin' as const, operator: { id: 1, username: 'admin', nickname: '客服小李' },
            action: 'process_approve', content: JSON.stringify(approveData), content_data: approveData,
            created_at: '2026-09-19 10:05:00',
          },
        ],
      },
    },
  })
}

describe('后台退款处理 RefundView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getRefundsMock.mockResolvedValue({ data: { data: listResult } })
    processRefundMock.mockResolvedValue({ data: { data: { ...refundFixture, status: 'success' } } })
    uploadImageMock.mockResolvedValue({ data: { data: { url: '/storage/uploads/products/20260919/new.jpg' } } })
    mockDetail()
    freshPinia()
  })

  it('渲染列表行与凭证图计数', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.text()).toContain('RF20260919001')
    expect(wrapper.text()).toContain('仅退款')
    expect(wrapper.text()).toContain('¥88.00')
    expect(wrapper.text()).toContain('图1')
  })

  it('点击详情打开弹层并展示商品明细、用户凭证图与处理记录', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="detail-21"]').trigger('click')
    await flushPromises()

    expect(getRefundDetailMock).toHaveBeenCalledWith(21)
    expect(wrapper.find('[data-testid="refund-detail"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="detail-items"]').text()).toContain('测试商品A')
    expect(wrapper.find('[data-testid="detail-user-images"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="detail-logs"]').text()).toContain('用户提交申请')
    // 产品链接指向后台商品详情
    expect(wrapper.find('[data-testid="detail-items"] a[href="/products/77"]').exists()).toBe(true)
  })

  it('处理记录把 content JSON 转成中文键值，图片渲染为缩略图（不再展示原始 JSON）', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()
    await wrapper.find('[data-testid="detail-21"]').trigger('click')
    await flushPromises()

    const logs = wrapper.find('[data-testid="detail-logs"]')
    // 中文标签 + 值
    expect(logs.text()).toContain('退款理由')
    expect(logs.text()).toContain('商品破损')
    expect(logs.text()).toContain('退款类型')
    expect(logs.text()).toContain('仅退款')
    expect(logs.text()).toContain('退款金额')
    expect(logs.text()).toContain('¥88.00')
    expect(logs.text()).toContain('处理理由')
    expect(logs.text()).toContain('核对无误，同意退款')
    // 不再直接吐原始 JSON
    expect(logs.text()).not.toContain('{"')
    // 两条流水的图片（用户凭证图 + 后台说明图）共 2 张缩略图，且无跳转链接
    expect(logs.findAll('[data-testid="log-image"]').length).toBe(2)
    expect(logs.findAll('a').length).toBe(0)
  })

  it('处理记录的申请人显示买家昵称，后台动作显示操作员昵称', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()
    await wrapper.find('[data-testid="detail-21"]').trigger('click')
    await flushPromises()

    const entries = wrapper.findAll('[data-testid="log-entry"]')
    expect(entries.length).toBe(2)

    // 第 1 条：用户提交申请 → 操作人应为买家昵称（而非「管理员」）
    expect(entries[0].text()).toContain('用户提交申请')
    expect(entries[0].text()).toContain('买家甲')
    expect(entries[0].text()).not.toContain('客服小李')

    // 第 2 条：后台同意退款 → 操作人应为后台操作员昵称
    expect(entries[1].text()).toContain('后台同意退款')
    expect(entries[1].text()).toContain('客服小李')
  })

  it('点击处理记录中的图片在图层灯箱中放大，不跳转页面', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()
    await wrapper.find('[data-testid="detail-21"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="image-lightbox"]').exists()).toBe(false)

    await wrapper.find('[data-testid="log-image"]').trigger('click')
    await flushPromises()
    const box = wrapper.find('[data-testid="image-lightbox"]')
    expect(box.exists()).toBe(true)
    expect(box.find('img').attributes('src')).toBe('/storage/uploads/products/20260919/u1.jpg')

    await wrapper.find('[data-testid="lightbox-close"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="image-lightbox"]').exists()).toBe(false)
  })

  it('用户凭证图为按钮并走灯箱预览（无 target=_blank 链接）', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()
    await wrapper.find('[data-testid="detail-21"]').trigger('click')
    await flushPromises()

    const thumb = wrapper.find('[data-testid="user-image"]')
    expect(thumb.exists()).toBe(true)
    expect(thumb.element.tagName).toBe('BUTTON')
    expect(wrapper.find('[data-testid="detail-user-images"] a').exists()).toBe(false)

    await thumb.trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="image-lightbox"]').exists()).toBe(true)
  })

  it('点击同意弹出审核弹层，提交携带同意理由与图片', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="approve-21"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="process-dialog"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="process-dialog"]').text()).toContain('同意退款')

    await wrapper.find('[data-testid="process-remark"]').setValue('核对无误，同意')

    // 模拟选择图片 → 触发上传
    const input = wrapper.find('[data-testid="process-image-input"]')
    Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'a.png', { type: 'image/png' })] })
    await input.trigger('change')
    await flushPromises()
    expect(uploadImageMock).toHaveBeenCalled()

    await wrapper.find('[data-testid="process-submit"]').trigger('click')
    await flushPromises()

    expect(processRefundMock).toHaveBeenCalledWith(21, 'approve', expect.objectContaining({
      admin_remark: '核对无误，同意',
      admin_images: ['/storage/uploads/products/20260919/new.jpg'],
    }))
    // 刷新列表
    expect(getRefundsMock).toHaveBeenCalledTimes(2)
  })

  it('点击拒绝弹层标题为拒绝退款，提交携带拒绝理由', async () => {
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    await wrapper.find('[data-testid="reject-21"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="process-dialog"]').text()).toContain('拒绝退款')

    await wrapper.find('[data-testid="process-remark"]').setValue('商品已使用')
    await wrapper.find('[data-testid="process-submit"]').trigger('click')
    await flushPromises()

    expect(processRefundMock).toHaveBeenCalledWith(21, 'reject', expect.objectContaining({ admin_remark: '商品已使用' }))
  })

  it('已处理退款不再显示同意/拒绝，仅保留详情', async () => {
    getRefundsMock.mockResolvedValue({
      data: { data: { list: [{ ...refundFixture, status: 'success', admin_remark: '已退款', processed_by_name: '管理员' }], pagination: listResult.pagination } },
    })
    const wrapper = mount(RefundView, { global: globalCfg(freshPinia()) })
    await flushPromises()

    expect(wrapper.find('[data-testid="detail-21"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="approve-21"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="reject-21"]').exists()).toBe(false)
  })
})
