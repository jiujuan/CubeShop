import request from './request'
import type { ApiResult, Pagination } from './types'

// ---------- 订单（API 文档 6 / Roadmap P4） ----------

export type OrderStatus =
  | 'pending_payment'
  | 'paid'
  | 'shipped'
  | 'completed'
  | 'cancelled'
  | 'refunding'
  | 'refunded'

/** 状态中文映射（与后端 Order::STATUS_LABELS 对齐） */
export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
  pending_payment: '待支付',
  paid: '已支付',
  shipped: '已发货',
  completed: '已完成',
  cancelled: '已取消',
  refunding: '退款中',
  refunded: '已退款',
}

export interface OrderItemView {
  product_title: string
  sku_specs: Record<string, string>
  sku_image: string | null
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

export interface OrderBrief {
  id: number
  order_no: string
  status: OrderStatus
  status_label: string
  total_amount: string
  freight_amount: string
  pay_amount: string
  item_count: number
  items: OrderItemView[]
  created_at: string
}

export interface OrderDetail extends OrderBrief {
  remark: string | null
  address_snapshot: AddressSnapshot | null
  cancel_reason: string | null
  paid_at: string | null
  shipped_at: string | null
  completed_at: string | null
  cancelled_at: string | null
}

export interface OrderListResult {
  list: OrderBrief[]
  pagination: Pagination
}

export interface CreateOrderResult {
  order_id: number
  order_no: string
  total_amount: string
  freight_amount: string
  pay_amount: string
  status: OrderStatus
}

/** 创建订单（cart_item_ids 不传则结算全部有效项） */
export function createOrder(data: { address_id: number; cart_item_ids?: number[]; remark?: string }) {
  return request.post<ApiResult<CreateOrderResult>>('/orders', data)
}

export function getOrders(params: { status?: OrderStatus; page?: number; page_size?: number }) {
  return request.get<ApiResult<OrderListResult>>('/orders', { params })
}

export function getOrder(id: number) {
  return request.get<ApiResult<OrderDetail>>(`/orders/${id}`)
}

export function cancelOrder(id: number, reason?: string) {
  return request.post<ApiResult<OrderDetail>>(`/orders/${id}/cancel`, { reason })
}
