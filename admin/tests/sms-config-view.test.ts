import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const {
  getSmsConfigMock,
  updateSmsSwitchesMock,
  updateSmsChannelMock,
  testSmsMock,
  getSmsLogsMock,
} = vi.hoisted(() => ({
  getSmsConfigMock: vi.fn(),
  updateSmsSwitchesMock: vi.fn(),
  updateSmsChannelMock: vi.fn(),
  testSmsMock: vi.fn(),
  getSmsLogsMock: vi.fn(),
}))

vi.mock('@/api/sms', () => ({
  getSmsConfig: getSmsConfigMock,
  updateSmsSwitches: updateSmsSwitchesMock,
  updateSmsChannel: updateSmsChannelMock,
  testSms: testSmsMock,
  getSmsLogs: getSmsLogsMock,
}))

import SmsConfigView from '@/views/system/SmsConfigView.vue'

/** 与后端 GET /admin/sms/config 契约一致的形态 */
const configFixture = () => ({
  channels: [
    {
      id: 1,
      provider: 'mock',
      provider_label: 'Mock 渠道（不发真短信）',
      available: true,
      name: 'Mock 渠道（不发真短信）',
      access_key_id: null,
      has_secret: false,
      secret_masked: null,
      sign_name: null,
      region: 'cn-hangzhou',
      is_enabled: true,
      credentials_complete: false,
      missing_credentials: ['access_key_id', 'access_key_secret', 'sign_name'],
      remark: '开发/测试默认渠道',
    },
    {
      id: 2,
      provider: 'aliyun',
      provider_label: '阿里云短信',
      available: true,
      name: '阿里云短信',
      access_key_id: 'LTAI_test',
      has_secret: true,
      secret_masked: '****8888',
      sign_name: 'CubeShop',
      region: 'cn-hangzhou',
      is_enabled: false,
      credentials_complete: true,
      missing_credentials: [],
      remark: '需填写 AccessKey',
    },
    {
      id: 3,
      provider: 'tencent',
      provider_label: '腾讯云短信',
      available: false,
      name: '腾讯云短信',
      access_key_id: null,
      has_secret: false,
      secret_masked: null,
      sign_name: null,
      region: 'ap-guangzhou',
      is_enabled: false,
      credentials_complete: false,
      missing_credentials: [],
      remark: '二期提供适配器',
    },
  ],
  active: { provider: 'mock', configured_provider: 'mock', degraded: false, error: null },
  switches: { enabled: false, code_scenes: [], code_templates: {} },
  options: {
    scenes: { register: '注册', reset_password: '重置密码' },
    providers: [
      { key: 'mock', label: 'Mock 渠道（不发真短信）', available: true },
      { key: 'aliyun', label: '阿里云短信', available: true },
      { key: 'tencent', label: '腾讯云短信', available: false },
    ],
  },
})

const logsFixture = () => ({
  list: [
    {
      id: 11,
      sms_config_id: 1,
      provider: 'mock',
      phone_masked: '138****8000',
      scene: 'register',
      template_code: 'SMS_1',
      status: 'sent',
      error_code: null,
      error_msg: null,
      biz_id: 'mock-1',
      latency_ms: 0,
      created_at: '2026-09-25 10:00:00',
    },
    {
      id: 12,
      sms_config_id: 2,
      provider: 'aliyun',
      phone_masked: '139****8001',
      scene: 'test',
      template_code: 'SMS_2',
      status: 'failed',
      error_code: 'isv.AMOUNT_NOT_ENOUGH',
      error_msg: '短信账户余额不足，请充值',
      biz_id: null,
      latency_ms: 120,
      created_at: '2026-09-25 10:01:00',
    },
  ],
  pagination: { page: 1, page_size: 20, total: 2, total_pages: 1, has_more: false },
})

