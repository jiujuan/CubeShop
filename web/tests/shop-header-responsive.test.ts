import { describe, expect, it, vi } from 'vitest'
import { render } from '@testing-library/vue'
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

async function mountHeader() {
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
  auth.user = { id: 1, username: 'uibuyer', nickname: '测试买家' } as unknown as typeof auth.user

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

    const textBlock = container.querySelector('header a[href="/"] span.hidden')
    expect(textBlock).not.toBeNull()
    expect(textBlock!.className).toContain('sm:block')
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

  it('公告条文案 truncate（配合父级 min-w-0 单行收敛）', async () => {
    const { getByText } = await mountHeader()

    expect(getByText(/全场满 99 元包邮/).className).toContain('truncate')
  })

  it('公告条账号入口窄屏隐藏（≥sm 才显示），避免与公告文案互相挤压', async () => {
    const { getByText } = await mountHeader()

    const entry = getByText('个人中心').closest('div')
    expect(entry).toBeTruthy()
    expect(entry!.className).toContain('hidden')
    expect(entry!.className).toContain('sm:flex')
  })
})
