import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { getPolicyMock, updatePolicyMock } = vi.hoisted(() => ({
  getPolicyMock: vi.fn(),
  updatePolicyMock: vi.fn(),
}))

vi.mock('@/api/refund', () => ({
  getRefundPolicy: getPolicyMock,
  updateRefundPolicy: updatePolicyMock,
}))

import RefundPolicyView from '@/views/refund/RefundPolicyView.vue'

const policyPayload = {
  auto_approve_amount: '0.00',
  max_retry: 3,
  dispute_sla_hours: 48,
  return_address_template: '',
}

beforeEach(() => {
  getPolicyMock.mockReset()
  updatePolicyMock.mockReset()
  getPolicyMock.mockResolvedValue({ data: { data: { ...policyPayload } } })
})

function mountView() {
  return mount(RefundPolicyView)
}

describe('退款策略配置页 RefundPolicyView', () => {
  it('加载后渲染四个策略项', async () => {
    getPolicyMock.mockResolvedValue({
      data: { data: { auto_approve_amount: '30.50', max_retry: 5, dispute_sla_hours: 24, return_address_template: '深圳市科技路 1 号' } },
    })
    const w = mountView()
    await flushPromises()

    expect(getPolicyMock).toHaveBeenCalledTimes(1)
    expect((w.find('[data-testid="policy-auto-approve"]').element as HTMLInputElement).value).toBe('30.50')
    expect((w.find('[data-testid="policy-max-retry"]').element as HTMLInputElement).value).toBe('5')
    expect((w.find('[data-testid="policy-sla"]').element as HTMLInputElement).value).toBe('24')
    expect((w.find('[data-testid="policy-return-address"]').element as HTMLTextAreaElement).value).toBe('深圳市科技路 1 号')
    expect(w.text()).toContain('退款策略')
  })

  it('保存提交四个字段并回显成功提示', async () => {
    updatePolicyMock.mockResolvedValue({
      data: { data: { auto_approve_amount: '10.00', max_retry: 2, dispute_sla_hours: 36, return_address_template: '新地址' } },
    })
    const w = mountView()
    await flushPromises()

    await w.find('[data-testid="policy-auto-approve"]').setValue('10.00')
    await w.find('[data-testid="policy-max-retry"]').setValue('2')
    await w.find('[data-testid="policy-sla"]').setValue('36')
    await w.find('[data-testid="policy-return-address"]').setValue('新地址')
    await w.find('[data-testid="policy-save"]').trigger('click')
    await flushPromises()

    // number 输入框的 v-model 产出 number 类型（后端 numeric 校验兼容），模板保持字符串
    expect(updatePolicyMock).toHaveBeenCalledWith({
      auto_approve_amount: 10,
      max_retry: 2,
      dispute_sla_hours: 36,
      return_address_template: '新地址',
    })
    expect(w.find('[data-testid="policy-tip"]').text()).toContain('已保存')
  })

  it('加载失败展示错误提示', async () => {
    getPolicyMock.mockRejectedValue(new Error('网络异常'))
    const w = mountView()
    await flushPromises()

    expect(w.find('[data-testid="policy-tip"]').text()).toBe('网络异常')
    expect(w.find('[data-testid="policy-form"]').exists()).toBe(false)
  })

  it('保存失败展示错误提示且不清空表单', async () => {
    updatePolicyMock.mockRejectedValue(new Error('无权限操作'))
    const w = mountView()
    await flushPromises()

    await w.find('[data-testid="policy-max-retry"]').setValue('5')
    await w.find('[data-testid="policy-save"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="policy-tip"]').text()).toBe('无权限操作')
    expect((w.find('[data-testid="policy-max-retry"]').element as HTMLInputElement).value).toBe('5')
  })
})
