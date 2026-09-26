import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AdminLayout from '@/layouts/AdminLayout.vue'

/**
 * 侧栏菜单高亮（唯一命中）：
 * 父子路径同时是菜单项时（/products 与 /products/import），只有最长匹配的那一项目亮，
 * 不能出现「点商品导入、商品管理也跟着变蓝」的双高亮。
 */

const ALL_PERMISSIONS = ['product.view', 'product.import', 'refund.view', 'inventory.check']

function freshPinia(permissions: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'admin', nickname: null, avatar: null, phone: null, email: null,
    roles: ['operator'], permissions,
  }
  return pinia
}

async function mountLayoutAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/products', component: { template: '<div />' } },
      { path: '/products/import', component: { template: '<div />' } },
      { path: '/products/:id/edit', component: { template: '<div />' } },
      { path: '/inventory-checks', component: { template: '<div />' } },
      { path: '/inventory-checks/:id', component: { template: '<div />' } },
      { path: '/refunds', component: { template: '<div />' } },
      { path: '/refunds-overview', component: { template: '<div />' } },
      // 其余菜单项未在测试里定义路由，兜底一条通配避免 RouterLink 报 No match 警告
      { path: '/:pathMatch(.*)*', component: { template: '<div />' } },
    ],
  })
  router.push(path)
  await router.isReady()
  const wrapper = mount(AdminLayout, { global: { plugins: [freshPinia(ALL_PERMISSIONS), router] } })
  await flushPromises()
  return wrapper
}

/** 当前被高亮的菜单文案列表 */
function activeTitles(wrapper: Awaited<ReturnType<typeof mountLayoutAt>>): string[] {
  return wrapper
    .findAll('a[data-active="true"]')
    .map((a) => (a.text() || '').trim())
    .filter(Boolean)
}

describe('侧栏菜单高亮（父子路径唯一命中）', () => {
  it('MENU-01 在商品导入页：只高亮「商品导入」，商品管理不跟着亮', async () => {
    const wrapper = await mountLayoutAt('/products/import')

    expect(activeTitles(wrapper)).toEqual(['商品导入'])
  })

  it('MENU-02 在商品列表页：只高亮「商品管理」', async () => {
    const wrapper = await mountLayoutAt('/products')

    expect(activeTitles(wrapper)).toEqual(['商品管理'])
  })

  it('MENU-03 商品详情页（非菜单路径）仍回退高亮父级「商品管理」', async () => {
    const wrapper = await mountLayoutAt('/products/12/edit')

    expect(activeTitles(wrapper)).toEqual(['商品管理'])
  })

  it('MENU-04 盘点详情页仍回退高亮父级「库存盘点」', async () => {
    const wrapper = await mountLayoutAt('/inventory-checks/7')

    expect(activeTitles(wrapper)).toEqual(['库存盘点'])
  })

  it('MENU-05 /refunds-overview 不被误判进 /refunds（前缀兄弟路径隔离）', async () => {
    const wrapper = await mountLayoutAt('/refunds-overview')

    expect(activeTitles(wrapper)).toEqual(['退款概览'])
  })
})
