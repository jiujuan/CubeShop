import request from './request'
import type { ApiResult } from './request'

/**
 * 账号与角色管理（V1.1 F04 / T-022 接口的 admin 消费层）
 */

export interface Pagination {
  page: number
  page_size: number
  total: number
  total_pages: number
}

export interface AccountRow {
  id: number
  username: string
  nickname: string | null
  email: string | null
  phone: string | null
  status: number
  roles: string[]
  last_login_at: string | null
  created_at: string | null
}

export interface AccountPayload {
  username?: string
  password?: string
  nickname?: string
  email?: string
  phone?: string
  roles: string[]
}

export interface RoleRow {
  id: number
  name: string
  label: string
  builtin: boolean
  permissions: string[]
  user_count: number
}

export interface PermissionGroup {
  module: string
  label: string
  permissions: string[]
}

/** 后台角色中文标签（与后端 RoleController::ROLE_LABELS 对齐） */
export const ROLE_LABELS: Record<string, string> = {
  super_admin: '超级管理员',
  operator: '运营',
  customer: '买家',
}

/** 账号列表 */
export function getAccounts(params: {
  keyword?: string
  role?: string
  status?: number
  page?: number
  page_size?: number
}) {
  return request.get<ApiResult<{ list: AccountRow[]; pagination: Pagination }>>('/admin/accounts', { params })
}

/** 新增账号 */
export function createAccount(payload: AccountPayload) {
  return request.post<ApiResult<{ id: number }>>('/admin/accounts', payload)
}

/** 编辑账号基础信息与角色 */
export function updateAccount(id: number, payload: Partial<AccountPayload>) {
  return request.put<ApiResult<AccountRow>>(`/admin/accounts/${id}`, payload)
}

/** 启用/禁用 */
export function setAccountStatus(id: number, status: number) {
  return request.post<ApiResult<AccountRow>>(`/admin/accounts/${id}/status`, { status })
}

/** 重置密码 */
export function resetAccountPassword(id: number, password: string) {
  return request.post<ApiResult<null>>(`/admin/accounts/${id}/reset-password`, { password })
}

/** 角色列表 + 权限分组（一次返回，供权限树） */
export function getRoles() {
  return request.get<ApiResult<{ roles: RoleRow[]; permission_groups: PermissionGroup[] }>>('/admin/roles')
}

/** 权限码分组 */
export function getPermissions() {
  return request.get<ApiResult<{ groups: PermissionGroup[] }>>('/admin/permissions')
}

/** 新增角色 */
export function createRole(payload: { name: string; permissions: string[] }) {
  return request.post<ApiResult<{ id: number }>>('/admin/roles', payload)
}

/** 编辑角色 */
export function updateRole(id: number, payload: { name?: string; permissions?: string[] }) {
  return request.put<ApiResult<null>>(`/admin/roles/${id}`, payload)
}

/** 删除角色 */
export function deleteRole(id: number) {
  return request.delete<ApiResult<null>>(`/admin/roles/${id}`)
}
