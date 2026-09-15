import request from './request'
import type { ApiResult } from './request'

/**
 * 支付管理 / 支付日志（API 文档 8.11 / 8.12，权限 payment.view，关闭需 payment.manage）
 */

export type PaymentStatus = 'pending' | 'success' | 'failed' | 'closed'
export type PaymentChannel = 'wechat' | 'alipay'

export interface PaymentRow {
  id: number
  payment_no: string
  order_id: number
  order_no: string
  user_id: number
  channel: PaymentChannel
  channel_label: string
  amount: string
  status: PaymentStatus
  status_label: string
  channel_trade_no: string | null
  paid_at: string | null
  created_at: string | null
  log_count: number
}

export interface PaymentLogRow {
  id: number
  payment_id: number | null
  payment_no: string | null
  event: string
  event_label: string
  request_preview: string | null
  response_preview: string | null
  created_at: string | null
  /** 详情接口才有 */
  request_data?: unknown
  response_data?: unknown
}

export interface PaymentOrderBrief {
  id: number
  order_no: string
  status: string
  status_label: string
  pay_amount: string
  created_at: string | null
}

export interface PaymentDetail extends PaymentRow {
  order: PaymentOrderBrief | null
  logs: PaymentLogRow[]
}

export interface PaymentSummary {
  total: number
  success_count: number
  success_amount: string
  pending_count: number
  failed_count: number
}

export interface PaymentListResult {
  list: PaymentRow[]
  pagination: { page: number; page_size: number; total: number; total_pages: number }
  summary: PaymentSummary
}

export interface PaymentQuery {
  payment_no?: string
  order_no?: string
  user_id?: number
  channel?: PaymentChannel
  status?: PaymentStatus
  start_time?: string
  end_time?: string
  page?: number
  page_size?: number
}

export interface PaymentLogQuery {
  payment_no?: string
  event?: string
  start_time?: string
  end_time?: string
  page?: number
  page_size?: number
}

export function getPayments(params: PaymentQuery) {
  return request.get<ApiResult<PaymentListResult>>('/admin/payments', { params })
}

export function getPayment(id: number) {
  return request.get<ApiResult<PaymentDetail>>(`/admin/payments/${id}`)
}

/** 关闭待支付单（仅 pending 可关，需 payment.manage） */
export function closePayment(id: number, reason?: string) {
  return request.post<ApiResult<PaymentRow>>(`/admin/payments/${id}/close`, { reason })
}

export function getPaymentLogs(params: PaymentLogQuery) {
  return request.get<ApiResult<{ list: PaymentLogRow[]; pagination: { page: number; page_size: number; total: number; total_pages: number } }>>(
    '/admin/payment-logs',
    { params },
  )
}

export function getPaymentLog(id: number) {
  return request.get<ApiResult<PaymentLogRow>>(`/admin/payment-logs/${id}`)
}

/** 支付单导出（CSV，Excel 可直接打开） */
export async function exportPayments(params: PaymentQuery, filename?: string) {
  const response = await request.get('/admin/payments/export', {
    params,
    responseType: 'blob',
    timeout: 60000,
  })

  let name = filename || 'payments.csv'
  if (!filename) {
    const disposition = String(response.headers['content-disposition'] || '')
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)/i.exec(disposition)
    if (match) name = decodeURIComponent(match[1])
  }

  const url = URL.createObjectURL(response.data as Blob)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  link.click()
  URL.revokeObjectURL(url)
}

export const PAYMENT_STATUS_LABELS: Record<PaymentStatus, string> = {
  pending: '待支付',
  success: '支付成功',
  failed: '支付失败',
  closed: '已关闭',
}

export const PAYMENT_STATUS_CLASS: Record<PaymentStatus, string> = {
  pending: 'bg-orange-100 text-orange-500',
  success: 'bg-green-100 text-green-600',
  failed: 'bg-red-100 text-red-500',
  closed: 'bg-slate-100 text-slate-500',
}

export const PAYMENT_CHANNEL_LABELS: Record<PaymentChannel, string> = {
  wechat: '微信支付',
  alipay: '支付宝',
}

export const PAYMENT_EVENT_LABELS: Record<string, string> = {
  create: '创建支付单',
  callback: '渠道回调',
  notify: '异步通知',
  close: '后台关闭',
}
