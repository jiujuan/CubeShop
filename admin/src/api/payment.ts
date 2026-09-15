import request from './request'
import type { ApiResult } from './request'

/**
 * 支付管理 / 支付日志（API 文档 8.11 / 8.12，权限 payment.view，关闭需 payment.manage）
 */

export type PaymentStatus = 'pending' | 'success' | 'failed' | 'closed' | 'reviewing'
export type PaymentChannel = 'wechat' | 'alipay' | 'offline' | 'balance' | 'mock'

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
  reviewing: '待核账',
}

export const PAYMENT_STATUS_CLASS: Record<PaymentStatus, string> = {
  pending: 'bg-orange-100 text-orange-500',
  success: 'bg-green-100 text-green-600',
  failed: 'bg-red-100 text-red-500',
  closed: 'bg-slate-100 text-slate-500',
  reviewing: 'bg-amber-100 text-amber-600',
}

export const PAYMENT_CHANNEL_LABELS: Record<PaymentChannel, string> = {
  wechat: '微信支付',
  alipay: '支付宝',
  balance: '余额支付',
  offline: '线下转账',
  mock: '本地模拟',
}

export const PAYMENT_EVENT_LABELS: Record<string, string> = {
  create: '创建支付单',
  callback: '渠道回调',
  notify: '异步通知',
  close: '后台关闭',
}

/* ----------------------------- 支付渠道配置（§5） ----------------------------- */

export type PaymentChannelCode = 'wechat' | 'alipay' | 'balance' | 'offline' | 'mock'

export interface PaymentChannelRow {
  channel: PaymentChannelCode
  name: string
  sort: number
  enabled: boolean
  sandbox: boolean
  is_online: boolean
  is_configured: boolean
  config: Record<string, unknown>
  notify_url: string | null
  return_url: string | null
  remark: string | null
  updated_at: string | null
}

export interface PaymentChannelListResult {
  list: PaymentChannelRow[]
}

export interface TestResult {
  ok: boolean
  message: string
  detail?: Record<string, unknown>
}

export function getPaymentChannels() {
  return request.get<ApiResult<PaymentChannelListResult>>('/admin/payment-channels')
}

export function getPaymentChannel(channel: string) {
  return request.get<ApiResult<PaymentChannelRow>>(`/admin/payment-channels/${channel}`)
}

export function updatePaymentChannel(channel: string, payload: Record<string, unknown>) {
  return request.put<ApiResult<PaymentChannelRow>>(`/admin/payment-channels/${channel}`, payload)
}

export function togglePaymentChannel(channel: string, enabled: boolean) {
  return request.post<ApiResult<PaymentChannelRow>>(`/admin/payment-channels/${channel}/toggle`, { enabled })
}

export function testPaymentChannel(channel: string) {
  return request.post<ApiResult<TestResult>>(`/admin/payment-channels/${channel}/test`)
}

/* ----------------------------- 余额充值单管理（§5.2 / §6.5） ----------------------------- */

export type RechargeStatus = 'pending' | 'reviewing' | 'success' | 'failed' | 'closed'
export type RechargeChannel = 'wechat' | 'alipay' | 'balance' | 'offline'

export interface BalanceRechargeRow {
  id: number
  recharge_no: string
  user_id: number
  user_name: string | null
  amount: string
  gift_amount: string
  total: string
  channel: RechargeChannel
  channel_label: string
  status: RechargeStatus
  status_label: string
  paid_at: string | null
  created_at: string | null
}

export interface BalanceRechargeQuery {
  recharge_no?: string
  user_id?: number
  channel?: RechargeChannel
  status?: RechargeStatus
  start_time?: string
  end_time?: string
  page?: number
  page_size?: number
}

export interface BalanceRechargeDetail extends BalanceRechargeRow {
  payment: {
    id: number
    payment_no: string
    channel: string
    channel_label: string
    status: string
    status_label: string
    channel_trade_no: string | null
    review_remark: string | null
    reviewed_at: string | null
  } | null
  voucher_url: string | null
  payer_name: string | null
  payer_account: string | null
  transfer_no: string | null
  transferred_at: string | null
  balance_log: { amount: string; balance_after: string; created_at: string } | null
}

export interface BalanceRechargeListResult {
  list: BalanceRechargeRow[]
  pagination: { page: number; page_size: number; total: number; total_pages: number }
}

export function getBalanceRecharges(params: BalanceRechargeQuery) {
  return request.get<ApiResult<BalanceRechargeListResult>>('/admin/balance-recharges', { params })
}

export function getBalanceRecharge(id: number) {
  return request.get<ApiResult<BalanceRechargeDetail>>(`/admin/balance-recharges/${id}`)
}

export function reviewBalanceRecharge(id: number, pass: boolean, remark?: string) {
  return request.post<ApiResult<BalanceRechargeRow>>(`/admin/balance-recharges/${id}/review`, { pass, remark })
}

export async function exportBalanceRecharges(params: BalanceRechargeQuery, filename?: string) {
  const response = await request.get('/admin/balance-recharges/export', {
    params,
    responseType: 'blob',
    timeout: 60000,
  })

  let name = filename || 'balance-recharges.csv'
  const disposition = String(response.headers['content-disposition'] || '')
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)/i.exec(disposition)
  if (match) name = decodeURIComponent(match[1])

  const url = URL.createObjectURL(response.data as Blob)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  link.click()
  URL.revokeObjectURL(url)
}

export const RECHARGE_STATUS_LABELS: Record<RechargeStatus, string> = {
  pending: '待支付',
  reviewing: '待核账',
  success: '充值成功',
  failed: '充值失败',
  closed: '已关闭',
}

export const RECHARGE_STATUS_CLASS: Record<RechargeStatus, string> = {
  pending: 'bg-slate-100 text-slate-500',
  reviewing: 'bg-orange-100 text-orange-500',
  success: 'bg-green-100 text-green-600',
  failed: 'bg-red-100 text-red-500',
  closed: 'bg-slate-100 text-slate-500',
}

export const RECHARGE_CHANNEL_LABELS: Record<RechargeChannel, string> = {
  wechat: '微信支付',
  alipay: '支付宝',
  balance: '余额支付',
  offline: '线下转账',
}

/* ----------------------------- 线下支付单核账（payment.offline.review） ----------------------------- */

export function reviewPayment(id: number, pass: boolean, remark?: string) {
  return request.post<ApiResult<PaymentRow>>(`/admin/payments/${id}/review`, { pass, remark })
}
