/**
 * 退款纠纷/申诉 API（#4）
 *
 * 后台端点：/admin/refund-disputes*（权限 refund.view / refund.process）。
 * id 为 int 主键（后台路由 {id}），public_id 仅透出展示。
 */
import request from './request'
import type { ApiResult } from './request'

export type RefundDisputeStatus =
  | 'opened'
  | 'platform_involved'
  | 'resolved_refund'
  | 'resolved_reject'
  | 'closed'

export type RefundDisputeReason =
  | 'refund_rejected'
  | 'goods_damaged_dispute'
  | 'timeout_no_process'
  | 'amount_mismatch'
  | 'not_received_return'
  | 'other'

export type RefundDisputeResolution = 'resolved_refund' | 'resolved_reject'

export type RefundDisputeAction =
  | 're_open_refund'
  | 'approve_refund'
  | 'force_receive'
  | 'none'

export interface RefundDisputeBuyer {
  id: number
  username: string
  nickname: string | null
}

export interface RefundDisputeMessage {
  id: string
  sender_type: 'admin' | 'customer' | 'system'
  sender_id: number | null
  body: string
  attachments: string[]
  created_at: string | null
}

export interface RefundDispute {
  id: number
  public_id: string
  refund_no: string | null
  refund_status: string | null
  order_no: string | null
  buyer: RefundDisputeBuyer | null
  reason_code: RefundDisputeReason
  reason_label: string
  description: string | null
  evidence: string[]
  status: RefundDisputeStatus
  status_label: string
  assignee: RefundDisputeBuyer | null
  resolution: RefundDisputeResolution | null
  resolution_note: string | null
  refund_action: RefundDisputeAction | null
  created_at: string | null
  resolved_at: string | null
  /** 详情附加 */
  refund?: {
    refund_no: string
    status: string
    amount: string
    type: string
    reason: string | null
    admin_remark: string | null
    channel: string | null
  } | null
  messages?: RefundDisputeMessage[]
}

export interface RefundDisputeListParams {
  status?: RefundDisputeStatus
  reason_code?: RefundDisputeReason
  keyword?: string
  page?: number
  page_size?: number
}

export interface RefundDisputePagination {
  page: number
  page_size: number
  total: number
  total_pages: number
}

export interface RefundDisputeListResult {
  list: RefundDispute[]
  pagination: RefundDisputePagination
}

export const REFUND_DISPUTE_STATUS_LABELS: Record<RefundDisputeStatus, string> = {
  opened: '待介入',
  platform_involved: '已介入',
  resolved_refund: '支持买家',
  resolved_reject: '支持商家',
  closed: '已关闭',
}

export const REFUND_DISPUTE_STATUS_CLASS: Record<RefundDisputeStatus, string> = {
  opened: 'bg-amber-50 text-amber-600',
  platform_involved: 'bg-blue-50 text-blue-500',
  resolved_refund: 'bg-green-100 text-green-600',
  resolved_reject: 'bg-slate-100 text-slate-500',
  closed: 'bg-slate-100 text-slate-400',
}

export const REFUND_DISPUTE_REASON_LABELS: Record<RefundDisputeReason, string> = {
  refund_rejected: '商家拒绝退款',
  goods_damaged_dispute: '退货商品争议',
  timeout_no_process: '超时未处理',
  amount_mismatch: '退款金额争议',
  not_received_return: '未收到退货/已退未收',
  other: '其他',
}

export const REFUND_DISPUTE_RESOLUTION_LABELS: Record<RefundDisputeResolution, string> = {
  resolved_refund: '支持买家（触发退款动作）',
  resolved_reject: '支持商家（维持原结论）',
}

export const REFUND_DISPUTE_ACTION_LABELS: Record<RefundDisputeAction, string> = {
  re_open_refund: '重新发起审核',
  approve_refund: '同意退款',
  force_receive: '强制收货(良品全收)',
  none: '仅记录裁决',
}

export function getRefundDisputes(params: RefundDisputeListParams) {
  return request.get<ApiResult<RefundDisputeListResult>>('/admin/refund-disputes', { params })
}

export function getRefundDispute(id: number) {
  return request.get<ApiResult<RefundDispute>>(`/admin/refund-disputes/${id}`)
}

export function assignRefundDispute(id: number, adminId: number) {
  return request.post<ApiResult<RefundDispute>>(`/admin/refund-disputes/${id}/assign`, { admin_id: adminId })
}

export function resolveRefundDispute(
  id: number,
  payload: { resolution: RefundDisputeResolution; refund_action?: RefundDisputeAction; note?: string },
) {
  return request.post<ApiResult<RefundDispute>>(`/admin/refund-disputes/${id}/resolve`, payload)
}

export function getRefundDisputeMessages(id: number) {
  return request.get<ApiResult<RefundDisputeMessage[]>>(`/admin/refund-disputes/${id}/messages`)
}

export function postRefundDisputeMessage(id: number, body: string) {
  return request.post<ApiResult<{ id: string }>>(`/admin/refund-disputes/${id}/messages`, { body })
}
