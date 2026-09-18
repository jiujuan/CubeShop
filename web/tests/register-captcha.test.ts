import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { ApiBusinessError } from '@/api/request'

const { getCaptchaMock, registerMock, getMeMock } = vi.hoisted(() => ({
  getCaptchaMock: vi.fn(),
  registerMock: vi.fn(),
  getMeMock: vi.fn(),
}))

vi.mock('@/api/auth', () => ({
  getCaptcha: getCaptchaMock,
  register: registerMock,
  getMe: getMeMock,
}))

import RegisterView from '@/views/RegisterView.vue'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/register', component: RegisterView },
      { path: '/login', component: { template: '<div />' } },
    ],
  })
}

/** 后端统一错误响应：明细包在 data.errors（与 ApiResponse 一致） */
function fail422(errors: Record<string, string[]>) {
  return Promise.reject(new ApiBusinessError(40000, '参数校验失败', errors, 422))
}

const captcha = { captcha_id: 'cap-1', image: 'data:image/svg+xml;base64,AAA', expires_in: 300 }

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  getCaptchaMock.mockResolvedValue({ data: { data: { ...captcha } } })
  getMeMock.mockResolvedValue({ data: { data: { id: 1, username: 'u', nickname: null, roles: [] } } })
})

/** 挂载注册页并填好用户名与验证码 */
async function mountFilled(password: string, confirm = password) {
  const router = makeRouter()
  router.push('/register')
  await router.isReady()

  render(RegisterView, { global: { plugins: [router] } })

  await waitFor(() => expect(getCaptchaMock).toHaveBeenCalled())

  // 密码框是 type=password，role 不是 textbox，按 placeholder 定位
  const usernameEl = screen.getByPlaceholderText(/用户名/)
  const passwordEl = screen.getByPlaceholderText(/^密码/)
  const confirmEl = screen.getByPlaceholderText(/确认密码/)
  const codeEl = screen.getByPlaceholderText(/验证码/)
  await fireEvent.update(usernameEl, 'newuser001')
  await fireEvent.update(passwordEl, password)
  await fireEvent.update(confirmEl, confirm)
  await fireEvent.update(codeEl, 'AB12')

  return { usernameEl, passwordEl, confirmEl, codeEl }
}

async function clickSubmit() {
  const btn = screen.getAllByRole('button').find((b) => b.textContent?.includes('注'))
  await fireEvent.click(btn!)
}

describe('注册页校验提示（422「参数校验失败」定位）', () => {
  it('TC-REG-01 密码缺数字时本地拦截，给出明确规则提示且不发请求', async () => {
    await mountFilled('abcdefgh')
    await clickSubmit()

    expect(screen.getByTestId('register-error').textContent).toContain('密码至少 8 位，且需同时包含字母和数字')
    expect(registerMock).not.toHaveBeenCalled()
  })

  it('TC-REG-02 密码缺字母同样被拦截', async () => {
    await mountFilled('12345678')
    await clickSubmit()

    expect(screen.getByTestId('register-error').textContent).toContain('需同时包含字母和数字')
    expect(registerMock).not.toHaveBeenCalled()
  })

  it('TC-REG-03 合规密码正常提交', async () => {
    registerMock.mockResolvedValue({ data: { data: { token: 'tk', user: { id: 1, username: 'newuser001', nickname: null, roles: [] } } } })

    await mountFilled('Test@1234')
    await clickSubmit()

    await waitFor(() => expect(registerMock).toHaveBeenCalled())
    const payload = registerMock.mock.calls[0][0]
    expect(payload.username).toBe('newuser001')
    expect(payload.code).toBe('AB12')
    expect(payload.captcha_id).toBe('cap-1')
  })

  it('TC-REG-04 后端 422 时展示字段级明细，而不是笼统的「参数校验失败」', async () => {
    registerMock.mockImplementation(() => fail422({ password: ['密码过于简单或过于常见，请更换'] }))

    await mountFilled('Test@1234')
    await clickSubmit()

    await waitFor(() => expect(screen.getByTestId('register-error')).toBeTruthy())
    expect(screen.getByTestId('register-error').textContent).toContain('密码过于简单或过于常见，请更换')
    // 提交失败后自动刷新验证码，便于用户重试
    expect(getCaptchaMock.mock.calls.length).toBeGreaterThan(1)
  })

  it('TC-REG-05 账号被占用时展示后端给出的中文原因', async () => {
    registerMock.mockImplementation(() => fail422({ account: ['该账号信息不可用，请更换后重试'] }))

    await mountFilled('Test@1234')
    await clickSubmit()

    await waitFor(() => expect(screen.getByTestId('register-error').textContent).toContain('该账号信息不可用，请更换后重试'))
  })

  it('TC-REG-06 验证码接口失败时不提交，提示刷新验证码', async () => {
    getCaptchaMock.mockRejectedValue(new ApiBusinessError(429, '操作过于频繁，请稍后再试', undefined, 429))

    const router = makeRouter()
    router.push('/register')
    await router.isReady()
    render(RegisterView, { global: { plugins: [router] } })

    // 加载失败即提示，且 captcha 置空
    await waitFor(() => expect(screen.getByTestId('register-error').textContent).toContain('操作过于频繁，请稍后再试'))

    await fireEvent.update(screen.getByPlaceholderText(/用户名/), 'someone')
    await fireEvent.update(screen.getByPlaceholderText(/^密码/), 'Test@1234')
    await fireEvent.update(screen.getByPlaceholderText(/确认密码/), 'Test@1234')
    await fireEvent.update(screen.getByPlaceholderText(/验证码/), 'AB12')
    await clickSubmit()

    expect(registerMock).not.toHaveBeenCalled()
    expect(screen.getByTestId('register-error').textContent).toContain('验证码未加载成功')
  })
})
