import axios, { AxiosError } from 'axios'
import type { ApiResult } from './types'

/** 统一业务错误（组件可 catch 后提示 message） */
export class ApiBusinessError extends Error {
  constructor(
    public code: number,
    message: string,
  ) {
    super(message)
  }
}

const request = axios.create({
  baseURL: '/api',
  timeout: 15000,
})

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
      return Promise.reject(new ApiBusinessError(body.code, body.message || '请求失败'))
    }
    return response
  },
  (error: AxiosError<ApiResult>) => {
    const body = error.response?.data
    return Promise.reject(new ApiBusinessError(body?.code ?? error.response?.status ?? 50000, body?.message ?? '网络异常，请稍后重试'))
  },
)

export default request
