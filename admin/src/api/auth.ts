import request from './request'
import type { ApiResult } from './request'

export interface UserInfo {
  id: number
  username: string
  nickname: string | null
  avatar: string | null
  phone: string | null
  email: string | null
  roles: string[]
  permissions?: string[]
}

export interface Captcha {
  captcha_id: string
  image: string
  expires_in: number
  debug_code?: string
}

/** 图形验证码（登录页） */
export function getCaptcha() {
  return request.post<ApiResult<Captcha>>('/auth/captcha')
}

export interface LoginPayload {
  username: string
  password: string
  captcha_id: string
  captcha_code: string
}

/** 登录（API 文档 2.2） */
export function login(data: LoginPayload) {
  return request.post<ApiResult<{ token: string; user: UserInfo }>>('/auth/login', data)
}

export interface RegisterPayload {
  username: string
  password: string
  password_confirmation: string
  phone?: string
  email?: string
  captcha_id: string
  code: string
}

/** 注册（API 文档 2.1，验证码 V1.0 用图形验证码降级） */
export function register(data: RegisterPayload) {
  return request.post<ApiResult<{ token: string; user: UserInfo }>>('/auth/register', data)
}

/** 退出登录（API 文档 2.3） */
export function logout() {
  return request.post<ApiResult<null>>('/auth/logout')
}

/** 当前用户信息（含权限码，供动态菜单） */
export function getMe() {
  return request.get<ApiResult<UserInfo>>('/auth/me')
}

/** 修改密码 */
export function changePassword(data: { old_password: string; password: string; password_confirmation: string }) {
  return request.post<ApiResult<null>>('/auth/password', data)
}
