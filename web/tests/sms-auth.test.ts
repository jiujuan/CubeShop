import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const {
  getCaptchaMock, getVerifyModeMock, sendSmsCodeMock,
  loginMock, loginBySmsCodeMock, registerMock, getMeMock,
} = vi.hoisted(() => ({
  getCaptchaMock: vi.fn(),
  getVerifyModeMock: vi.fn(),
  sendSmsCodeMock: vi.fn(),
  loginMock: vi.fn(),
  loginBySmsCodeMock: vi.fn(),
  registerMock: vi.fn(),
  getMeMock: vi.fn(),
}))

// ⚠️ 常量必须一并导出：mock 工厂漏掉它们，页面里的位数校验会拿到 undefined 而永远拦截提交
vi.mock('@/api/auth', () => ({
  getCaptcha: getCaptchaMock,
  getVerifyMode: getVerifyModeMock,
  sendSmsCode: sendSmsCodeMock,
  login: loginMock,
  loginBySmsCode: loginBySmsCodeMock,
  register: registerMock,
  getMe: getMeMock,
  CAPTCHA_LENGTH: 5,
  SMS_CODE_LENGTH: 6,
}))

import LoginView from '@/views/LoginView.vue'
import RegisterView from '@/views/RegisterView.vue'

/*
 * 短信验证码接入登录 / 注册（短信渠道计划 第一期）
 *
 * 钉的是四条容易被改坏的行为：
 * 1. 后台配好短信 → 登录页出现双 tab 且**默认落在验证码登录**（「优先走短信」）；
 * 2. 密码登录始终可用——短信就绪也不能只剩验证码登录；
 * 3. 发短信验证码**必须带图形验证码**（防刷闸门，不能因为改 UI 就漏传）；
 * 4. 后端返回 sent:false 时前端切回图形验证码，注册/登录不被配置问题打断。
 */
const captcha = { captcha_id: 'cap-1', image: 'data:image/svg+xml;base64,AAA', expires_in: 300 }
const VALID_CAPTCHA = 'AB12C'

function makeRouter(component: unknown, path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div>home</div>' } },
      { path, component: component as never },
      { path: '/login', component: { path: '/login', component: LoginView } as never },
      { path: '/register', component: RegisterView },
    ],
  })

  return router
}

function verifyMode(mode: 'sms' | 'captcha') {
  return {
    data: {
      data: {
        scene: 'login',
        mode,
        code_length: mode === 'sms' ? 6 : 5,
        reason: mode === 'sms' ? null : 'sms_disabled',
      },
    },
  }
}

async function mountAt(component: unknown, path: string) {
  const router = makeRouter(component, path)
  router.push(path)
  await router.isReady()

  render(component as never, { global: { plugins: [router] } })

  await waitFor(() => expect(getVerifyModeMock).toHaveBeenCalled())

  return router
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  getCaptchaMock.mockResolvedValue({ data: { data: { ...captcha } } })
  getMeMock.mockResolvedValue({ data: { data: { id: 1, username: 'u', nickname: null, roles: [] } } })
  getVerifyModeMock.mockResolvedValue(verifyMode('captcha'))
  sendSmsCodeMock.mockResolvedValue({ data: { data: { sent: true, mode: 'sms', code_length: 6, resend_after: 60 } } })
  loginMock.mockResolvedValue({ data: { data: { token: 't-pwd', user: { id: 1, username: 'u' } } } })
  loginBySmsCodeMock.mockResolvedValue({ data: { data: { token: 't-sms', user: { id: 1, username: 'u' } } } })
  registerMock.mockResolvedValue({ data: { data: { token: 't-reg', user: { id: 1, username: 'u' } } } })
})

