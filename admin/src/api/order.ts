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

export const ORDER_STATUS_CLASS: Record<OrderStatus, string> = {
  pending_payment: 'bg-orange-100 text-orange-500',
  paid: 'bg-blue-100 text-blue-500',
  shipped: 'bg-cyan-100 text-cyan-600',
  completed: 'bg-green-100 text-green-600',
  cancelled: 'bg-slate-100 text-slate-500',
  refunding: 'bg-purple-100 text-purple-500',
  refunded: 'bg-red-100 text-red-500',
}
