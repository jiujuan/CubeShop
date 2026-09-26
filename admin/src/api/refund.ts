import request from './request'
import type { ApiResult } from './request'

// ---------- 退款处理（API 文档 8.4 / Roadmap P5，权限 refund.view / refund.process） ----------

export type RefundStatus = 'pending' | 'approved' | 'rejected' | 'success' | 'failed' | 'processing'
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
  // Phase 4/5 渠道退款字段
  channel: string | null
  out_refund_no: string | null
  channel_refund_no: string | null
  refund_status: string | null
  failed_reason: string | null
  retry_count: number
  refunded_at: string | null
  max_retry: number
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

/** 退款全链路事件日志（refund_logs，append-only；Phase 1 引入，Phase 5 暴露给后台） */
export interface RefundLogEntry {
  id: number
  type: string
  channel: string | null
  out_refund_no: string | null
  channel_status: string | null
  actor_type: string | null
  actor_id: number | null
  note: string | null
  request: unknown
  response: unknown
  created_at: string | null
}

export interface RefundLogResult {
  refund_id: number
  logs: RefundLogEntry[]
}

/** 退款全链路日志（Phase 5：后台「退款日志」抽屉读取） */
export function getRefundLogs(id: number) {
  return request.get<ApiResult<RefundLogResult>>(`/admin/refunds/${id}/logs`)
}

/** 后台重试退款（失败态，复用 out_refund_no 幂等，达 max_retry 转人工） */
export function retryRefund(id: number) {
  return request.post<ApiResult<Refund>>(`/admin/refunds/${id}/retry`)
}

export const REFUND_MAX_RETRY = 3
export const REFUND_CHANNEL_LABELS: Record<string, string> = {
  wechat: '微信支付',
  alipay: '支付宝',
  balance: '余额',
  offline: '线下',
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
  processing: '退款中',
}

export const REFUND_STATUS_CLASS: Record<RefundStatus, string> = {
  pending: 'bg-orange-100 text-orange-500',
  approved: 'bg-blue-100 text-blue-500',
  rejected: 'bg-slate-100 text-slate-500',
  success: 'bg-green-100 text-green-600',
  failed: 'bg-red-100 text-red-500',
  processing: 'bg-blue-50 text-blue-500',
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
