import request from './request'
import type { ApiResult } from './request'

/** 单期指标 */
export interface PeriodMetrics {
  orders: number
  paid_orders: number
  sales: string
  aov: string
  conversion_rate: number
}

export interface ReportOverview {
  today: PeriodMetrics
  yesterday: PeriodMetrics
  last_7_days: PeriodMetrics
  pending: { ship: number; refund: number; review: number; stock_warning: number }
  conversion_rate: number
}

export interface TrendPoint {
  date: string
  orders: number
  sales: string
}

export interface TopProduct {
  product_id: number
  title: string
  quantity: number
  amount: string
}

export interface CategoryShareItem {
  category_id: number | null
  category_name: string
  amount: string
  percent: number
}

export interface UserReport {
  days: number
  series: Array<{ date: string; new_users: number }>
  total_new_users: number
  buyers: number
  repeat_buyers: number
  repurchase_rate: number
}

export interface ExportResult {
  rows: Array<Record<string, string>>
  total: number
  truncated: boolean
  limit: number
}

/** 核心指标卡 */
export function getReportOverview() {
  return request.get<ApiResult<ReportOverview>>('/admin/reports/overview')
}

/** 趋势（days ∈ 1..90） */
export function getReportTrend(days = 30) {
  return request.get<ApiResult<{ days: number; series: TrendPoint[] }>>('/admin/reports/trend', { params: { days } })
}

/** 商品 TOP */
export function getTopProducts(params: { limit?: number; days?: number } = {}) {
  return request.get<ApiResult<{ list: TopProduct[] }>>('/admin/reports/top-products', { params })
}

/** 分类销售额占比 */
export function getCategoryShare(days = 30) {
  return request.get<ApiResult<{ total: string; items: CategoryShareItem[] }>>('/admin/reports/category-share', { params: { days } })
}

/** 用户增长与复购 */
export function getReportUsers(days = 30) {
  return request.get<ApiResult<UserReport>>('/admin/reports/users', { params: { days } })
}

/** 区间订单明细导出 */
export function exportOrders(start: string, end: string) {
  return request.get<ApiResult<ExportResult>>('/admin/reports/export', { params: { start, end } })
}
