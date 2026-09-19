import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * ShopHeader 移动端适配回归（P1 修复）
 *
 * 背景：375px 视口下顶栏曾横向溢出 296px（主头部一行 logo + 搜索框 + 右侧入口总宽超视口，
 * 且搜索框 flex-1 无法收缩），右侧购物车/用户入口被挤出可视区。
 * 修复手法：< lg 收缩间距、隐藏次要文字、搜索框 min-w-0、分类导航横向滚动。
 *
 * jsdom 不做真实布局计算，因此这里锁定的是「模板契约」——即保证不再被改回
 * 无响应式保护的固定横排。真实视口的像素级验证见
 * docs/testing/evidence/cs/CS-117/overflow-*.txt。
 */

vi.mock('@/api/shop', () => ({
  getCategories: vi.fn().mockResolvedValue({
    data: { data: [{ id: 1, name: '运动户外', children: [{ id: 11, name: '跑步鞋' }] }] },
  }),
}))

vi.mock('@/api/user', () => ({
  getCartCount: vi.fn().mockResolvedValue({ data: { data: { count: 2 } } }),
}))

vi.mock('@/api/notification', () => ({
  getUnreadCount: vi.fn().mockResolvedValue({ data: { data: { count: 0 } } }),
  getNotifications: vi.fn().mockResolvedValue({ data: { data: { list: [] } } }),
  markNotificationsRead: vi.fn(),
}))

vi.mock('@/api/auth', () => ({
  getMe: vi.fn(),
  logout: vi.fn(),
}))

vi.mock('@/api/announcement', () => ({
  getAnnouncements: vi.fn().mockResolvedValue({
    data: { data: { list: [{ id: 'abc123', title: '系统维护通知', is_top: true, published_at: '2026-09-18', summary: '升级维护' }] } },
  }),
}))

/** 无公告场景：把公告接口返回空列表 */
async function withEmptyAnnouncements() {
  const { getAnnouncements } = await import('@/api/announcement')
  vi.mocked(getAnnouncements).mockResolvedValue({ data: { data: { list: [] } } } as never)
}

/**
 * @param options.withUser=false 模拟「刷新后 token 在、用户信息尚未回填」的瞬间
 */
async function mountHeader(options: { withUser?: boolean } = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/category/:id', name: 'category', component: { template: '<div />' } },
    ],
  })
  const pinia = createPinia()
  setActivePinia(pinia)

  const auth = useAuthStore()
  auth.setToken('test-token')
  if (options.withUser !== false) {
    auth.user = { id: 1, username: 'uibuyer', nickname: '测试买家' } as unknown as typeof auth.user
  }

  router.push('/')
  await router.isReady()

  return render(ShopHeader, { global: { plugins: [router, pinia] } })
}