function freshPinia(permissions: string[] = ['sms.view', 'sms.manage']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  // ⚠️ 用 operator 而非 super_admin：hasPermission 对 super_admin 短路放行，会架空无权限用例
  auth.user = {
    id: 1,
    username: 'operator',
    nickname: null,
    avatar: null,
    phone: null,
    email: null,
    roles: ['operator'],
    permissions,
  }
  return pinia
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia],
  directives: { permission },
})

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getSmsConfigMock.mockResolvedValue({ data: { code: 0, data: configFixture() } })
  getSmsLogsMock.mockResolvedValue({ data: { code: 0, data: logsFixture() } })
  updateSmsSwitchesMock.mockResolvedValue({
    data: { code: 0, data: { switches: { enabled: true, code_scenes: ['register'], code_templates: {} } } },
  })
  updateSmsChannelMock.mockResolvedValue({ data: { code: 0, data: configFixture().channels[1] } })
  testSmsMock.mockResolvedValue({
    data: { code: 0, data: { ok: true, error_code: null, error_msg: null, biz_id: 'biz-1', latency_ms: 88 } },
  })
})

async function mountView(permissions?: string[]) {
  const wrapper = mount(SmsConfigView, { global: globalCfg(freshPinia(permissions)) })
  await flushPromises()
  return wrapper
}

