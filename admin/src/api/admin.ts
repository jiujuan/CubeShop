import request from './request'
import type { ApiResult } from './request'

export interface Profile {
  id: number
  username: string
  nickname: string | null
  avatar: string | null
  phone: string | null
  email: string | null
  last_login_at?: string
  last_login_ip?: string
  roles: string[]
  permissions: string[]
}

/** 个人资料（API 文档 3.1） */
export function getProfile() {
  return request.get<ApiResult<Profile>>('/user/profile')
}

/** 更新个人资料（API 文档 3.2） */
export function updateProfile(data: { nickname?: string; avatar?: string; email?: string; phone?: string }) {
  return request.put<ApiResult<Profile>>('/user/profile', data)
}

export interface SystemConfig {
  config_key: string
  config_value: string
  description: string | null
  /** 分组标签（由后端 config_key 前缀推导，见 App\Support\ConfigGroup），用于 Tab 归类 */
  group: string
  updated_at: string
}

/** 系统配置列表（API 文档 8.6，权限 config.manage） */
export function getConfigs() {
  return request.get<ApiResult<SystemConfig[]>>('/admin/configs')
}

/** 批量更新系统配置（config_value 允许空串以清空配置） */
export function updateConfigs(configs: { config_key: string; config_value: string }[]) {
  return request.put<ApiResult<null>>('/admin/configs', { configs })
}

/** 上传站点图片（logo 等，权限 config.manage，落在 uploads/site 目录） */
export function uploadSiteLogo(file: File) {
  const form = new FormData()
  form.append('file', file)
  return request.post<ApiResult<{ url: string }>>('/admin/configs/upload', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

export interface OperationLog {
  id: number
  user: { id: number; username: string; nickname: string } | null
  module: string
  action: string
  target_type: string | null
  target_id: number | null
  content: string | null
  ip: string
  created_at: string
}

/** 操作日志（API 文档 8.7 / V1.1 T-023，权限 log.view） */
export function getOperationLogs(params: {
  page?: number
  page_size?: number
  module?: string
  action?: string
  operator_id?: number
  start?: string
  end?: string
}) {
  return request.get<ApiResult<{ list: OperationLog[]; pagination: { page: number; page_size: number; total: number; total_pages: number } }>>(
    '/admin/operation-logs',
    { params },
  )
}