describe('ShopHeader 移动端适配（P1 回归）', () => {
  it('搜索框可压缩：容器带 min-w-0（否则会把右侧入口挤出视口）', async () => {
    const { getByTestId } = await mountHeader()

    expect(getByTestId('search-box').className).toContain('min-w-0')
  })

  it('右侧入口组不收缩：容器带 shrink-0', async () => {
    const { getByTestId } = await mountHeader()

    expect(getByTestId('header-actions').className).toContain('shrink-0')
  })

  it('分类导航：窄屏横向滚动，≥lg 恢复 overflow 可见（下拉面板不被裁剪）', async () => {
    const nav = (await mountHeader()).getByTestId('category-nav')

    expect(nav.className).toContain('overflow-x-auto')
    expect(nav.className).toContain('lg:overflow-x-visible')
  })

  it('logo 文字块与副标语在窄屏隐藏', async () => {
    const { container } = await mountHeader()

    // logo 区改为「移动端小 logo / 桌面端大 logo」两套容器后，
    // 「首个 span.hidden」不再是文字块，故按 testid 精确定位
    const textBlock = container.querySelector('[data-testid="site-name-block"]')
    expect(textBlock).not.toBeNull()
    expect(textBlock!.className).toContain('hidden')
    expect(textBlock!.className).toContain('sm:block')

    const slogan = textBlock!.querySelector('span.hidden')
    expect(slogan).not.toBeNull()
    expect(slogan!.className).toContain('lg:block')
  })

  it('分类下拉只在 ≥lg 渲染（移动端滚动容器会裁剪绝对定位面板）', async () => {
    const { container } = await mountHeader()

    const panel = container.querySelector('[data-testid="category-nav"] .invisible')
    expect(panel).not.toBeNull()
    expect(panel!.className).toContain('lg:block')
  })

  it('「我的订单」入口窄屏隐藏、≥lg 显示', async () => {
    const { getAllByText } = await mountHeader()

    const btn = getAllByText('我的订单')
      .map((el) => el.closest('button'))
      .find((el) => el !== null)

    expect(btn).toBeTruthy()
    expect(btn!.className).toContain('hidden')
    expect(btn!.className).toContain('lg:flex')
  })

  it('公告条展示后台公告且文案 truncate（动态内容，替换原写死的促销语）', async () => {
    const { findByText } = await mountHeader()

    const span = await findByText('系统维护通知')
    const link = span.closest('a')!
    expect(link.className).toContain('truncate')
    expect(link.getAttribute('href')).toBe('/announcements/abc123')
  })

  it('公告条账号入口窄屏隐藏（≥sm 才显示），避免与公告文案互相挤压', async () => {
    const { getByText } = await mountHeader()

    const entry = getByText('个人中心').closest('div')
    expect(entry).toBeTruthy()
    expect(entry!.className).toContain('hidden')
    expect(entry!.className).toContain('sm:flex')
  })

  it('账号区始终贴右：公告条用 justify-end，公告链接用 mr-auto 撑开左侧', async () => {
    const { getByTestId, findByText } = await mountHeader()

    const bar = getByTestId('announcement-bar')
    expect(bar.className).toContain('justify-end')
    expect(bar.className).not.toContain('justify-between')

    // 公告为异步拉取，等它渲染出来再断言
    const link = (await findByText('系统维护通知')).closest('a')
    expect(link).not.toBeNull()
    expect(link!.className).toContain('mr-auto')
  })

  it('无公告（接口返回空）时账号区仍在右侧，不会跑到左边', async () => {
    await withEmptyAnnouncements()
    const { getByTestId, findByText } = await mountHeader()

    expect(getByTestId('announcement-bar').className).toContain('justify-end')

    const entry = (await findByText('个人中心')).closest('div')
    expect(entry).toBeTruthy()
    expect(entry!.getAttribute('data-testid')).toBe('header-account-entry')
    // 公告缺失时不再渲染公告链接，账号区是条内唯一可见内容且被推到右侧
    expect(document.querySelector('[data-testid="announcement-bar"] a[href^="/announcements/"]')).toBeNull()
  })
})

/**
 * 顶栏用户菜单交互 + 账号名回填（2026-09-19 修复）
 *
 * 反馈现象：1) 点开「我的」浮层后鼠标移出不会收起；2) 「Hi，xxx」只显示「Hi，」。
 * 后者根因不在模板：token 持久化在 localStorage 而 user 不持久化，
 * `fetchUser()` 原先只在登录/注册成功后调用，刷新页面后 store.user 恒为 null。
 * 回填逻辑在 `main.ts` 启动引导，此处锁定模板侧的两个契约。
 */
describe('顶栏用户菜单与账号名（ShopHeader）', () => {
  it('鼠标移出触发区后浮层自动收起', async () => {
    const { getByTestId, queryByTestId } = await mountHeader()

    await fireEvent.click(getByTestId('user-menu-trigger'))
    expect(queryByTestId('user-menu')).not.toBeNull()

    // 容器同时含「触发按钮 + 浮层」，移出容器（DOM 包含关系）即收起
    const triggerBox = getByTestId('user-menu-trigger').parentElement!
    await fireEvent.mouseLeave(triggerBox)

    expect(queryByTestId('user-menu')).toBeNull()
  })

  it('浮层顶部保留透明过渡带（pt-2）：否则鼠标下移途中先触发 mouseleave，面板点不到', async () => {
    const { getByTestId } = await mountHeader()

    await fireEvent.click(getByTestId('user-menu-trigger'))

    const menu = getByTestId('user-menu')
    expect(menu.className).toContain('pt-2')
    expect(menu.className).toContain('top-full')
  })

  it('用户信息回填后展示「Hi，昵称」', async () => {
    const { findByText } = await mountHeader()

    expect(await findByText('Hi，测试买家')).toBeTruthy()
  })

  it('用户信息未回填时不渲染悬空的「Hi，」', async () => {
    const { queryByText, findByText } = await mountHeader({ withUser: false })

    // 账号入口仍在，只是没有名字可展示
    expect(await findByText('个人中心')).toBeTruthy()
    expect(queryByText(/^Hi，/)).toBeNull()
  })
})
