import axios, { AxiosError, type AxiosInstance, type AxiosResponse } from 'axios'
import { useAuthStore } from '@/stores/auth'

/** 后端统一响应结构（API 文档 1.2） */
export interface ApiResult<T = unknown> {
  code: number
  message: string
  data: T
}

const request: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  timeout: 15000,
})

/** 请求拦截：自动附加 Token */
request.interceptors.request.use((config) => {
  const auth = useAuthStore()
  if (auth.token) {
    config.headers.Authorization = `Bearer ${auth.token}`
  }
  return config
})

/** 401 统一处理：清 Token 并跳转登录页（携带回跳地址） */
function handleUnauthorized(message: string) {
  const auth = useAuthStore()
  auth.clearToken()
  showBusinessError(40001, message)
  const current = encodeURIComponent(window.location.pathname + window.location.search)
  if (!window.location.pathname.startsWith('/login')) {
    window.location.href = `/login?redirect=${current}`
  }
}

/** 响应拦截：统一错误码处理 */
request.interceptors.response.use(
  (response: AxiosResponse<ApiResult>) => {
    const body = response.data

    // 二进制等非标准结构直接透传
    if (body === null || typeof body !== 'object' || !('code' in body)) {
      return response
    }

    if (body.code === 0) {
      return response
    }

    // 业务错误码非 0（HTTP 200）：提示并拒绝
    if (body.code === 40001) {
      handleUnauthorized(body.message || '登录已过期，请重新登录')
    } else {
      showBusinessError(body.code, body.message)
    }
    return Promise.reject(new ApiBusinessError(body.code, body.message))
  },
  (error: AxiosError<ApiResult>) => {
    const body = error.response?.data

    // 未认证：HTTP 401 或业务码 40001
    if (error.response?.status === 401 || body?.code === 40001) {
      handleUnauthorized(body?.message || '登录已过期，请重新登录')
      return Promise.reject(new ApiBusinessError(40001, body?.message || '未登录'))
    }

    // 参数校验失败
    if (body?.code === 40000 || error.response?.status === 422) {
      const msg = body?.message || '参数校验失败，请检查表单'
      showBusinessError(40000, msg)
      return Promise.reject(new ApiBusinessError(40000, msg))
    }

    // 无权限
    if (body?.code === 40003) {
      showBusinessError(40003, body?.message || '无权限执行此操作')
      return Promise.reject(new ApiBusinessError(40003, body?.message || '无权限'))
    }

    showBusinessError(body?.code ?? error.response?.status ?? -1, body?.message || error.message || '网络异常，请稍后重试')
    return Promise.reject(new ApiBusinessError(body?.code ?? -1, body?.message || error.message))
  },
)

export class ApiBusinessError extends Error {
  constructor(
    public code: number,
    message: string,
  ) {
    super(message)
    this.name = 'ApiBusinessError'
  }
}

let toastHandler: ((code: number, message: string) => void) | null = null

/** 注册全局错误提示（避免循环依赖，由 App 挂载时注入 Toast 实现） */
export function setGlobalToastHandler(handler: (code: number, message: string) => void) {
  toastHandler = handler
}

function showBusinessError(code: number, message: string) {
  if (toastHandler) {
    toastHandler(code, message)
    return
  }
  console.warn(`[CubeShop] 请求失败 code=${code}: ${message}`)
}

export default request
