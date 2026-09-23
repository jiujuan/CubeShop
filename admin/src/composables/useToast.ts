import { ref } from 'vue'

export type ToastType = 'error' | 'success' | 'info'

export interface ToastItem {
  id: number
  message: string
  type: ToastType
}

/** 全局共享的 toast 队列（单例，跨组件复用） */
const toasts = ref<ToastItem[]>([])
let seq = 0

/**
 * 极简 toast：用于后台操作的非阻断提示（如面单加载失败）。
 * 无第三方依赖，由 <ToastHost /> 统一渲染。
 */
export function useToast() {
  function show(message: string, type: ToastType = 'error', duration = 3000) {
    const id = ++seq
    toasts.value.push({ id, message, type })
    window.setTimeout(() => {
      toasts.value = toasts.value.filter((t) => t.id !== id)
    }, duration)
  }

  return { toasts, show }
}
