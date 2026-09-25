import request from './request'
import type { ApiResult } from './types'

// ============ 前台对账看板（A7 增强：web 商城侧只读汇总，仅运营可见） ============
//
// 权限：payment.reconcile.view（后端路由 permission 中间件保护，买家账号 403）。
// 只读汇总：不含差异工单明细与处置能力（明细/处置在 admin 后台）。

export type PayPlatform = 'web' | 'h5' | 'miniprogram'

/** 平台中文（后端 Payment::PLATFORM_LABELS 镜像） */
export const PAY_PLATFORM_LABELS: Record<PayPlatform, string> = {
  web: 'Web 商城',
  h5: 'H5 手机端',
  miniprogram: '小程序',
}

export type PaymentChannel = 'wechat' | 'alipay' | 'balance' | 'offline' | 'mock'

/** 渠道中文（后端 Payment::CHANNEL_LABELS 镜像） */
export const PAYMENT_CHANNEL_LABELS: Record<PaymentChannel, string> = {
  wechat: '微信支付',
  alipay: '支付宝',
  balance: '余额支付',
  offline: '线下转账',
  mock: '本地模拟',
}

export type ReconcileDiffType =
  | 'MISSING_LOCAL'
  | 'MISSING_CHANNEL'
  | 'AMOUNT_MISMATCH'
  | 'DUPLICATE_CALLBACK'
  | 'UNKNOWN'

/** 差异类型中文（后端 PaymentReconciliationDiff::TYPE_LABELS 镜像） */
export const RECONCILE_DIFF_TYPE_LABELS: Record<ReconcileDiffType, string> = {
  MISSING_LOCAL: '漏单（渠道有本地无）',
  MISSING_CHANNEL: '本地成功·渠道无记录',
  AMOUNT_MISMATCH: '金额不一致',
  DUPLICATE_CALLBACK: '重复回调',
  UNKNOWN: '未知差异',
}

/** 分布条实心配色（语义与 admin 一致：橙/红/琥珀/紫/灰） */
export const RECONCILE_DIFF_TYPE_BAR: Record<ReconcileDiffType, string> = {
  MISSING_LOCAL: 'bg-orange-400',
  MISSING_CHANNEL: 'bg-red-400',
  AMOUNT_MISMATCH: 'bg-amber-400',
  DUPLICATE_CALLBACK: 'bg-violet-400',
  UNKNOWN: 'bg-slate-300',
}

/** 对账看板汇总（后端 PaymentReconcileService::stats 镜像） */
export interface ReconcileStats {
  total_runs: number
  total_diffs: number
  pending_diffs: number
  processing_diffs: number
  resolved_diffs: number
  ignored_diffs: number
  by_type: Partial<Record<ReconcileDiffType, number>>
  /** 按渠道拆分 */
  by_channel: { channel: PaymentChannel; diffs: number; pending: number; resolved: number; ignored: number }[]
  trend: { date: string; diffs: number }[]
}

/**
 * 对账看板汇总
 * @param platform 订单来源端筛选（web/h5/miniprogram）；不传 = 全部平台
 */
export function getReconcileSummary(params: { platform?: PayPlatform } = {}) {
  return request.get<ApiResult<ReconcileStats>>('/payment-reconcile/dashboard', { params })
}
