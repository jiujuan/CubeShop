import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import ShippingCard from '@/components/ShippingCard.vue'

const { getOrderShippingMock } = vi.hoisted(() => ({
  getOrderShippingMock: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  getOrderShipping: getOrderShippingMock,
}))

/** 构造 shipping 接口响应 */
function shippingPayload(overrides: Record<string, unknown> = {}) {
  return {
    express_company: '顺丰速运',
    tracking_no: 'SF2609170001',
    trace_status: 'in_transit',
    shipped_at: '2026-09-16 10:00:00',
    delivered_at: null,
    has_trace: true,
    traces: [
      { context: '快件正在派送途中', occurred_at: '2026-09-17 09:00:00' },
      { context: '快件已到达深圳转运中心', occurred_at: '2026-09-16 22:00:00' },
      { context: '快件已揽收', occurred_at: '2026-09-16 10:30:00' },
    ],
    ...overrides,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  // jsdom 无剪贴板，注入 mock
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
    configurable: true,
  })
})

describe('ShippingCard（V1.1 T-046）', () => {
  it('TC-SHIP-046-01 有轨迹渲染时间线且最新节点高亮', async () => {
    getOrderShippingMock.mockResolvedValue({ data: { data: shippingPayload() } })

    render(ShippingCard, { props: { orderId: 42 } })

    await waitFor(() => expect(screen.getByTestId('trace-list')).toBeTruthy())

    const items = screen.getByTestId('trace-list').querySelectorAll('li')
    expect(items.length).toBe(3)
    // 最新在顶 + 高亮「最新」标记
    expect(items[0].textContent).toContain('快件正在派送途中')
    expect(items[0].textContent).toContain('最新')
    expect(screen.getByTestId('latest-badge')).toBeTruthy()
    expect(items[2].textContent).not.toContain('最新')
    // 公司与单号、状态徽标
    expect(screen.getByText('顺丰速运')).toBeTruthy()
    expect(screen.getByText('SF2609170001')).toBeTruthy()
    expect(screen.getByText('运输中')).toBeTruthy()
  })

  it('TC-SHIP-046-02 复制按钮调用剪贴板并反馈已复制', async () => {
    getOrderShippingMock.mockResolvedValue({ data: { data: shippingPayload() } })

    render(ShippingCard, { props: { orderId: 42 } })
    await waitFor(() => expect(screen.getByTestId('copy-tracking')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('copy-tracking'))

    expect(navigator.clipboard.writeText).toHaveBeenCalledWith('SF2609170001')
    await waitFor(() => expect(screen.getByTestId('copy-tracking').textContent).toContain('已复制'))
  })

  it('TC-SHIP-046-03 无轨迹降级展示查询中外链', async () => {
    getOrderShippingMock.mockResolvedValue({
      data: { data: shippingPayload({ has_trace: false, traces: [] }) },
    })

    render(ShippingCard, { props: { orderId: 42 } })

    await waitFor(() => expect(screen.getByTestId('trace-empty')).toBeTruthy())
    expect(screen.getByTestId('trace-empty').textContent).toContain('暂无轨迹')
    // 官方查询外链按单号生成
    const link = screen.getByTestId('query-external') as HTMLAnchorElement
    expect(link.href).toContain('kuaidi100.com/chaxun')
    expect(link.href).toContain(encodeURIComponent('SF2609170001'))
    // 降级态下复制仍可用
    await fireEvent.click(screen.getByTestId('copy-tracking'))
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith('SF2609170001')
  })

  it('TC-SHIP-046-04 接口异常展示降级卡不白屏', async () => {
    getOrderShippingMock.mockRejectedValue(new Error('network down'))

    render(ShippingCard, { props: { orderId: 42 } })

    await waitFor(() => expect(screen.getByText('物流信息加载失败，请稍后刷新重试')).toBeTruthy())
    // 降级卡不渲染时间线，也不抛错
    expect(screen.queryByTestId('trace-list')).toBeNull()
  })

  it('TC-SHIP-046-05 未发货（data null）不渲染卡片', async () => {
    getOrderShippingMock.mockResolvedValue({ data: { data: null } })

    const { container } = render(ShippingCard, { props: { orderId: 42 } })

    // 等待加载骨架结束后（loading=false 且 shipping=null → 整卡不渲染）
    await waitFor(() => expect(getOrderShippingMock).toHaveBeenCalled())
    await new Promise((r) => setTimeout(r, 20))
    expect(container.querySelector('section')).toBeNull()
    expect(container.innerHTML).not.toContain('物流信息')
  })
})
