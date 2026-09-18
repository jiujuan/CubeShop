import request from './request'
import type { ApiResult, Pagination } from './types'

// ---------- 订单（API 文档 6 / Roadmap P4） ----------

export type OrderStatus =
  | 'pending_payment'
  | 'paid'
  | 'pending_ship'
  | 'shipped'
  | 'completed'
  | 'cancelled'
  | 'refunding'
  | 'refunded'

/** 状态中文映射（与后端 Order::STATUS_LABELS 对齐） */
export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
  pending_payment: '待支付',
  paid: '已支付',
  pending_ship: '待发货',
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
  /** 行项目 ID（评价入口需要；已转 public_id 字符串） */
  id?: string
  product_id?: string | number | null
  sku_id?: string | number | null
  product_title: string
  sku_specs: Record<string, string>
  sku_image: string | null
  price: string
  quantity: number
  total_amount: string
  /** V1.1 T-016：该行项目的评价状态（未评价为 null） */
  review?: {
    id: string
    rating: number
    content: string | null
    /** 评价图片（URL 数组） */
    images?: string[]
    status: 'pending' | 'approved' | 'rejected'
    can_edit: boolean
  } | null
}

/** 列表缩略预览（V1.1 T-004） */
export interface OrderItemPreview {
  product_id?: string | number | null
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
  /** 订单对外标识（已转 public_id 字符串，非自增主键） */
  id: string
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
  type?: 'refund' | 'return_refund'
  amount: string
  reason: string | null
  /** 用户上传的凭证图片（URL 数组） */
  images?: string[]
  status: 'pending' | 'approved' | 'rejected' | 'success' | 'failed'
  return_status?: 'waiting_return' | 'shipping' | 'received' | 'exception' | null
  return_details?: { sku_id: number; product_title?: string | null; sku_specs?: Record<string, string> | null; quantity: number }[] | null
  return_tracking_no?: string | null
  return_express_company?: string | null
  admin_remark: string | null
  created_at: string
}

export interface ApplyRefundPayload {
  reason?: string
  type?: 'refund' | 'return_refund'
  return_details?: { sku_id: number | string | null; quantity: number; product_title?: string; sku_specs?: Record<string, string> }[]
  return_tracking_no?: string
  return_express_company?: string
  /** 凭证图片（先经 /user/upload 上传得到 URL） */
  images?: string[]
}

export interface OrderListResult {
  list: OrderBrief[]
  pagination: Pagination
}

export interface CreateOrderResult {
  /** P2-11：订单对外标识（public_id 字符串，非自增主键） */
  order_id: string
  order_no: string
  total_amount: string
  freight_amount: string
  pay_amount: string
  status: OrderStatus
  /** V1.1 T-035/T-039：用券下单返回的金额明细（核对前端预览口径） */
  discount_amount?: string
  promotion_discount?: string
  coupon_id?: number | null
  amount_details?: Record<string, unknown> | null
}

/** 运费实时预览结果（与后端 FreightResult 对齐，T-053 Stage 2） */
export interface FreightPreview {
  freight_amount: string
  free_shipping: boolean
  free_shipping_gap: string | null
  not_support: boolean
  detail: Array<{ template_id: number | null; mode: string; weight_g: number; amount: string; source: string }>
}

/**
 * 运费实时预览（结算页选地址后调用，与下单同一套引擎；region 模板需传 address_id 才能按省计算）。
 * not_support=true 表示该地区不可配送（不抛错，前端禁用提交并提示）。
 */
export function previewFreight(data: {
  items: Array<{ sku_id: string | number; quantity: number }>
  address_id?: number
}) {
  return request.post<ApiResult<FreightPreview>>('/orders/freight-preview', data)
}

/**
 * 运费预估（T-053 Stage 3，公开接口，游客可用）：详情页/购物车预估展示。
 * 与下单同一套引擎；游客不带 address_id 时 region 模板无法按省匹配，
 * not_support=true 仅表示「需按收货地址进一步确认」，前端展示引导文案即可。
 */
export function estimateFreight(data: {
  items: Array<{ sku_id: string | number; quantity: number }>
  address_id?: number
}) {
  return request.post<ApiResult<FreightPreview>>('/freight/estimate', data)
}

/** 创建订单（cart_item_ids 不传则结算全部有效项；V1.1 T-035/T-039 支持 user_coupon_id） */
export function createOrder(data: {
  address_id: number
  cart_item_ids?: number[]
  remark?: string
  user_coupon_id?: number
  promotion_id?: number
}) {
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

export function getOrder(id: string | number) {
  return request.get<ApiResult<OrderDetail>>(`/orders/${id}`)
}

export function cancelOrder(id: string | number, reason?: string) {
  return request.post<ApiResult<OrderDetail>>(`/orders/${id}/cancel`, { reason })
}

/** 确认收货（V1.1 E02-A / T-002） */
export function confirmOrder(id: string | number) {
  return request.post<
    ApiResult<{
      id: string
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
  skipped: Array<{ product_id: string | number | null; title: string; reason: string }>
  cart_count: number
}

export function rebuyOrder(id: string | number) {
  return request.post<ApiResult<RebuyResult>>(`/orders/${id}/rebuy`)
}

/** 申请退款（API 文档 6.5） */
export function applyRefund(id: string | number, payload: ApplyRefundPayload = {}) {
  return request.post<ApiResult<{
    refund_id: string
    refund_no: string
    type: string
    amount: string
    status: string
    return_status: string | null
  }>>(
    `/orders/${id}/refund`,
    payload,
  )
}

/** 订单物流信息（V1.1 T-046） */
export interface ShippingTraceEntry {
  context: string
  occurred_at: string
}

export interface ShippingInfo {
  express_company: string
  tracking_no: string
  trace_status: 'pending' | 'in_transit' | 'delivered' | 'failed'
  shipped_at: string | null
  delivered_at: string | null
  has_trace: boolean
  traces: ShippingTraceEntry[]
}

/** 订单物流信息（仅本人；未发货返回 null） */
export function getOrderShipping(id: string | number) {
  return request.get<ApiResult<ShippingInfo | null>>(`/orders/${id}/shipping`)
}
