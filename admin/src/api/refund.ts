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
  status: RefundStatus
  return_status: ReturnStatus
  return_tracking_no: string | null
  return_express_company: string | null
  return_details: ReturnDetail[] | null
  return_received_details: ReturnReceivedDetail[] | null
  return_received_at: string | null
  return_exception_reason: string | null
  admin_remark: string | null
  processed_at: string | null
  created_at: string
  order_status: string
}

export interface RefundListResult {
  list: Refund[]
  pagination: { page: number; page_size: number; total: number; total_pages: number }
}

export function getRefunds(params: { refund_no?: string; order_no?: string; status?: RefundStatus; page?: number; page_size?: number }) {
  return request.get<ApiResult<RefundListResult>>('/admin/refunds', { params })
}

export function processRefund(id: number, action: 'approve' | 'reject', adminRemark?: string) {
  return request.post<ApiResult<Refund>>(`/admin/refunds/${id}/process`, { action, admin_remark: adminRemark })
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
