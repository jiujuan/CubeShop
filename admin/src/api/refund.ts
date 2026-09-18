import request from './request'
import type { ApiResult } from './request'

// ---------- 退款处理（API 文档 8.4 / Roadmap P5，权限 refund.view / refund.process） ----------

export type RefundStatus = 'pending' | 'approved' | 'rejected' | 'success' | 'failed'
export type RefundType = 'refund' | 'return_refund'
export type ReturnStatus = 'waiting_return' | 'shipping' | 'received' | 'exception' | null
export type ReturnCondition = 'good' | 'defective'

export interface ReturnDetail {
  sku_id: number
  product_title?: string | null
  sku_specs?: Record<string, string> | null
  quantity: number
}

export interface ReturnReceivedDetail {
  sku_id: number
  quantity: number
  condition: ReturnCondition
}

export interface Refund {
  id: number
  refund_no: string
  order_id: number
  order_no: string
  user_id: number
  type: RefundType
  amount: string
  reason: string | null
  /** 用户申请凭证图片（URL 数组） */
  images: string[]
  status: RefundStatus
  return_status: ReturnStatus
  return_tracking_no: string | null
  return_express_company: string | null
  return_details: ReturnDetail[] | null
  return_received_details: ReturnReceivedDetail[] | null
  return_received_at: string | null
  return_exception_reason: string | null
  admin_remark: string | null
  /** 后台处理说明图片（URL 数组） */
  admin_images: string[]
  processed_by: number | null
  processed_by_name: string | null
  processed_at: string | null
  created_at: string
  order_status: string
}

/** 退款详情中的订单商品行（产品图 / 产品链接 / 规格 / 数量） */
export interface RefundDetailItem {
  product_id: number | null
  product_public_id: string | null
  product_title: string
  sku_id: number | null
  sku_public_id: string | null
  sku_specs: Record<string, string>
  sku_image: string | null
  price: string
  quantity: number
  total_amount: string
}

/** 退款详情中的后台处理流水 */
export interface RefundDetailLog {
  id: number
  actor_type: 'admin' | 'customer'
  operator: { id: number; username: string | null; nickname: string | null } | null
  action: string
  /** 原始 content（JSON 字符串或纯文本） */
  content: string | null
  /** 后端解码后的结构（用于中文键值 + 图片渲染） */
  content_data: unknown
  created_at: string | null
}

export interface RefundDetail extends Refund {
  user: { id: number; username: string | null; nickname: string | null; phone: string | null } | null
  order: { order_no: string; status: string; pay_amount: string; created_at: string | null } | null
  items: RefundDetailItem[]
  logs: RefundDetailLog[]
}

export interface RefundListResult {
  list: Refund[]
  pagination: { page: number; page_size: number; total: number; total_pages: number }
}

export function getRefunds(params: { refund_no?: string; order_no?: string; status?: RefundStatus; page?: number; page_size?: number }) {
  return request.get<ApiResult<RefundListResult>>('/admin/refunds', { params })
}

/** 退款详情（含订单商品明细与后台处理流水） */
export function getRefundDetail(id: number) {
  return request.get<ApiResult<RefundDetail>>(`/admin/refunds/${id}`)
}

/** 审核退款：同意/拒绝均可附理由与说明图片 */
export function processRefund(id: number, action: 'approve' | 'reject', payload: { admin_remark?: string; admin_images?: string[] } = {}) {
  return request.post<ApiResult<Refund>>(`/admin/refunds/${id}/process`, {
    action,
    admin_remark: payload.admin_remark,
    admin_images: payload.admin_images,
  })
}

/** 确认收货（退货退款专用）：按实收明细回库存 + 完成退款 */
export function receiveRefund(id: number, payload: { received_details: ReturnReceivedDetail[]; exception_reason?: string }) {
  return request.post<ApiResult<Refund>>(`/admin/refunds/${id}/receive`, payload)
}

export const REFUND_STATUS_LABELS: Record<RefundStatus, string> = {
  pending: '待审核',
  approved: '已同意',
  rejected: '已拒绝',
  success: '退款成功',
  failed: '退款失败',
}

export const REFUND_STATUS_CLASS: Record<RefundStatus, string> = {
  pending: 'bg-orange-100 text-orange-500',
  approved: 'bg-blue-100 text-blue-500',
  rejected: 'bg-slate-100 text-slate-500',
  success: 'bg-green-100 text-green-600',
  failed: 'bg-red-100 text-red-500',
}

export const REFUND_TYPE_LABELS: Record<RefundType, string> = {
  refund: '仅退款',
  return_refund: '退货退款',
}

export const RETURN_STATUS_LABELS: Record<Exclude<ReturnStatus, null>, string> = {
  waiting_return: '待退货',
  shipping: '退货中',
  received: '已收货',
  exception: '异常',
}

/** 后台处理动作中文（用于处理流水） */
export const REFUND_ACTION_LABELS: Record<string, string> = {
  apply: '用户提交申请',
  process_approve: '后台同意退款',
  process_reject: '后台拒绝退款',
  return_received: '后台确认收货',
  coupon_returned: '返还优惠券',
}
