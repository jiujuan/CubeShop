import axios, { AxiosError } from 'axios'
import type { ApiResult } from './types'

/** 统一业务错误（组件可 catch 后提示 message） */
export class ApiBusinessError extends Error {
  constructor(
    public code: number,
    message: string,
    /** 422 校验失败的字段级明细；页面据此把错误落到具体输入框，而不是笼统提示 */
    public errors?: Record<string, string[]>,
    public status?: number,
  ) {
    super(message)
  }
}

const request = axios.create({
  baseURL: '/api',
  timeout: 15000,
})

/**
 * 取校验失败明细：后端统一包在 `data.errors`，个别接口也可能放在顶层 `errors`。
 */
function pickErrors(body?: ApiResult): Record<string, string[]> | undefined {
  if (! body) return undefined
  const inner = body.data as { errors?: Record<string, string[]> } | undefined | null

  return body.errors ?? inner?.errors
}

// 响应拦截：拆包 { code, message, data }
request.interceptors.request.use((config) => {
  // 预留：登录后的 Token
  const token = localStorage.getItem('web_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

request.interceptors.response.use(
  (response) => {
    const body = response.data as ApiResult
    if (body.code !== 0) {
      return Promise.reject(new ApiBusinessError(body.code, body.message || '请求失败', pickErrors(body)))
    }
    return response
  },
  (error: AxiosError<ApiResult>) => {
    const body = error.response?.data
    const status = error.response?.status
    // 429 是限流（Laravel 默认只回 Too Many Attempts.），单独给中文文案
    const message = status === 429
      ? '操作过于频繁，请稍后再试'
      : (body?.message ?? '网络异常，请稍后重试')

    return Promise.reject(new ApiBusinessError(body?.code ?? status ?? 50000, message, pickErrors(body), status))
  },
)

export default request
