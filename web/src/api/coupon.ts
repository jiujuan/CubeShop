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
  /** 命中范围 id（scope=all 时为空数组；T-038 详情页过滤用，public_id 字符串） */
  scope_refs: string[]
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

// ---------- 结算可用券与满减预览（V1.1 二期 T-039） ----------

/** 结算可用券项（对齐 CouponService::availableFor 的 usable 项） */
export interface AvailableCoupon {
  user_coupon_id: number
  coupon_id: number
  name: string
  type: CouponType
  type_label: string
  amount: number | null
  percent: number | null
  max_discount: number | null
  min_spend: number
  scope: CouponScope
  scope_label: string
  expire_at: string
  near_expiry: boolean
  /** 按命中范围金额预览的优惠额（与下单同口径 couponDiscount） */
  discount: number
}

/** 不可用券项（附原因：门槛不足/范围不符/已过期/已停止使用） */
export interface UnavailableCoupon {
  user_coupon_id: number
  coupon_id: number | null
  name: string | null
  min_spend: number
  expire_at: string | null
  reason: string
}

export interface AvailableCouponsResponse {
  usable: AvailableCoupon[]
  unusable: UnavailableCoupon[]
}

/** 结算可用券 GET /coupons/available（items 缺 category_id 由后端回库补全） */
export function getAvailableCoupons(params: {
  amount: number | string
  /** P2-11：product_id 传 public_id 字符串（与购物车/订单出口一致），后端 resolve 回主键 */
  items: Array<{ product_id?: string | number | null; price: number | string; quantity: number }>
}) {
  return request.get<ApiResult<AvailableCouponsResponse>>('/coupons/available', { params })
}

/** 满减展示结构（对齐 PromotionService::displayFor） */
export interface PromotionDisplay {
  promotion_id: number
  name: string
  scope: CouponScope
  scope_refs: Array<string | number>
  base_amount: number
  current_tier: { min: number; discount: number } | null
  discount: number
  next_tier: { min: number; discount: number } | null
  gap_to_next: number
}

/** 满减预览 GET /promotions/preview（无命中时 promotion 为 null） */
export function getPromotionPreview(params: {
  /** P2-11：product_id 传 public_id 字符串（与购物车/订单出口一致），后端 resolve 回主键 */
  items: Array<{ product_id?: string | number | null; price: number | string; quantity: number }>
}) {
  return request.get<ApiResult<{ promotion: PromotionDisplay | null }>>('/promotions/preview', { params })
}
