import type { Directive } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 按钮级权限指令（架构文档 9.3）
 *
 * 用法：
 *   v-permission="'order.ship'"
 *   v-permission="['order.ship', 'order.export']"  // 任一满足即可
 * 无权限时移除元素。
 */
export const permission: Directive<HTMLElement, string | string[] | undefined> = {
  mounted(el, binding) {
    const auth = useAuthStore()
    if (!auth.hasPermission(binding.value)) {
      el.parentNode?.removeChild(el)
    }
  },
}

export default permission
