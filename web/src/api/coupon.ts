import request from './request'
import type { ApiResult, Pagination } from './types'

/** 券类型 */
export type CouponType = 'fixed' | 'percent'
/** 适用范围 */
export type CouponScope = 'all' | 'category' | 'product'
/** 有效期类型 */
export type CouponValidType = 'absolute' | 'relative'
/** 用户券状态 */
export type UserCouponStatus = 'unused' | 'used' | 'expired' | 'returned'

/** 领券中心可领券（对齐 CouponService::receivableCoupons） */
export interface ReceivableCoupon {
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
  /** 命中范围 id（scope=all 时为空数组；T-038 详情页过滤用） */
  scope_refs: number[]
  valid_type: CouponValidType
  valid_to: string | null
  valid_days: number | null
  total_count: number
  remaining: number
  received_by_me: number
  can_receive: boolean
}

/** 我的券项（对齐 StorefrontCouponController::formatUserCoupon） */
export interface UserCouponItem {
  id: number
  coupon_id: number
  name: string | null
  type: CouponType | null
  type_label: string | null
  amount: number | null
  percent: number | null
  max_discount: number | null
  min_spend: number
  scope: CouponScope | null
  scope_label: string | null
  status: UserCouponStatus
  status_label: string
  expire_at: string
  near_expiry: boolean
  used_order_id: number | null
  used_at: string | null
}

export interface CouponCenterResponse {
  list: ReceivableCoupon[]
}

export interface MyCouponsResponse {
  list: UserCouponItem[]
  pagination: Pagination
}

export interface ReceiveCouponResponse {
  user_coupon_id: number
  coupon_id: number
  expire_at: string
}

/** 领券中心（登录可选：未登录仅返回券面信息，不含个人领取状态） */
export function getCouponCenter() {
  return request.get<ApiResult<CouponCenterResponse>>('/coupons')
}

/** 领取优惠券 POST /coupons/{id}/receive */
export function receiveCoupon(id: number) {
  return request.post<ApiResult<ReceiveCouponResponse>>(`/coupons/${id}/receive`)
}

/** 我的券 GET /me/coupons（status: unused/used/expired/returned，null=全部） */
export function getMyCoupons(params: { status?: UserCouponStatus | null; page?: number; page_size?: number } = {}) {
  return request.get<ApiResult<MyCouponsResponse>>('/me/coupons', { params })
}
