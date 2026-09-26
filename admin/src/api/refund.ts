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

export function getRefunds(params: { refund_no?: string; order_no?: string; status?: RefundStatus; type?: RefundType; return_status?: string; aged_hours?: number; retry_exhausted?: 1; page?: number; page_size?: number }) {
  return request.get<ApiResult<RefundListResult>>('/admin/refunds', { params })
}

/** 退款概览统计（GET /admin/refunds/stats，只读聚合） */
export interface RefundStats {
  status_counts: Record<RefundStatus, number>
  status_amounts: Record<RefundStatus, string>
  /** 未完结单账龄分桶（小时） */
  aging: { lt_24h: number; h24_72: number; gt_72h: number }
  queues: {
    /** processing 超 24h，疑似渠道回调丢失 */
    processing_stuck: number
    /** failed 且 retry_count 达上限，待人工 */
    failed_maxed: number
    /** return_refund 待退货超 7 天未发货 */
    return_waiting_overdue: number
  }
}

/** 退款策略（GET/PUT /admin/refunds/policy，refund.* 配置） */
export interface RefundPolicy {
  /** 自动同意阈值（元），'0.00' = 不启用 */
  auto_approve_amount: string
  /** 渠道退款失败自动重试上限（次），超过转人工 */
  max_retry: number
  /** 纠纷处理 SLA（小时），超时列表标记催办 */
  dispute_sla_hours: number
  /** 退货地址模板（纯文本，支持换行） */
  return_address_template: string
}

export type RefundPolicyUpdate = Partial<RefundPolicy>

export function getRefundPolicy() {
  return request.get<ApiResult<RefundPolicy>>('/admin/refunds/policy')
}

export function updateRefundPolicy(payload: RefundPolicyUpdate) {
  return request.put<ApiResult<RefundPolicy>>('/admin/refunds/policy', payload)
}

export function getRefundStats() {
  return request.get<ApiResult<RefundStats>>('/admin/refunds/stats')
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

/** 实收商品状态（良品/残次）中文 */
export const RETURN_CONDITION_LABELS: Record<ReturnCondition, string> = {
  good: '良品',
  defective: '残次',
}

/** 后台处理动作中文（用于处理流水） */
export const REFUND_ACTION_LABELS: Record<string, string> = {
  apply: '用户提交申请',
  process_approve: '后台同意退款',
  process_reject: '后台拒绝退款',
  return_received: '后台确认收货',
  coupon_returned: '返还优惠券',
}

// ---------- 批量审核 + 导出（#5） ----------

export interface BatchProcessResultItem {
  id: number
  refund_no: string
  status?: string
  status_label?: string
  reason?: string
}

export interface BatchProcessResult {
  total: number
  succeeded_count: number
  failed_count: number
  succeeded: BatchProcessResultItem[]
  failed: BatchProcessResultItem[]
}

/** 批量审核：循环复用 RefundService::process（每单独立事务），非 pending 单逐单回报失败 */
export function batchProcessRefunds(ids: number[], action: 'approve' | 'reject', remark?: string) {
  return request.post<ApiResult<BatchProcessResult>>('/admin/refunds/batch-process', { ids, action, remark })
}

/** 导出退款列表（CSV，随当前筛选全量导出；Excel 兼容 UTF-8 BOM） */
export async function exportRefunds(params: Parameters<typeof getRefunds>[0] = {}) {
  const res = await request.get<Blob>('/admin/refunds/export', {
    params,
    responseType: 'blob',
  })

  const url = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = url
  a.download = `refunds-${new Date().toISOString().slice(0, 19).replaceAll(/[-:T]/g, '')}.csv`
  a.click()
  URL.revokeObjectURL(url)
}
