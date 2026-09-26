import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { getStatsMock, pushMock } = vi.hoisted(() => ({
  getStatsMock: vi.fn(),
  pushMock: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: pushMock }),
}))

vi.mock('@/api/refund', () => ({
  getRefundStats: getStatsMock,
  REFUND_STATUS_LABELS: {
    pending: '待审核',
    approved: '已同意',
    rejected: '已拒绝',
    success: '退款成功',
    failed: '退款失败',
    processing: '退款中',
  },
  REFUND_STATUS_CLASS: {
    pending: 'bg-orange-100 text-orange-500',
    approved: 'bg-blue-100 text-blue-500',
    rejected: 'bg-slate-100 text-slate-500',
    success: 'bg-green-100 text-green-600',
    failed: 'bg-red-100 text-red-500',
    processing: 'bg-cyan-100 text-cyan-600',
  },
}))

import RefundOverviewView from '@/views/refund/RefundOverviewView.vue'

const statsPayload = {
  status_counts: { pending: 2, approved: 1, rejected: 0, success: 3, failed: 1, processing: 1 },
  status_amounts: { pending: '200.00', approved: '100.00', rejected: '0.00', success: '60.00', failed: '30.00', processing: '50.00' },
  aging: { lt_24h: 2, h24_72: 1, gt_72h: 2 },
  queues: { processing_stuck: 1, failed_maxed: 1, return_waiting_overdue: 2 },
}

beforeEach(() => {
  pushMock.mockClear()
  getStatsMock.mockResolvedValue({ data: { data: statsPayload } })
})

function mountView() {
  return mount(RefundOverviewView)
}

describe('退款概览页', () => {
  it('加载 stats 后渲染状态指标卡（计数+金额）与账龄分桶', async () => {
    const w = mountView()
    await flushPromises()

    expect(getStatsMock).toHaveBeenCalledTimes(1)

    const pending = w.find('[data-testid="stats-status-pending"]')
    expect(pending.exists()).toBe(true)
    expect(pending.text()).toContain('2')
    expect(pending.text()).toContain('¥200.00')
    expect(w.find('[data-testid="stats-status-failed"]').text()).toContain('¥30.00')

    expect(w.find('[data-testid="stats-aging-lt-24h"]').text()).toContain('2')
    expect(w.find('[data-testid="stats-aging-h24-72"]').text()).toContain('1')
    expect(w.find('[data-testid="stats-aging-gt-72h"]').text()).toContain('2')
  })

  it('点击状态卡跳转退款处理页并携带 status 筛选', async () => {
    const w = mountView()
    await flushPromises()

    await w.find('[data-testid="stats-status-pending"]').trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ path: '/refunds', query: { status: 'pending' } })
  })

  it('点击异常队列卡携带对应组合筛选跳转', async () => {
    const w = mountView()
    await flushPromises()

    await w.find('[data-testid="stats-queue-processing_stuck"]').trigger('click')
    expect(pushMock).toHaveBeenCalledWith({
      path: '/refunds',
      query: { status: 'processing', aged_hours: '24' },
    })

    await w.find('[data-testid="stats-queue-failed_maxed"]').trigger('click')
    expect(pushMock).toHaveBeenCalledWith({
      path: '/refunds',
      query: { status: 'failed', retry_exhausted: '1' },
    })

    await w.find('[data-testid="stats-queue-return_waiting_overdue"]').trigger('click')
    expect(pushMock).toHaveBeenCalledWith({
      path: '/refunds',
      query: { type: 'return_refund', return_status: 'waiting_return', aged_hours: '168' },
    })
  })

  it('接口失败时展示错误提示', async () => {
    getStatsMock.mockRejectedValue(new Error('网络异常'))
    const w = mountView()
    await flushPromises()

    expect(w.text()).toContain('网络异常')
    expect(w.find('[data-testid="stats-status-pending"]').exists()).toBe(false)
  })
})
