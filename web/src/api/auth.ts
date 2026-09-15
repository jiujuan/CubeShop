import request from './request'
import type { ApiResult } from './types'

export interface UserInfo {
  id: number
  username: string
  nickname: string | null
  roles: string[]
}

export interface Captcha {
  captcha_id: string
  image: string
  expires_in: number
  debug_code?: string
}

/** 图形验证码 */
export function getCaptcha() {
  return request.post<ApiResult<Captcha>>('/auth/captcha')
}

/** 登录（API 文档 2.2） */
export function login(data: { username: string; password: string; captcha_id: string; captcha_code: string }) {
  return request.post<ApiResult<{ token: string; user: UserInfo }>>('/auth/login', data)
}

/** 注册（API 文档 2.1） */
export function register(data: {
  username: string
  password: string
  password_confirmation: string
  captcha_id: string
  code: string
}) {
  return request.post<ApiResult<{ token: string; user: UserInfo }>>('/auth/register', data)
}

/** 当前用户信息 */
export function getMe() {
  return request.get<ApiResult<UserInfo>>('/auth/me')
}

/** 退出登录 */
export function logout() {
  return request.post<ApiResult<null>>('/auth/logout')
}
