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

/**
 * 图形验证码位数（前端单一真源）
 *
 * ⚠️ 必须与后端 `App\Services\Common\CaptchaService::LENGTH` 保持一致 ——
 * 改位数要同时改两处，否则用户永远输不满、登录必然失败。
 */
export const CAPTCHA_LENGTH = 5

/**
 * 短信验证码位数（前端单一真源）
 *
 * ⚠️ 必须与后端 `App\Services\Sms\SmsCodeService::LENGTH` 保持一致。
 * 与图形验证码位数不同，因此输入框 maxlength 与提交前校验都要跟着
 * {@link VerifyMode} 的 `code_length` 走，不能写死。
 */
export const SMS_CODE_LENGTH = 6

/** 验证码场景（后端 SmsSettings::CODE_SCENES 的子集，前台只用这三个） */
export type SmsScene = 'register' | 'login' | 'reset_password'

/** 某场景当前该用哪种验证码 */
export interface VerifyMode {
  scene: string
  mode: 'sms' | 'captcha'
  code_length: number
  reason: string | null
}

export interface SendSmsCodeResult {
  sent: boolean
  /** 后端回退时返回 captcha：前端应立即切回图形验证码，别让用户卡住 */
  mode: 'sms' | 'captcha'
  code_length: number
  reason: string | null
  resend_after?: number
}

/** 图形验证码（scene=web：用户端专属风格，与管理端区分） */
export function getCaptcha() {
  return request.post<ApiResult<Captcha>>('/auth/captcha', { scene: 'web' })
}

/** 查询某场景当前用哪种验证码（短信不可用时返回 captcha，前端据此回退） */
export function getVerifyMode(scene: SmsScene) {
  return request.get<ApiResult<VerifyMode>>('/auth/verify-mode', { params: { scene } })
}

/**
 * 发送短信验证码
 *
 * 必须带图形验证码：短信按条计费，一个不带人机校验的发码口就是可被脚本刷的账单。
 * 后端在系统不就绪时会返回 `sent: false` 而不是报错，前端需切回图形验证码。
 */
export function sendSmsCode(data: { scene: SmsScene; phone: string; captcha_id: string; captcha_code: string }) {
  return request.post<ApiResult<SendSmsCodeResult>>('/auth/send-sms-code', data)
}

/** 登录（API 文档 2.2） */
export function login(data: { username: string; password: string; captcha_id: string; captcha_code: string }) {
  return request.post<ApiResult<{ token: string; user: UserInfo }>>('/auth/login', data)
}

/** 短信验证码登录（密码登录始终可用，两者由前端 tab 切换） */
export function loginBySmsCode(data: { phone: string; sms_code: string }) {
  return request.post<ApiResult<{ token: string; user: UserInfo }>>('/auth/login', data)
}

/** 注册（API 文档 2.1；短信模式下传 phone + sms_code，图形模式下传 captcha_id + code） */
export function register(data: {
  username?: string
  password?: string
  password_confirmation?: string
  phone?: string
  sms_code?: string
  captcha_id?: string
  code?: string
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
