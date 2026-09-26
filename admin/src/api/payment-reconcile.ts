import request from './request'
import type { ApiResult } from './request'
import type { Pagination } from './announcement'

// ============ 支付渠道日终对账（A7-支付渠道对账，权限 payment.reconcile.view / payment.reconcile.handle） ============

export type PaymentChannel = 'wechat' | 'alipay' | 'balance' | 'offline' | 'mock'

/** 渠道中文（后端 Payment::CHANNEL_LABELS 镜像） */
export const PAYMENT_CHANNEL_LABELS: Record<PaymentChannel, string> = {
  wechat: '微信支付',
  alipay: '支付宝',
  balance: '余额支付',
  offline: '线下转账',
  mock: '本地模拟',
}

/** 订单来源平台（后端 Payment::PLATFORMS 镜像） */
export type PayPlatform = 'web' | 'h5' | 'miniprogram'

/** 平台中文（后端 Payment::PLATFORM_LABELS 镜像） */
export const PAY_PLATFORM_LABELS: Record<PayPlatform, string> = {
  web: 'Web 商城',
  h5: 'H5 手机端',
  miniprogram: '小程序',
}

export type ReconcileRunStatus = 'running' | 'done' | 'partial' | 'failed'

/** 对账批次状态中文（后端 PaymentReconciliationRun::STATUS_LABELS 镜像） */
export const RECONCILE_RUN_STATUS_LABELS: Record<ReconcileRunStatus, string> = {
  running: '对账中',
  done: '一致',
  partial: '存在差异',
  failed: '账单拉取失败',
}

export const RECONCILE_RUN_STATUS_CLASS: Record<ReconcileRunStatus, string> = {
  running: 'bg-blue-50 text-blue-600',
  done: 'bg-emerald-50 text-emerald-600',
  partial: 'bg-amber-50 text-amber-600',
  failed: 'bg-red-50 text-red-600',
}

export type ReconcileDiffType =
  | 'MISSING_LOCAL'
  | 'MISSING_CHANNEL'
  | 'AMOUNT_MISMATCH'
  | 'DUPLICATE_CALLBACK'
  | 'UNKNOWN'
  | 'REFUND_STATUS_MISMATCH'
  | 'REFUND_CHANNEL_MISSING'
  | 'REFUND_LOCAL_MISSING'

/** 差异类型中文（后端 PaymentReconciliationDiff::TYPE_LABELS 镜像） */
export const RECONCILE_DIFF_TYPE_LABELS: Record<ReconcileDiffType, string> = {
  MISSING_LOCAL: '漏单（渠道有本地无）',
  MISSING_CHANNEL: '本地成功·渠道无记录',
  AMOUNT_MISMATCH: '金额不一致',
  DUPLICATE_CALLBACK: '重复回调',
  UNKNOWN: '未知差异',
  REFUND_STATUS_MISMATCH: '退款状态不一致',
  REFUND_CHANNEL_MISSING: '退款·渠道无记录(疑似漏退)',
  REFUND_LOCAL_MISSING: '渠道退款·本地无单',
}

/** 语义配色：渠道长款(橙) > 本地短款/资金风险(红) > 长短款(琥珀) > 重复回调(紫) > 未知(灰) */
export const RECONCILE_DIFF_TYPE_CLASS: Record<ReconcileDiffType, string> = {
  MISSING_LOCAL: 'bg-orange-50 text-orange-600',
  MISSING_CHANNEL: 'bg-red-50 text-red-600',
  AMOUNT_MISMATCH: 'bg-amber-50 text-amber-600',
  DUPLICATE_CALLBACK: 'bg-violet-50 text-violet-600',
  UNKNOWN: 'bg-slate-100 text-slate-500',
  REFUND_STATUS_MISMATCH: 'bg-red-50 text-red-600',
  REFUND_CHANNEL_MISSING: 'bg-orange-50 text-orange-600',
  REFUND_LOCAL_MISSING: 'bg-amber-50 text-amber-600',
}

/** 看板分布条实心配色（语义与列表徽章一致，用于可视化条形） */
export const RECONCILE_DIFF_TYPE_BAR: Record<ReconcileDiffType, string> = {
  MISSING_LOCAL: 'bg-orange-400',
  MISSING_CHANNEL: 'bg-red-400',
  AMOUNT_MISMATCH: 'bg-amber-400',
  DUPLICATE_CALLBACK: 'bg-violet-400',
  UNKNOWN: 'bg-slate-300',
}

export type ReconcileDiffStatus = 'pending' | 'processing' | 'resolved' | 'ignored'

/** 差异处置状态中文（后端 PaymentReconciliationDiff::STATUS_LABELS 镜像） */
export const RECONCILE_DIFF_STATUS_LABELS: Record<ReconcileDiffStatus, string> = {
  pending: '待处理',
  processing: '核查中',
  resolved: '已处置',
  ignored: '已忽略',
}

