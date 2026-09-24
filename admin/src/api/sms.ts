import request from './request'
import type { ApiResult } from './request'

/**
 * 短信渠道后台配置（短信渠道计划 第一期，权限 sms.view / sms.manage）
 *
 * 后端：app/Http/Controllers/Admin/SmsConfigController.php
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D7
 *
 * ⚠️ 与后端联动的两条语义（页面上也有提示，不是可省的装饰）：
 * 1. **Secret 只出不进**：接口只回 `secret_masked`（`****abcd`）与 `has_secret`，
 *    明文/密文都不出网，前端也就无从泄露；
 * 2. **Secret 留空 = 不修改** —— 全局中间件 `ConvertEmptyStringsToNull` 会把 `''` 变成 null，
 *    所以「清空凭证」在 HTTP 层无法表达（这是有意的：避免一个空表单提交抹掉线上凭证）。
 */

export interface SmsChannelRow {
  id: number
  provider: string
  provider_label: string
  /** 该服务商是否已落地（腾讯云为 false，页面灰置「二期」） */
  available: boolean
  name: string
  access_key_id: string | null
  /** 是否已配置 Secret（用于显示「未配置」还是「****abcd」） */
  has_secret: boolean
  /** 掩码后的 Secret；未配置为 null */
  secret_masked: string | null
  sign_name: string | null
  region: string | null
  is_enabled: boolean
  /** 凭证是否齐备（不足时非生产环境会回退 Mock，页面据此提示） */
  credentials_complete: boolean
  /** 缺失项清单，如 ['access_key_secret', 'sign_name'] */
  missing_credentials: string[]
  remark: string | null
}

export interface SmsActiveInfo {
  /** 实际生效的渠道（可能因凭证缺失而回退 mock） */
  provider: string | null
  /** 后台配置里选的渠道 */
  configured_provider: string | null
  /** 配置值与实际生效不一致（非生产环境凭证缺失时回退 Mock） */
  degraded: boolean
  /** 渠道不可用时的原因（如生产环境凭证缺失） */
  error: string | null
}

export interface SmsSwitches {
  /** 短信总开关：关闭时所有发送只落 skipped 日志，不发真短信 */
  enabled: boolean
  /** 已启用短信验证码的场景；未列出的场景维持图形验证码 */
  code_scenes: string[]
  /** 场景 → 模板 CODE（阿里云账号级别的 SMS_xxxxxx） */
  code_templates: Record<string, string>
}

export interface SmsConfigData {
  channels: SmsChannelRow[]
  active: SmsActiveInfo
  switches: SmsSwitches
  options: {
    /** 场景 key => 中文名（页面多选与后端校验共用同一份白名单） */
    scenes: Record<string, string>
    providers: Array<{ key: string; label: string; available: boolean }>
  }
}

export interface SmsSwitchesPayload {
  enabled?: boolean
  code_scenes?: string[]
  code_templates?: Record<string, string>
}

export interface SmsChannelPayload {
  name?: string
  access_key_id?: string
  /** 留空/不传 = 不修改；无法从后台清空 */
  access_key_secret?: string
  sign_name?: string
  region?: string
  is_enabled?: boolean
}

export interface SmsTestPayload {
  phone: string
  template_code: string
  params?: Record<string, string>
}

export interface SmsTestResult {
  ok: boolean
  error_code: string | null
  error_msg: string | null
  biz_id: string | null
  latency_ms: number | null
}

export interface SmsLogRow {
  id: number
  sms_config_id: number | null
  provider: string
  /** 脱敏手机号（库里只有脱敏值） */
  phone_masked: string
  scene: string
  template_code: string | null
  status: 'sent' | 'failed' | 'skipped'
  error_code: string | null
  error_msg: string | null
  biz_id: string | null
  latency_ms: number | null
  created_at: string | null
}

export interface SmsLogList {
  list: SmsLogRow[]
  pagination: {
    page: number
    page_size: number
    total: number | null
    total_pages: number | null
    has_more: boolean
  }
}

export interface SmsLogQuery {
  provider?: string
  status?: string
  scene?: string
  phone?: string
  page?: number
  page_size?: number
}

/** 渠道列表 + 生效渠道 + 运营开关（GET /admin/sms/config） */
export function getSmsConfig() {
  return request.get<ApiResult<SmsConfigData>>('/admin/sms/config')
}

/** 更新运营开关（PUT /admin/sms/config） */
export function updateSmsSwitches(payload: SmsSwitchesPayload) {
  return request.put<ApiResult<{ switches: SmsSwitches }>>('/admin/sms/config', payload)
}

/** 更新某渠道凭证（PUT /admin/sms/config/{id}） */
export function updateSmsChannel(id: number, payload: SmsChannelPayload) {
  return request.put<ApiResult<SmsChannelRow>>(`/admin/sms/config/${id}`, payload)
}

/**
 * 测试发送（POST /admin/sms/test）
 *
 * 走真实链路并按条计费，后端额外挂了 `throttle:sms-send`（同 IP 每分钟次数）
 */
export function testSms(payload: SmsTestPayload) {
  return request.post<ApiResult<SmsTestResult>>('/admin/sms/test', payload)
}

/** 发送记录分页（GET /admin/sms/logs） */
export function getSmsLogs(query: SmsLogQuery = {}) {
  const params: Record<string, string | number> = {}
  if (query.provider) params.provider = query.provider
  if (query.status) params.status = query.status
  if (query.scene) params.scene = query.scene
  if (query.phone) params.phone = query.phone
  if (query.page) params.page = query.page
  if (query.page_size) params.page_size = query.page_size
  return request.get<ApiResult<SmsLogList>>('/admin/sms/logs', { params })
}
