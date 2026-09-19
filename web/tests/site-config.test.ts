import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import ShopHeader from '@/components/ShopHeader.vue'
import { setTitleBase, useSiteStore } from '@/stores/site'

/**
 * 站点品牌信息（P-SiteConfig）
 *
 * 覆盖：缓存水合（刷新不闪默认名）、回源覆盖、回源失败保留缓存、大小 logo 回落、
 *      顶栏渲染站点名与 logo、浏览器标题按站点名替换。
 */

const { getSiteConfigMock } = vi.hoisted(() => ({ getSiteConfigMock: vi.fn() }))

vi.mock('@/api/site', () => ({ getSiteConfig: getSiteConfigMock }))

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({ data: { data: [] } }),
}))
vi.mock('@/api/user', () => ({
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
}))
vi.mock('@/api/notification', () => ({
  getUnreadCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  getNotifications: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
  markNotificationsRead: vi.fn(),
}))
vi.mock('@/api/auth', () => ({ getMe: vi.fn(), logout: vi.fn() }))
vi.mock('@/api/announcement', () => ({
  getAnnouncements: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
}))

function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return pinia
}

function cacheSite(info: { name: string; logo: string; logo_small: string }) {
  localStorage.setItem('site_info', JSON.stringify(info))
}

beforeEach(() => {
  vi.clearAllMocks()
  localStorage.clear()
  document.title = ''
  getSiteConfigMock.mockResolvedValue({
    data: { data: { name: 'CubeShop', logo: '', logo_small: '' } },
  })
})

describe('站点信息 store', () => {
  it('初始化时同步水合本地缓存（刷新瞬间即是上次的品牌）', () => {
    cacheSite({ name: '星尘商城', logo: '/storage/uploads/site/big.png', logo_small: '/storage/uploads/site/small.png' })
    freshPinia()

    const site = useSiteStore()

    expect(site.name).toBe('星尘商城')
    expect(site.logo).toBe('/storage/uploads/site/big.png')
    expect(site.logoSmall).toBe('/storage/uploads/site/small.png')
    expect(site.loaded).toBe(false)
  })

  it('回源成功后更新品牌并写回缓存', async () => {
    freshPinia()
    const site = useSiteStore()
    getSiteConfigMock.mockResolvedValue({
      data: { data: { name: '云集优选', logo: '/logo.png', logo_small: '' } },
    })

    await site.load()

    expect(site.name).toBe('云集优选')
    expect(site.logo).toBe('/logo.png')
    expect(site.loaded).toBe(true)
    expect(JSON.parse(localStorage.getItem('site_info')!)).toEqual({
      name: '云集优选',
      logo: '/logo.png',
      logo_small: '',
    })
  })

  it('回源失败时保留缓存值，不把品牌打回默认', async () => {
    cacheSite({ name: '星尘商城', logo: '/big.png', logo_small: '' })
    freshPinia()
    const site = useSiteStore()
    getSiteConfigMock.mockRejectedValue(new Error('network'))

    await site.load()

    expect(site.name).toBe('星尘商城')
    expect(site.logo).toBe('/big.png')
  })

  it('站点名为空串时回落默认名', async () => {
    freshPinia()
    const site = useSiteStore()
    getSiteConfigMock.mockResolvedValue({ data: { data: { name: '', logo: '', logo_small: '' } } })

    await site.load()

    expect(site.name).toBe('CubeShop')
  })

  it('大小 logo 互为回落：移动端优先小 logo，醒目位优先大 logo', () => {
    freshPinia()
    const site = useSiteStore()

    site.logoSmall = '/small.png'
    expect(site.mobileLogo).toBe('/small.png')
    expect(site.brandLogo).toBe('/small.png')

    site.logo = '/big.png'
    expect(site.mobileLogo).toBe('/small.png')
    expect(site.brandLogo).toBe('/big.png')

    site.logoSmall = ''
    expect(site.mobileLogo).toBe('/big.png')
  })
})

describe('顶栏站点品牌渲染', () => {
  async function mountHeader(site?: Partial<{ name: string; logo: string; logoSmall: string }>) {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', name: 'home', component: { template: '<div />' } }],
    })
    const pinia = freshPinia()
    const store = useSiteStore()
    if (site?.name !== undefined) store.name = site.name
    if (site?.logo !== undefined) store.logo = site.logo
    if (site?.logoSmall !== undefined) store.logoSmall = site.logoSmall

    router.push('/')
    await router.isReady()

    return render(ShopHeader, { global: { plugins: [router, pinia] } })
  }

  it('默认展示内置图标与默认站点名', async () => {
    const { getByText, queryByTestId } = await mountHeader()

    expect(getByText('CubeShop')).toBeTruthy()
    expect(queryByTestId('site-logo')).toBeNull()
    expect(queryByTestId('site-logo-small')).toBeNull()
  })

  it('配置大/小 logo 后渲染为图片（桌面用大 logo、移动端用小 logo）', async () => {
    const { getByTestId } = await mountHeader({
      name: '星尘商城',
      logo: '/big.png',
      logoSmall: '/small.png',
    })

    expect(getByTestId('site-logo').getAttribute('src')).toBe('/big.png')
    expect(getByTestId('site-logo-small').getAttribute('src')).toBe('/small.png')
  })

  it('仅配置大 logo 时移动端回落大 logo', async () => {
    const { getByTestId } = await mountHeader({ logo: '/big.png' })

    expect(getByTestId('site-logo-small').getAttribute('src')).toBe('/big.png')
  })

  it('展示后台配置的站点名', async () => {
    const { getByText } = await mountHeader({ name: '云集优选' })

    expect(getByText('云集优选')).toBeTruthy()
  })
})

describe('浏览器标题按站点名替换', () => {
  it('路由标题里的占位符替换为实际站点名', async () => {
    freshPinia()
    const site = useSiteStore()
    getSiteConfigMock.mockResolvedValue({ data: { data: { name: '星尘商城', logo: '', logo_small: '' } } })
    await site.load()

    setTitleBase('商品详情 · CubeShop')

    expect(document.title).toBe('商品详情 · 星尘商城')
  })

  it('站点名本身含占位符时不会重复追加（保留原始标题再渲染）', async () => {
    freshPinia()
    const site = useSiteStore()
    getSiteConfigMock.mockResolvedValue({
      data: { data: { name: 'CubeShop 旗舰店', logo: '', logo_small: '' } },
    })
    await site.load()

    setTitleBase('CubeShop')
    expect(document.title).toBe('CubeShop 旗舰店')
    setTitleBase('CubeShop')
    expect(document.title).toBe('CubeShop 旗舰店')
  })
})