describe('登录页：密码 / 短信验证码双通道', () => {
  it('TC-SMS-LOGIN-01 短信就绪时出现双 tab 并默认选中验证码登录', async () => {
    getVerifyModeMock.mockResolvedValue(verifyMode('sms'))

    await mountAt(LoginView, '/login')

    await waitFor(() => expect(screen.getByTestId('login-tabs')).toBeTruthy())
    // 默认落在验证码登录：渲染短信字段而不是用户名输入框
    await waitFor(() => expect(screen.getByTestId('login-sms-field')).toBeTruthy())
    expect(screen.queryByPlaceholderText('用户名 / 手机号')).toBeNull()
  })

  it('TC-SMS-LOGIN-02 短信不可用时只有密码登录，不出现 tab', async () => {
    await mountAt(LoginView, '/login')

    await waitFor(() => expect(screen.getByPlaceholderText('用户名 / 手机号')).toBeTruthy())
    expect(screen.queryByTestId('login-tabs')).toBeNull()
  })

  it('TC-SMS-LOGIN-03 短信就绪时仍可切回密码登录并提交', async () => {
    getVerifyModeMock.mockResolvedValue(verifyMode('sms'))

    await mountAt(LoginView, '/login')
    await waitFor(() => expect(screen.getByTestId('login-tab-password')).toBeTruthy())

    await fireEvent.click(screen.getByTestId('login-tab-password'))
    await waitFor(() => expect(screen.getByPlaceholderText('用户名 / 手机号')).toBeTruthy())

    await fireEvent.update(screen.getByPlaceholderText('用户名 / 手机号'), 'alice')
    await fireEvent.update(screen.getByPlaceholderText('密码'), 'Passw0rd1')
    await fireEvent.update(screen.getByPlaceholderText('验证码'), VALID_CAPTCHA)
    await fireEvent.click(screen.getByTestId('login-submit'))

    await waitFor(() => expect(loginMock).toHaveBeenCalled())
    expect(loginMock.mock.calls[0][0]).toMatchObject({ username: 'alice', captcha_id: 'cap-1' })
  })

  it('TC-SMS-LOGIN-04 发送短信验证码必须携带图形验证码（防刷）', async () => {
    getVerifyModeMock.mockResolvedValue(verifyMode('sms'))

    await mountAt(LoginView, '/login')
    await waitFor(() => expect(screen.getByTestId('sms-phone')).toBeTruthy())

    await fireEvent.update(screen.getByTestId('sms-phone'), '13800138000')
    await fireEvent.update(screen.getByTestId('sms-captcha-code'), VALID_CAPTCHA)
    await fireEvent.click(screen.getByTestId('sms-send'))

    await waitFor(() => expect(sendSmsCodeMock).toHaveBeenCalled())
    expect(sendSmsCodeMock.mock.calls[0][0]).toMatchObject({
      scene: 'login',
      phone: '13800138000',
      captcha_id: 'cap-1',
      captcha_code: VALID_CAPTCHA,
    })
  })

  it('TC-SMS-LOGIN-05 短信验证码登录提交 phone + sms_code', async () => {
    getVerifyModeMock.mockResolvedValue(verifyMode('sms'))

    await mountAt(LoginView, '/login')
    await waitFor(() => expect(screen.getByTestId('sms-code')).toBeTruthy())

    await fireEvent.update(screen.getByTestId('sms-phone'), '13800138000')
    await fireEvent.update(screen.getByTestId('sms-code'), '123456')
    await fireEvent.click(screen.getByTestId('login-submit'))

    await waitFor(() => expect(loginBySmsCodeMock).toHaveBeenCalled())
    expect(loginBySmsCodeMock.mock.calls[0][0]).toEqual({ phone: '13800138000', sms_code: '123456' })
  })

  it('TC-SMS-LOGIN-06 后端判定短信不可用（sent=false）时切回密码登录', async () => {
    getVerifyModeMock.mockResolvedValue(verifyMode('sms'))
    sendSmsCodeMock.mockResolvedValue({
      data: { data: { sent: false, mode: 'captcha', code_length: 5, reason: 'sms_disabled' } },
    })

    await mountAt(LoginView, '/login')
    await waitFor(() => expect(screen.getByTestId('sms-phone')).toBeTruthy())

    await fireEvent.update(screen.getByTestId('sms-phone'), '13800138000')
    await fireEvent.update(screen.getByTestId('sms-captcha-code'), VALID_CAPTCHA)
    await fireEvent.click(screen.getByTestId('sms-send'))

    // 回退后 tab 消失，密码表单回来
    await waitFor(() => expect(screen.queryByTestId('login-tabs')).toBeNull())
    await waitFor(() => expect(screen.getByPlaceholderText('用户名 / 手机号')).toBeTruthy())
  })

  it('TC-SMS-LOGIN-07 手机号非法时本地拦截，不发请求', async () => {
    getVerifyModeMock.mockResolvedValue(verifyMode('sms'))

    await mountAt(LoginView, '/login')
    await waitFor(() => expect(screen.getByTestId('sms-phone')).toBeTruthy())

    await fireEvent.update(screen.getByTestId('sms-phone'), '123')
    await fireEvent.update(screen.getByTestId('sms-code'), '123456')
    await fireEvent.click(screen.getByTestId('login-submit'))

    expect(loginBySmsCodeMock).not.toHaveBeenCalled()
    await waitFor(() => expect(screen.getByTestId('login-error').textContent).toContain('请输入正确的手机号'))
  })
})

describe('注册页：短信验证码模式', () => {
  it('TC-SMS-REG-01 短信就绪时提交携带 phone + sms_code 而非图形验证码', async () => {
    getVerifyModeMock.mockResolvedValue({
      data: { data: { scene: 'register', mode: 'sms', code_length: 6, reason: null } },
    })

    await mountAt(RegisterView, '/register')
    await waitFor(() => expect(screen.getByTestId('register-sms-field')).toBeTruthy())

    await fireEvent.update(screen.getByPlaceholderText('用户名 / 手机号'), 'bob')
    await fireEvent.update(screen.getByPlaceholderText('密码（≥8 位，含字母和数字）'), 'Passw0rd1')
    await fireEvent.update(screen.getByPlaceholderText('确认密码'), 'Passw0rd1')
    await fireEvent.update(screen.getByTestId('sms-phone'), '13800138000')
    await fireEvent.update(screen.getByTestId('sms-code'), '123456')
    await fireEvent.click(screen.getByTestId('register-submit'))

    await waitFor(() => expect(registerMock).toHaveBeenCalled())

    const payload = registerMock.mock.calls[0][0] as Record<string, string>
    expect(payload.phone).toBe('13800138000')
    expect(payload.sms_code).toBe('123456')
    expect(payload.code).toBeUndefined()
  })

  it('TC-SMS-REG-02 短信不可用时仍走图形验证码（回归）', async () => {
    await mountAt(RegisterView, '/register')

    await waitFor(() => expect(screen.getByTestId('register-captcha-code')).toBeTruthy())
    expect(screen.queryByTestId('register-sms-field')).toBeNull()

    await fireEvent.update(screen.getByPlaceholderText('用户名 / 手机号'), 'carl')
    await fireEvent.update(screen.getByPlaceholderText('密码（≥8 位，含字母和数字）'), 'Passw0rd1')
    await fireEvent.update(screen.getByPlaceholderText('确认密码'), 'Passw0rd1')
    await fireEvent.update(screen.getByTestId('register-captcha-code'), VALID_CAPTCHA)
    await fireEvent.click(screen.getByTestId('register-submit'))

    await waitFor(() => expect(registerMock).toHaveBeenCalled())
    expect(registerMock.mock.calls[0][0]).toMatchObject({ captcha_id: 'cap-1', code: VALID_CAPTCHA })
  })
})
