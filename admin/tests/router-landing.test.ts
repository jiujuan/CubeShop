import { describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { landingPath } from '@/router'

/**
 * CS-117 缺陷 #4 配套：登录落地页按权限回退
 *
 * 新增客服角色（cs_agent）只持有 cs.*，**没有 dashboard.view**（工作台含今日销售额等
 * 经营数据）。若登录后一律跳 /dashboard，客服会直接看到 403 空页；因此落地页按
 * 「首个有权限的入口」依次回退，这里锁定各角色的落点。
 */
function authWith(permissions: string[], roles: string[] = ['operator']) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'u1', nickname: null, avatar: null, phone: null, email: null,
    roles, permissions,
  }

  return auth
}

describe('登录落地页 landingPath（CS-117 缺陷 #4）', () => {
  it('有 dashboard.view 的角色（运营）落在工作台', () => {
    authWith(['dashboard.view', 'order.view', 'product.view'])
    expect(landingPath()).toBe('/dashboard')
  })

  it('超级管理员走角色直通，同样落在工作台', () => {
    authWith([], ['super_admin'])
    expect(landingPath()).toBe('/dashboard')
  })

  it('客服（只有 cs.*）落在客服工作台而不是工作台', () => {
    authWith(['cs.ticket.view', 'cs.ticket.handle', 'cs.faq.manage'], ['cs_agent'])
    expect(landingPath()).toBe('/cs/tickets')
  })

  it('只维护帮助中心的角色落在帮助中心', () => {
    authWith(['cs.faq.manage'], ['cs_agent'])
    expect(landingPath()).toBe('/cs/faq')
  })

  it('完全没有权限时兜底回工作台（由路由守卫/接口层拒绝，不至于停留在空路径）', () => {
    authWith([])
    expect(landingPath()).toBe('/dashboard')
  })
})
