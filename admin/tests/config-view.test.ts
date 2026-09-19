import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const { getConfigsMock, updateConfigsMock, uploadSiteLogoMock } = vi.hoisted(() => ({
  getConfigsMock: vi.fn(),
  updateConfigsMock: vi.fn(),
  uploadSiteLogoMock: vi.fn(),
}))

vi.mock('@/api/admin', () => ({
  getConfigs: getConfigsMock,
  updateConfigs: updateConfigsMock,
  uploadSiteLogo: uploadSiteLogoMock,
}))

import ConfigView from '@/views/system/ConfigView.vue'

/** 与后端一致的形态：扁平列表，每项带 group 标签，已按 Tab 顺序排好 */
const cfg = (
  config_key: string,
  config_value: string,
  group: string,
  description: string | null = null,
) => ({ config_key, config_value, description, group, updated_at: '2026-09-20 10:00:00' })

const configFixture = () => [
  cfg('site.logo', '', '站点信息', '站点大 logo 图片地址'),
  cfg('site.logo_small', '', '站点信息', '站点小 logo 图片地址'),
  cfg('site.name', 'CubeShop', '站点信息', '电商站点名称'),
  cfg('auth.password_min_length', '8', '认证安全', '密码最小长度'),
  cfg('order.timeout_minutes', '30', '订单交易', '订单支付超时时间'),
]

function freshPinia(permissions: string[] = ['config.manage']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  // ⚠️ 角色必须是 operator：hasPermission 对 super_admin 直接短路放行，
  // 用 super_admin 会让「无权限」用例永远拿不到 false。
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

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/configs', component: ConfigView }],
  })
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia, makeRouter()],
  directives: { permission },
})

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getConfigsMock.mockResolvedValue({ data: { data: configFixture() } })
  updateConfigsMock.mockResolvedValue({ data: { code: 0 } })
})

async function mountView(permissions?: string[]) {
  const wrapper = mount(ConfigView, { global: globalCfg(freshPinia(permissions)) })
  await flushPromises()
  return wrapper
}

describe('系统设置页 ConfigView（分 Tab + 站点 logo）', () => {
  it('按分组渲染 Tab，默认选中第一个分组且只展示该组条目', async () => {
    const wrapper = await mountView()

    const tabs = wrapper.findAll('[data-testid="config-tabs"] button')
    expect(tabs.map((t) => t.text())).toEqual(['站点信息', '认证安全', '订单交易'])

    // 默认落在站点信息组
    expect(wrapper.find('[data-testid="config-input-site.name"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="config-input-site.name"]').element).toHaveProperty(
      'value',
      'CubeShop',
    )
    // 其它分组的条目不在当前面板
    expect(wrapper.find('[data-testid="config-input-auth.password_min_length"]').exists()).toBe(false)
  })

  it('切换 Tab 展示对应分组的配置项', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="config-tab-认证安全"]').trigger('click')
    expect(wrapper.find('[data-testid="config-input-auth.password_min_length"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="config-input-site.name"]').exists()).toBe(false)

    await wrapper.find('[data-testid="config-tab-订单交易"]').trigger('click')
    expect(wrapper.find('[data-testid="config-input-order.timeout_minutes"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="config-input-auth.password_min_length"]').exists()).toBe(false)
  })

  it('logo 项渲染上传控件，文本项渲染输入框', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="logo-input-site.logo"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="logo-input-site.logo_small"]').exists()).toBe(true)
    // logo 项不应出现文本输入框
    expect(wrapper.find('[data-testid="config-input-site.logo"]').exists()).toBe(false)
    // 未设置 logo 时展示占位（无预览图）
    expect(wrapper.find('[data-testid="logo-preview-site.logo"]').exists()).toBe(false)
  })

  it('上传 logo 后可预览，保存时提交新地址', async () => {
    const wrapper = await mountView()
    uploadSiteLogoMock.mockResolvedValue({ data: { data: { url: '/storage/uploads/site/x.png' } } })

    const input = wrapper.find('[data-testid="logo-input-site.logo"]')
    Object.defineProperty(input.element, 'files', {
      value: [new File(['x'], 'logo.png', { type: 'image/png' })],
    })
    await input.trigger('change')
    await flushPromises()

    expect(uploadSiteLogoMock).toHaveBeenCalledTimes(1)
    const preview = wrapper.find('[data-testid="logo-preview-site.logo"]')
    expect(preview.exists()).toBe(true)
    expect(preview.attributes('src')).toBe('/storage/uploads/site/x.png')

    await wrapper.find('[data-testid="config-save"]').trigger('click')
    await flushPromises()

    const payload = updateConfigsMock.mock.calls[0][0] as Array<{ config_key: string; config_value: string }>
    expect(payload.find((c) => c.config_key === 'site.logo')?.config_value).toBe(
      '/storage/uploads/site/x.png',
    )
    // 其它配置原样提交
    expect(payload.find((c) => c.config_key === 'site.name')?.config_value).toBe('CubeShop')
  })

  it('移除 logo 提交空串（用于清空）', async () => {
    getConfigsMock.mockResolvedValue({
      data: {
        data: [
          cfg('site.logo', '/storage/uploads/site/old.png', '站点信息', '站点大 logo 图片地址'),
          cfg('site.name', 'CubeShop', '站点信息', '电商站点名称'),
        ],
      },
    })
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="logo-preview-site.logo"]').exists()).toBe(true)
    await wrapper.find('[data-testid="logo-clear-site.logo"]').trigger('click')
    await wrapper.find('[data-testid="config-save"]').trigger('click')
    await flushPromises()

    const payload = updateConfigsMock.mock.calls[0][0] as Array<{ config_key: string; config_value: string }>
    expect(payload.find((c) => c.config_key === 'site.logo')?.config_value).toBe('')
  })

  it('站点名称置空时拦截保存并提示', async () => {
    const wrapper = await mountView()

    const nameInput = wrapper.find('[data-testid="config-input-site.name"]')
    await nameInput.setValue('   ')
    await wrapper.find('[data-testid="config-save"]').trigger('click')
    await flushPromises()

    expect(updateConfigsMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="config-error"]').text()).toContain('站点名称不能为空')
  })

  it('无 config.manage 权限时隐藏保存入口', async () => {
    const wrapper = await mountView([])

    expect(wrapper.find('[data-testid="config-save"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('config.manage')
  })
})
