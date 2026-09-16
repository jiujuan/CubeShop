import request from './request'
import type { ApiResult } from './request'
import type { Pagination } from './product'

/**
 * 营销管理 API（V1.1 二期 F06 / T-040，权限 marketing.manage）
 * 对齐后端 Admin\CouponController 与 Admin\PromotionController（T-032）。
 */

// ---------- 优惠券 ----------

export type CouponType = 'fixed' | 'percent'
export type CouponScope = 'all' | 'category' | 'product'
export type CouponValidType = 'absolute' | 'relative'
export type CouponStatus = 'active' | 'stopped'

export interface AdminCoupon {
  id: number
  name: string
  type: CouponType
  type_label: string
  amount: number | null
  percent: number | null
  max_discount: number | null
  min_spend: number
  scope: CouponScope
  scope_label: string
  scope_refs: number[]
  total_count: number
  issued_count: number
  used_count: number
  per_user_limit: number
  valid_type: CouponValidType
  valid_from: string | null
  valid_to: string | null
  valid_days: number | null
  status: CouponStatus
  /** 已发放（issued_count>0）：编辑时核心字段置灰 */
  issued: boolean
  created_at: string | null
}

export interface CouponListResult {
  list: AdminCoupon[]
  pagination: Pagination
}

/** 券创建/编辑载荷（编辑已发放券仅允许 name/status/valid_to） */
export interface CouponPayload {
  name: string
  type: CouponType
  amount?: number | null
  percent?: number | null
  max_discount?: number | null
  min_spend?: number
  scope?: CouponScope
  scope_refs?: number[]
  total_count?: number
  per_user_limit?: number
  valid_type?: CouponValidType
  valid_from?: string | null
  valid_to?: string | null
  valid_days?: number | null
  status?: CouponStatus
}

export interface CouponStats {
  id: number
  name: string
  total_count: number
  issued_count: number
  received_count: number
  used_count: number
  available_count: number
  issue_rate: number
  use_rate: number
  order_count: number
  discount_sum: number
  order_amount_sum: number
}

export function getCoupons(params: {
  keyword?: string
  status?: CouponStatus | ''
  type?: CouponType | ''
  page?: number
  page_size?: number
} = {}) {
  return request.get<ApiResult<CouponListResult>>('/admin/coupons', { params })
}

export function createCoupon(data: CouponPayload) {
  return request.post<ApiResult<{ id: number }>>('/admin/coupons', data)
}

export function updateCoupon(id: number, data: Partial<CouponPayload>) {
  return request.put<ApiResult<null>>(`/admin/coupons/${id}`, data)
}

export function stopCoupon(id: number) {
  return request.post<ApiResult<null>>(`/admin/coupons/${id}/stop`)
}

export function getCouponStats(id: number) {
  return request.get<ApiResult<CouponStats>>(`/admin/coupons/${id}/stats`)
}

/** 领取/核销明细导出（CSV blob 下载） */
export function exportCoupon(id: number) {
  return request.get(`/admin/coupons/${id}/export`, { responseType: 'blob' })
}

// ---------- 满减活动 ----------

export type PromotionStatus = 'active' | 'stopped'

export interface PromotionTier {
  min: number
  discount: number
}

export interface AdminPromotion {
  id: number
  name: string
  rules: PromotionTier[]
  scope: CouponScope
  scope_refs: number[]
  start_at: string
  end_at: string
  status: PromotionStatus
  /** 是否处于运行时间窗内（展示用） */
  running: boolean
  created_at: string | null
}

export interface PromotionListResult {
  list: AdminPromotion[]
  pagination: Pagination
}

export interface PromotionPayload {
  name: string
  rules: PromotionTier[]
  scope?: CouponScope
  scope_refs?: number[]
  start_at: string
  end_at: string
  status?: PromotionStatus
}

export function getPromotions(params: { page?: number; page_size?: number; status?: PromotionStatus | '' } = {}) {
  return request.get<ApiResult<PromotionListResult>>('/admin/promotions', { params })
}

export function createPromotion(data: PromotionPayload) {
  return request.post<ApiResult<{ id: number }>>('/admin/promotions', data)
}

export function updatePromotion(id: number, data: Partial<PromotionPayload>) {
  return request.put<ApiResult<null>>(`/admin/promotions/${id}`, data)
}

export function togglePromotion(id: number) {
  return request.post<ApiResult<{ status: PromotionStatus }>>(`/admin/promotions/${id}/toggle`)
}
