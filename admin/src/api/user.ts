import request from './request'
import type { ApiResult } from './request'

// ---------- 用户管理（API 文档 8.8，权限 user.manage） ----------

export interface AdminUser {
  id: number
  username: string
  nickname: string | null
  avatar: string | null
  phone: string | null
  email: string | null
  status: 0 | 1
  roles: string[]
  /** 有效订单数（已支付/待发货/已发货/已完成） */
  order_count: number
  /** 累计实付金额 */
  total_paid: string
  last_login_at: string | null
  last_login_ip: string | null
  created_at: string
}

export interface UserRecentOrder {
  id: number
  order_no: string
  status: string
  status_label: string
  pay_amount: string
  created_at: string
}

export interface AdminUserDetail extends AdminUser {
  recent_orders: UserRecentOrder[]
}

export interface UserListResult {
  list: AdminUser[]
  pagination: { page: number; page_size: number; total: number; total_pages: number }
}

export interface UserQuery {
  keyword?: string
  status?: 0 | 1
  start_time?: string
  end_time?: string
  page?: number
  page_size?: number
}

/** 用户列表（多条件筛选） */
export function getUsers(params: UserQuery) {
  return request.get<ApiResult<UserListResult>>('/admin/users', { params })
}

/** 用户详情（含订单统计与最近订单） */
export function getUser(id: number) {
  return request.get<ApiResult<AdminUserDetail>>(`/admin/users/${id}`)
}

/** 编辑用户资料（昵称 / 手机号 / 邮箱） */
export function updateUser(id: number, data: { nickname?: string; phone?: string; email?: string }) {
  return request.put<ApiResult<AdminUserDetail>>(`/admin/users/${id}`, data)
}

/** 启用 / 禁用（禁用立即强制下线） */
export function updateUserStatus(id: number, status: 0 | 1) {
  return request.put<ApiResult<AdminUser>>(`/admin/users/${id}/status`, { status })
}

// ---------- 收货地址管理（设计文档 CubeShop_Address_Design_v1.0 §5，权限 address.view / address.manage） ----------

export interface AdminUserAddress {
  id: number
  contact_name: string
  /** 列表展示为脱敏手机号（138****0000） */
  contact_phone: string
  /** 完整手机号，仅供编辑回显 */
  contact_phone_full: string
  province: string | null
  city: string | null
  district: string | null
  detail_address: string
  is_default: boolean
  updated_at?: string
}

/** 某用户的收货地址列表（无全局地址列表接口） */
export function getUserAddresses(userId: number) {
  return request.get<ApiResult<AdminUserAddress[]>>(`/admin/users/${userId}/addresses`)
}

export interface AdminAddressUpdate {
  contact_name?: string
  contact_phone?: string
  province?: string
  city?: string
  district?: string
  detail_address?: string
}

/** 代用户修改地址（不含默认标记与归属用户，后台留操作日志） */
export function updateAdminAddress(id: number, data: AdminAddressUpdate) {
  return request.put<ApiResult<AdminUserAddress>>(`/admin/addresses/${id}`, data)
}
