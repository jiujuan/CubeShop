import request from './request'
import type { ApiResult } from './request'

// ---------- 订单管理（API 文档 8.3 / Roadmap P6，权限 order.view / order.ship / order.export） ----------

export type OrderStatus =
  | 'pending_payment'
  | 'paid'
  | 'shipped'
  | 'completed'
  | 'cancelled'
  | 'refunding'
  | 'refunded'

export interface OrderItemView {
  product_title: string
  sku_specs: Record<string, string>
  price: string
  quantity: number
  total_amount: string
}

export interface AddressSnapshot {
  contact_name: string
  contact_phone: string
  province?: string | null
  city?: string | null
  district?: string | null
  detail_address?: string
  full_address?: string
}

export interface AdminOrder {
  id: number
  order_no: string
  user_id: number
  status: OrderStatus
  status_label: string
  total_amount: string
  freight_amount: string
  pay_amount: string
  item_count: number
  items: OrderItemView[]
  created_at: string
  remark?: string | null
  address_snapshot?: AddressSnapshot | null
  cancel_reason?: string | null
  paid_at?: string | null
  shipped_at?: string | null
  completed_at?: string | null
  cancelled_at?: string | null
}

export interface OrderListResult {
  list: AdminOrder[]
  pagination: { page: number; page_size: number; total: number; total_pages: number }
}

export interface OrderQuery {
  order_no?: string
  user_id?: number
  status?: OrderStatus
  start_time?: string
  end_time?: string
  page?: number
  page_size?: number
}

export function getOrders(params: OrderQuery) {
  return request.get<ApiResult<OrderListResult>>('/admin/orders', { params })
}

export function getOrder(id: number) {
  return request.get<ApiResult<AdminOrder>>(`/admin/orders/${id}`)
}

export function shipOrder(id: number, remark?: string) {
  return request.post<ApiResult<AdminOrder>>(`/admin/orders/${id}/ship`, { remark })
}

/** 订单导出（CSV，Excel 可直接打开） */
export async function exportOrders(params: OrderQuery, filename?: string) {
  const response = await request.get('/admin/orders/export', {
    params,
    responseType: 'blob',
    timeout: 60000,
  })

  // 从 Content-Disposition 提取文件名（优先使用调用方指定）
  let name = filename || 'orders.csv'
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

// ---------- 订单状态流水（API 文档 8.13，权限 order.log，只读） ----------

export interface OrderLogRow {
  id: number
  order_id: number
  order_no: string | null
  from_status: string | null
  from_status_label: string | null
  to_status: string
  to_status_label: string
  operator_type: string
  operator_type_label: string
  operator_id: number | null
  operator_name: string | null
  remark: string | null
  created_at: string | null
}

export interface OrderLogQuery {
  order_no?: string
  order_id?: number
  to_status?: OrderStatus
  operator_type?: string
  start_time?: string
  end_time?: string
  page?: number
  page_size?: number
}

export function getOrderLogs(params: OrderLogQuery) {
  return request.get<ApiResult<{ list: OrderLogRow[]; pagination: { page: number; page_size: number; total: number; total_pages: number } }>>(
    '/admin/order-logs',
    { params },
  )
}

/** 单笔订单的完整流水（时间正序） */
export function getOrderTimeline(orderId: number) {
  return request.get<ApiResult<{ order_id: number; order_no: string; list: OrderLogRow[] }>>(
    `/admin/orders/${orderId}/logs`,
  )
}

export const OPERATOR_TYPE_LABELS: Record<string, string> = {
  user: '用户',
  admin: '管理员',
  system: '系统',
}

// ---------- 数据概览（API 文档 8.5 / Roadmap P6，权限 dashboard.view） ----------

export interface StockWarning {
  sku_id: number
  product_title: string
  specs: Record<string, string>
  stock: number
  locked_stock: number
}

export interface DashboardData {
  today_orders: number
  today_sales: string
  yesterday_orders: number
  yesterday_sales: string
  pending_ship: number
  pending_refund: number
  stock_warnings: StockWarning[]
  stock_warning_threshold: number
}

export function getDashboard() {
  return request.get<ApiResult<DashboardData>>('/admin/dashboard')
}

export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
  pending_payment: '待支付',
  paid: '已支付',
  shipped: '已发货',
  completed: '已完成',
  cancelled: '已取消',
  refunding: '退款中',
  refunded: '已退款',
}

/**
 * 订单管理页（后台）状态标签：运营视角把 paid 呈现为「待发货」
 *
 * - 与买家端 `Order::TAB_LABELS.pending_ship`（= paid）语义一致，后台发货是运营的主任务队列；
 * - 仅用于订单管理页的 Tab / 筛选下拉 / 状态徽标，**不改动** `ORDER_STATUS_LABELS`
 *   （订单流水页 OrderLogView 的审计语义仍保留「已支付」）；
 * - 后端 `status_label` 仍返回「已支付」，前端展示以此表为准。
 */
export const ORDER_TAB_LABELS: Record<OrderStatus, string> = {
  ...ORDER_STATUS_LABELS,
  paid: '待发货',
}

export const ORDER_STATUS_CLASS: Record<OrderStatus, string> = {
  pending_payment: 'bg-orange-100 text-orange-500',
  paid: 'bg-blue-100 text-blue-500',
  shipped: 'bg-cyan-100 text-cyan-600',
  completed: 'bg-green-100 text-green-600',
  cancelled: 'bg-slate-100 text-slate-500',
  refunding: 'bg-purple-100 text-purple-500',
  refunded: 'bg-red-100 text-red-500',
}
