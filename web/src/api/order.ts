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

/** 订单列表分组（与后端 Order::TAB_STATUS_MAP 对齐，V1.1 T-004） */
export type OrderTab =
  | 'all'
  | 'pending_payment'
  | 'pending_ship'
  | 'pending_receive'
  | 'pending_review'
  | 'after_sale'

export const ORDER_TABS: Array<{ value: OrderTab; label: string; empty: string }> = [
  { value: 'all', label: '全部', empty: '暂无订单' },
  { value: 'pending_payment', label: '待付款', empty: '暂无待付款订单' },
  { value: 'pending_ship', label: '待发货', empty: '暂无待发货订单' },
  { value: 'pending_receive', label: '待收货', empty: '暂无待收货订单' },
  { value: 'pending_review', label: '待评价', empty: '暂无待评价订单' },
  { value: 'after_sale', label: '退款售后', empty: '暂无售后订单' },
]

export interface OrderItemView {
  /** 行项目 ID（评价入口需要） */
  id?: number
  product_id?: number | null
  sku_id?: number | null
  product_title: string
  sku_specs: Record<string, string>
  sku_image: string | null
  price: string
  quantity: number
  total_amount: string
  /** V1.1 T-016：该行项目的评价状态（未评价为 null） */
  review?: {
    id: number
    rating: number
    content: string | null
    status: 'pending' | 'approved' | 'rejected'
    can_edit: boolean
  } | null
}

/** 列表缩略预览（V1.1 T-004） */
export interface OrderItemPreview {
  product_id?: number | null
  product_title: string
  sku_image: string | null
  quantity: number
}

/** 订单操作可用性（由后端 actions 字段下发，前端不硬编码状态判断） */
export interface OrderActions {
  can_pay: boolean
  can_cancel: boolean
  can_confirm: boolean
  can_refund: boolean
  can_review: boolean
  can_rebuy: boolean
}

/** 订单状态流水（V1.1 T-001） */
export interface OrderLogEntry {
  from_status: OrderStatus | null
  to_status: OrderStatus
  to_status_label: string
  operator_type: 'user' | 'admin' | 'system'
  operator_id: number | null
  remark: string | null
  created_at: string
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
  items_preview: OrderItemPreview[]
  items: OrderItemView[]
  actions: OrderActions
  created_at: string
}

export interface OrderDetail extends OrderBrief {
  remark: string | null
  address_snapshot: AddressSnapshot | null
  cancel_reason: string | null
  refunds: RefundBrief[]
  logs: OrderLogEntry[]
  paid_at: string | null
  shipped_at: string | null
  completed_at: string | null
  cancelled_at: string | null
}

export interface RefundBrief {
  refund_no: string
  amount: string
  reason: string | null
  status: 'pending' | 'approved' | 'rejected' | 'success' | 'failed'
  admin_remark: string | null
  created_at: string
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

export function getOrders(params: {
  status?: OrderStatus
  tab?: OrderTab
  keyword?: string
  start?: string
  end?: string
  page?: number
  page_size?: number
}) {
  return request.get<ApiResult<OrderListResult>>('/orders', { params })
}

export function getOrder(id: number) {
  return request.get<ApiResult<OrderDetail>>(`/orders/${id}`)
}

export function cancelOrder(id: number, reason?: string) {
  return request.post<ApiResult<OrderDetail>>(`/orders/${id}/cancel`, { reason })
}

/** 确认收货（V1.1 E02-A / T-002） */
export function confirmOrder(id: number) {
  return request.post<
    ApiResult<{
      id: number
      order_no: string
      status: OrderStatus
      status_label: string
      completed_at: string | null
    }>
  >(`/orders/${id}/confirm`)
}

/** 再次购买（V1.1 E02-D / T-004）：按历史订单加入购物车 */
export interface RebuyResult {
  added: number
  skipped: Array<{ product_id: number | null; title: string; reason: string }>
  cart_count: number
}

export function rebuyOrder(id: number) {
  return request.post<ApiResult<RebuyResult>>(`/orders/${id}/rebuy`)
}

/** 申请退款（API 文档 6.5） */
export function applyRefund(id: number, reason?: string) {
  return request.post<ApiResult<{ refund_id: number; refund_no: string; amount: string; status: string }>>(
    `/orders/${id}/refund`,
    { reason },
  )
}