describe('短信渠道配置页 SmsConfigView（短信渠道计划 第一期）', () => {
  it('渲染三个渠道卡片：Mock 标记当前启用、腾讯云灰置「二期提供」', async () => {
    const wrapper = await mountView()

    expect(wrapper.findAll('[data-testid^="sms-channel-"]')).toHaveLength(3)
    expect(wrapper.find('[data-testid="sms-enabled-badge-mock"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="sms-channel-tencent"]').text()).toContain('二期提供')
    // 灰置渠道不渲染凭证表单
    expect(wrapper.find('[data-testid="sms-akid-tencent"]').exists()).toBe(false)
  })

  it('Secret 只回掩码，输入框 placeholder 提示「留空则不修改」', async () => {
    const wrapper = await mountView()

    const secret = wrapper.find('[data-testid="sms-secret-aliyun"]')
    expect((secret.element as HTMLInputElement).placeholder).toContain('****8888')
    expect((secret.element as HTMLInputElement).placeholder).toContain('留空则不修改')
    expect((secret.element as HTMLInputElement).value).toBe('')
  })

  it('保存凭证：Secret 留空提交空串（后端语义 = 不修改），保存后输入框清空', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="sms-akid-aliyun"]').setValue('LTAI_new')
    await wrapper.find('[data-testid="sms-sign-aliyun"]').setValue('新签名')
    await wrapper.find('[data-testid="sms-save-aliyun"]').trigger('click')
    await flushPromises()

    expect(updateSmsChannelMock).toHaveBeenCalledWith(2, {
      access_key_id: 'LTAI_new',
      access_key_secret: '',
      sign_name: '新签名',
      region: 'cn-hangzhou',
    })
    // 保存成功后本地 Secret 输入框复位，避免误以为已存了空串
    expect((wrapper.find('[data-testid="sms-secret-aliyun"]').element as HTMLInputElement).value).toBe('')
  })

  it('启用渠道只提交 is_enabled=true（互斥由后端事务保证）', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="sms-enable-aliyun"]').trigger('click')
    await flushPromises()

    expect(updateSmsChannelMock).toHaveBeenCalledWith(2, { is_enabled: true })
    expect(getSmsConfigMock).toHaveBeenCalledTimes(2)
  })

  it('改动开关后保存：提交 enabled / code_scenes / code_templates', async () => {
    const wrapper = await mountView()

    expect((wrapper.find('[data-testid="sms-save-switches"]').element as HTMLButtonElement).disabled).toBe(true)

    await wrapper.find('[data-testid="sms-switch-enabled"]').setValue(true)
    await wrapper.find('[data-testid="sms-scene-register"]').setValue(true)
    await wrapper.find('[data-testid="sms-template-register"]').setValue('SMS_123456')
    expect((wrapper.find('[data-testid="sms-save-switches"]').element as HTMLButtonElement).disabled).toBe(false)

    await wrapper.find('[data-testid="sms-save-switches"]').trigger('click')
    await flushPromises()

    expect(updateSmsSwitchesMock.mock.calls[0][0]).toEqual({
      enabled: true,
      code_scenes: ['register'],
      code_templates: { register: 'SMS_123456', reset_password: '' },
    })
  })

  it('无 sms.manage 权限：保存/启用/测试发送按钮全部禁用，页面仍可见', async () => {
    const wrapper = await mountView(['sms.view'])

    expect((wrapper.find('[data-testid="sms-save-switches"]').element as HTMLButtonElement).disabled).toBe(true)
    expect((wrapper.find('[data-testid="sms-save-aliyun"]').element as HTMLButtonElement).disabled).toBe(true)
    expect((wrapper.find('[data-testid="sms-enable-aliyun"]').element as HTMLButtonElement).disabled).toBe(true)
    expect((wrapper.find('[data-testid="sms-test-send"]').element as HTMLButtonElement).disabled).toBe(true)
    // 只读可见
    expect(wrapper.find('[data-testid="sms-channel-aliyun"]').exists()).toBe(true)
  })

  it('测试发送：按填参调用接口并回显结果；变量 JSON 非法时不下发请求', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="sms-test-phone"]').setValue('13800138000')
    await wrapper.find('[data-testid="sms-test-template"]').setValue('SMS_123456')
    await wrapper.find('[data-testid="sms-test-params"]').setValue('{"code":"123456"}')
    await wrapper.find('[data-testid="sms-test-send"]').trigger('click')
    await flushPromises()

    expect(testSmsMock.mock.calls[0][0]).toEqual({
      phone: '13800138000',
      template_code: 'SMS_123456',
      params: { code: '123456' },
    })
    expect(wrapper.find('[data-testid="sms-test-result"]').text()).toContain('biz-1')

    // 非法 JSON：提示且不请求（成功后手机号已清空，需重新填写才会重新启用按钮）
    await wrapper.find('[data-testid="sms-test-phone"]').setValue('13800138000')
    await wrapper.find('[data-testid="sms-test-params"]').setValue('{bad json')
    await wrapper.find('[data-testid="sms-test-send"]').trigger('click')
    await flushPromises()

    expect(testSmsMock).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="sms-tip"]').text()).toContain('合法 JSON')
  })

  it('渠道降级（凭证缺失回退 Mock）时显示降级提示条', async () => {
    const data = configFixture()
    data.active = {
      provider: 'mock',
      configured_provider: 'aliyun',
      degraded: true,
      error: null,
    }
    getSmsConfigMock.mockResolvedValue({ data: { code: 0, data } })

    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="sms-degraded"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="sms-degraded"]').text()).toContain('Mock')
  })

  it('发送记录渲染脱敏手机号与状态，筛选触发重新查询', async () => {
    const wrapper = await mountView()

    const rows = wrapper.findAll('tbody tr[data-testid^="sms-log-row-"]')
    expect(rows).toHaveLength(2)
    expect(wrapper.find('[data-testid="sms-log-row-11"]').text()).toContain('138****8000')
    expect(wrapper.find('[data-testid="sms-log-row-12"]').text()).toContain('失败')
    expect(wrapper.find('[data-testid="sms-log-row-12"]').text()).toContain('余额不足')

    // setValue 在 select 上会同时触发 change，无需再手动 trigger
    await wrapper.find('[data-testid="sms-log-status"]').setValue('failed')
    await flushPromises()

    expect(getSmsLogsMock).toHaveBeenCalledTimes(2)
    expect(getSmsLogsMock.mock.calls[1][0]).toMatchObject({ status: 'failed', page: 1 })
  })
})