export const RECONCILE_DIFF_STATUS_CLASS: Record<ReconcileDiffStatus, string> = {
  pending: 'bg-amber-50 text-amber-600',
  processing: 'bg-blue-50 text-blue-600',
  resolved: 'bg-emerald-50 text-emerald-600',
  ignored: 'bg-slate-100 text-slate-500',
}

/** 对账批次头（每渠道每日一条；批次跨平台汇总，无平台维度） */
export interface PaymentReconcileRunRow {
  id: number
  reconcile_date: string
  channel: PaymentChannel
  channel_label: string
  status: ReconcileRunStatus
  status_label: string
  local_count: number
  channel_count: number
  matched_count: number
  diff_count: number
  local_amount: string
  channel_amount: string
  started_at: string | null
  finished_at: string | null
  created_at: string | null
}

/** 单个对账批次详情（含待处理差异按类型汇总） */
export interface PaymentReconcileRunDetail {
  id: number
  reconcile_date: string
  channel: PaymentChannel
  status: ReconcileRunStatus
  status_label: string
  local_count: number
  channel_count: number
  matched_count: number
  diff_count: number
  local_amount: string
  channel_amount: string
  note: string | null
  finished_at: string | null
  /** { [diff_type]: 待处理条数 } */
  pending_by_type: Partial<Record<ReconcileDiffType, number>>
}

/** 对账差异（兼工单） */
export interface PaymentReconcileDiffRow {
  id: number
  reconcile_date: string
  channel: PaymentChannel
  channel_label: string
  /** 订单来源平台；漏单（本地无单可挂）为 null，只计入「全部平台」口径 */
  platform: PayPlatform | null
  platform_label: string | null
  diff_type: ReconcileDiffType
  diff_type_label: string
  payment_no: string | null
  channel_trade_no: string | null
  order_no: string | null
  local_amount: string | null
  channel_amount: string | null
  local_status: string | null
  channel_status: string | null
  detail: string | null
  status: ReconcileDiffStatus
  status_label: string
  handled_by: number | null
  handled_at: string | null
  handle_remark: string | null
  created_at: string | null
}

/** 看板统计（A7 可视化；支持按平台过滤） */
export interface PaymentReconcileStats {
  total_runs: number
  total_diffs: number
  pending_diffs: number
  processing_diffs: number
  resolved_diffs: number
  ignored_diffs: number
  by_type: Partial<Record<ReconcileDiffType, number>>
  /** 按渠道拆分（渠道 → 差异/待处置/已处置/已忽略） */
  by_channel: { channel: PaymentChannel; diffs: number; pending: number; resolved: number; ignored: number }[]
  trend: { date: string; diffs: number }[]
}

export interface PaymentReconcileRunListParams {
  date?: string
  channel?: PaymentChannel
  status?: ReconcileRunStatus
  page?: number
  page_size?: number
}

export interface PaymentReconcileDiffListParams {
  date?: string
  channel?: PaymentChannel
  diff_type?: ReconcileDiffType
  /** payment=仅支付差异，refund=仅退款差异；不传=全部 */
  category?: 'payment' | 'refund'
  status?: ReconcileDiffStatus
  platform?: PayPlatform
  keyword?: string
  page?: number
  page_size?: number
}

// ---------- 对账运行清单 / 详情 ----------

export function getPaymentReconciles(params: PaymentReconcileRunListParams = {}) {
  return request.get<ApiResult<{ list: PaymentReconcileRunRow[]; pagination: Pagination }>>(
    '/admin/payment-reconciles',
    { params },
  )
}

export function getPaymentReconcileRun(id: number) {
  return request.get<ApiResult<PaymentReconcileRunDetail>>(`/admin/payment-reconciles/${id}`)
}

// ---------- 差异清单 / 处置 / 导出 ----------

export function getPaymentReconcileDiffs(params: PaymentReconcileDiffListParams = {}) {
  return request.get<ApiResult<{ list: PaymentReconcileDiffRow[]; pagination: Pagination }>>(
    '/admin/payment-reconcile-diffs',
    { params },
  )
}

/** action=resolve（默认）按差异类型处置；ignore 只关单 */
export function resolvePaymentReconcileDiff(
  id: number,
  payload: { action?: 'resolve' | 'ignore'; remark?: string } = {},
) {
  return request.post<ApiResult<{ id: number; status: ReconcileDiffStatus; status_label: string }>>(
    `/admin/payment-reconcile-diffs/${id}/resolve`,
    payload,
  )
}

/** 导出对账差异报告（CSV，随当前筛选全量导出；Excel 兼容 UTF-8 BOM） */
export async function exportPaymentReconcileDiffs(params: PaymentReconcileDiffListParams = {}) {
  const res = await request.get<Blob>('/admin/payment-reconcile-diffs/export', {
    params,
    responseType: 'blob',
  })

  const url = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = url
  a.download = `payment-reconcile-diffs-${new Date().toISOString().slice(0, 19).replaceAll(/[-:T]/g, '')}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

// ---------- 看板统计（可视化） ----------

export function getPaymentReconcileStats(params: { platform?: PayPlatform } = {}) {
  return request.get<ApiResult<PaymentReconcileStats>>('/admin/payment-reconciles/stats', { params })
}
