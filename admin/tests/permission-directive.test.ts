import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { defineComponent } from 'vue'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const Button = defineComponent({
  props: { code: { type: String, required: true } },
  template: `<div><button v-permission="code">发货</button></div>`,
})

let pinia: Pinia

function setupWithPermissions(roles: string[], permissions: string[]) {
  pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: 1, username: 'u', nickname: null, avatar: null, phone: null, email: null,
    roles, permissions,
  }
  return mount(Button, {
    props: { code: 'order.ship' },
    attachTo: document.body,
    global: {
      plugins: [pinia],
      directives: { permission },
    },
  })
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('v-permission 指令', () => {
  it('有权限时元素保留', () => {
    setupWithPermissions(['operator'], ['order.ship'])
    expect(document.body.querySelector('button')?.textContent).toBe('发货')
  })

  it('无权限时元素被移除', () => {
    setupWithPermissions(['operator'], ['order.view'])
    expect(document.body.querySelector('button')).toBeNull()
  })

  it('超管无需显式权限码', () => {
    setupWithPermissions(['super_admin'], [])
    expect(document.body.querySelector('button')).not.toBeNull()
  })
})
