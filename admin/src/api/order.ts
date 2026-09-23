import request from './request'
import type { ApiResult } from './request'

// ---------- 订单管理（API 文档 8.3 / Roadmap P6，权限 order.view / order.ship / order.export） ----------

export type OrderStatus =
  | 'pending_payment'
  | 'paid'
  | 'pending_ship'
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
  /** 该行分摊到的优惠券金额（T-035，仅详情接口返回） */
  coupon_share?: string | null
  /** 该行分摊到的满减金额（仅详情接口返回） */
  promotion_share?: string | null
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

/** 金额明细（orders.amount_details，T-035 落库；V1.0 老单为 null，展示时回退订单级字段） */
export interface OrderAmountDetails {
  v: number
  goods_amount: string
  freight_amount: string
  promotion_discount: string
  coupon_discount: string
  discount_amount: string
  pay_amount: string
  promotion_id: number | null
  coupon_id: number | null
  user_coupon_id: number | null
  /** 行分摊，index 与 order_items 一一对应 */
  lines: Array<{
    index: number
    product_id: number
    sku_id: number
    amount: string
    promotion_share: string
    coupon_share: string
    payable: string
  }>
}

/** 订单使用的优惠券（券模板快照） */
export interface OrderCoupon {
  id: number
  name: string
  type: string
  amount: string | null
  percent: number | null
  min_spend: string | null
}

/** 物流轨迹节点 */
export interface ShippingTraceRow {
  context: string
  occurred_at: string | null
}

/** 订单物流（traces 按发生时间倒序，最新在前） */
export interface OrderShipping {
  id: number
  company_code: string
  company_name: string
  tracking_no: string
  trace_status: string
  shipped_at: string | null
  delivered_at: string | null
  pull_fail_count: number
  last_fail_message: string | null
  traces: ShippingTraceRow[]
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
  /** 物流冗余双号（T-043）：快递公司名称与运单号，未发货时为空 */
  express_company?: string | null
  tracking_no?: string | null
  created_at: string
  remark?: string | null
  address_snapshot?: AddressSnapshot | null
  cancel_reason?: string | null
  paid_at?: string | null
  shipped_at?: string | null
  completed_at?: string | null
  cancelled_at?: string | null
  /** 以下为详情接口专有（列表接口不返回） */
  auto_completed?: boolean
  trace_status?: string | null
  /** 整单优惠合计（amount_details 缺失时的回退口径） */
  discount_amount?: string | null
  promotion_discount?: string | null
  amount_details?: OrderAmountDetails | null
  coupon?: OrderCoupon | null
  shipping?: OrderShipping[]
  /** WMS 履约只读摘要（WMS P3 / Step 7）：订单无发货单时为 null（前端据此隐藏） */
  wms_fulfillment?: WmsFulfillmentBrief | null
}

/** WMS 履约只读摘要（GET /admin/orders/{id} 的 wms_fulfillment 字段） */
export interface WmsFulfillmentBrief {
  id: number
  outbound_no: string
  status: string
  status_label: string
  warehouse_id: number
  push_times: number
  last_push_error: string | null
  wms_outbound_no: string | null
  carrier_code: string | null
  tracking_no: string | null
}

/** WMS 履约状态中文标签（与后端 STATUS_LABELS 对齐；本页只读展示用） */
export const WMS_FULFILLMENT_STATUS_CLASS: Record<string, string> = {
  created: 'bg-slate-100 text-slate-500',
  pending_push: 'bg-amber-50 text-amber-600',
  pushing: 'bg-blue-50 text-blue-600',
  pushed: 'bg-blue-50 text-blue-600',
  picking: 'bg-blue-50 text-blue-600',
  packed: 'bg-blue-50 text-blue-600',
  shipped: 'bg-green-50 text-green-600',
  completed: 'bg-green-50 text-green-600',
  cancelled: 'bg-slate-100 text-slate-400',
  exception: 'bg-red-50 text-red-600',
  push_failed: 'bg-red-50 text-red-600',
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

// ---------- 订单资金视图（G7：统一资金流水聚合，权限 order.view，只读） ----------

/** 资金视图中的支付单行（GET /admin/orders/{id}/funds） */
export interface FundsPaymentRow {
  id: number
  payment_no: string
  channel: string
  channel_label: string
  amount: string
  status: string
  status_label: string
  channel_trade_no: string | null
  paid_at: string | null
  created_at: string | null
}

/** 支付单事件流水（创建/回调/核账/查单/关闭…，事件原文在支付日志页查看） */
export interface FundsPaymentEvent {
  payment_no: string
  event: string
  event_label: string
  created_at: string | null
}

/** 余额流水（经 related_type=payment 关联到本单支付单） */
export interface FundsBalanceLog {
  type: string
  type_label: string
  amount: string
  balance_before: string
  balance_after: string
  remark: string | null
  created_at: string | null
}

/** 退款单行（含优惠构成快照 refund_details） */
export interface FundsRefundRow {
  refund_no: string
  type: string
  amount: string
  status: string
  status_label: string
  refund_details: Record<string, unknown> | null
  reason: string | null
  created_at: string | null
  processed_at: string | null
}

/** 资金汇总（渠道成功收款/退款、余额渠道真实进出、净入账） */
export interface FundsSummary {
  pay_success_amount: string
  refund_success_amount: string
  balance_consume_amount: string
  balance_refund_amount: string
  net_amount: string
}

/** 订单资金视图响应（订单金额块复用 AdminOrder 的口径） */
export interface OrderFunds {
  order: {
    id: number
    order_no: string
    status: OrderStatus
    status_label: string
    user_id: number
    total_amount: string
    freight_amount: string
    discount_amount: string
    promotion_discount: string
    pay_amount: string
    amount_details?: OrderAmountDetails | null
    created_at: string | null
    paid_at: string | null
  }
  payments: FundsPaymentRow[]
  payment_events: FundsPaymentEvent[]
  balance_logs: FundsBalanceLog[]
  refunds: FundsRefundRow[]
  summary: FundsSummary
}

/** 订单资金视图（G7） */
export function getOrderFunds(id: number) {
  return request.get<ApiResult<OrderFunds>>(`/admin/orders/${id}/funds`)
}

export interface ShipPayload {
  /** 快递公司编码（express_companies.code，发货弹窗下拉选择） */
  express_company_code: string
  /** 快递单号（8~32 位，字母数字） */
  tracking_no: string
  /** 备注（可选） */
  remark?: string
}

export function shipOrder(id: number, payload: ShipPayload) {
  return request.post<ApiResult<AdminOrder>>(`/admin/orders/${id}/ship`, payload)
}

/**
 * 受理备货（paid → pending_ship）
 *
 * 正常路径由系统在支付成功后自动流转到「待发货」；本操作是异常滞留订单
 * （自动流转失败、退款被驳回回流到「已支付」）的人工兜底。
 */
export function acceptOrder(id: number, remark?: string) {
  return request.post<ApiResult<AdminOrder>>(`/admin/orders/${id}/accept`, { remark })
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

/**
 * 订单状态标签（与后端 `Order::STATUS_LABELS` 一一对应）
 *
 * 键顺序即后台订单管理页的 Tab / 筛选下拉顺序（履约主链路）：
 * 待支付 → 已支付 → 待发货 → 已发货 → 已完成 → 已取消 → 退款中 → 已退款
 */
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

export const ORDER_STATUS_CLASS: Record<OrderStatus, string> = {
  pending_payment: 'bg-orange-100 text-orange-500',
  paid: 'bg-blue-100 text-blue-500',
  pending_ship: 'bg-amber-100 text-amber-600',
  shipped: 'bg-cyan-100 text-cyan-600',
  completed: 'bg-green-100 text-green-600',
  cancelled: 'bg-slate-100 text-slate-500',
  refunding: 'bg-purple-100 text-purple-500',
  refunded: 'bg-red-100 text-red-500',
}

/** 订单主流程进度（详情页进度条）；取消 / 退款为终态分支，不进主流程 */
export const ORDER_PROGRESS_FLOW: Array<{ status: OrderStatus; label: string }> = [
  { status: 'pending_payment', label: '提交订单' },
  { status: 'paid', label: '支付成功' },
  { status: 'pending_ship', label: '待发货' },
  { status: 'shipped', label: '已发货' },
  { status: 'completed', label: '已完成' },
]

// ---------- 物流管理（V1.1 T-044/T-045/T-047，权限 order.ship / order.view / shipping.manage） ----------

/** 启用快递公司（发货弹窗下拉用；GET /admin/shipping-companies/enabled，权限 order.ship） */
export interface EnabledShippingCompany {
  code: string
  name: string
}

export function getEnabledShippingCompanies() {
  return request.get<ApiResult<EnabledShippingCompany[]>>('/admin/shipping-companies/enabled')
}

/**
 * 快递公司字典行（管理页；GET /admin/shipping-companies，权限 shipping.manage）
 *
 * `carrier_codes` 为多渠道承运商编码映射 { kuaidi100: 'shunfeng', cainiao: 'SF', jd_cloud: 'JD' }。
 * `channel_code` 是快递100 的历史兼容列，新数据以 carrier_codes.kuaidi100 为准。
 */
export interface ShippingCompany {
  id: number
  code: string
  name: string
  channel_code: string | null
  carrier_codes?: Record<string, string> | null
  sort: number
  status: number
  created_at?: string
  updated_at?: string
}

export interface ShippingCompanyPayload {
  code?: string
  name?: string
  channel_code?: string | null
  carrier_codes?: Record<string, string> | null
  sort?: number
  status?: number
}

export interface ShippingCompanyQuery {
  status?: number
  keyword?: string
  page?: number
  page_size?: number
}

export type PagedResult<T> = { list: T[]; pagination: { page: number; page_size: number; total: number; total_pages: number } }

export function getShippingCompanies(params: ShippingCompanyQuery) {
  return request.get<ApiResult<PagedResult<ShippingCompany>>>('/admin/shipping-companies', { params })
}

export function createShippingCompany(payload: ShippingCompanyPayload) {
  return request.post<ApiResult<ShippingCompany>>('/admin/shipping-companies', payload)
}

export function updateShippingCompany(id: number, payload: ShippingCompanyPayload) {
  return request.put<ApiResult<ShippingCompany>>(`/admin/shipping-companies/${id}`, payload)
}

export function deleteShippingCompany(id: number) {
  return request.delete<ApiResult<null>>(`/admin/shipping-companies/${id}`)
}

/** 物流看板行（GET /admin/shippings，权限 order.view） */
export interface ShippingRow {
  id: number
  order_id: number
  order_no: string | null
  company_code: string
  company_name: string
  tracking_no: string
  /** pending / in_transit / delivered / failed */
  trace_status: string
  trace_count: number
  shipped_at: string | null
  delivered_at: string | null
  pull_fail_count: number
  last_fail_message: string | null
  /** 异常：拉取失败 / 发货超 48h 无轨迹 / 轨迹停滞超 72h */
  abnormal: boolean
}

export interface ShippingQuery {
  trace_status?: string
  keyword?: string
  page?: number
  page_size?: number
}

export function getShippings(params: ShippingQuery) {
  return request.get<ApiResult<PagedResult<ShippingRow>>>('/admin/shippings', { params })
}

/** 手动重试轨迹拉取（POST /admin/shippings/{id}/pull，权限 order.ship） */
export interface PullResult {
  result: 'pulled' | 'failed' | 'skipped'
  trace_status: string
  pull_fail_count: number
  trace_count: number
}

export function pullShipping(id: number) {
  return request.post<ApiResult<PullResult>>(`/admin/shippings/${id}/pull`)
}

/**
 * 当前物流查询渠道（GET /admin/shippings/channel，权限 order.view）
 *
 * ⚠️ 只回显「密钥是否已配置」，接口不返回 key/customer 明文。
 */
export interface ShippingChannelInfo {
  /** 后台配置值：'' = 跟随 .env */
  configured: string
  /** 实际生效渠道；null = 未启用（关闭或未配置） */
  channel: string | null
  label: string
  /** database = 后台已覆盖；env = 跟随环境配置 */
  source: 'database' | 'env'
  /** 密钥齐备且渠道可用，能真正发起查询 */
  available: boolean
  key_configured: boolean
  customer_configured: boolean
  options: Array<{ value: string; label: string }>
}

export function getShippingChannel() {
  return request.get<ApiResult<ShippingChannelInfo>>('/admin/shippings/channel')
}

/** 切换渠道（PUT /admin/shippings/channel，权限 shipping.manage）；密钥仍走 .env */
export function updateShippingChannel(channel: string) {
  return request.put<ApiResult<{ configured: string; channel: string | null }>>('/admin/shippings/channel', { channel })
}

/** 获取电子面单申请渠道（GET /admin/shippings/waybill-channel，权限 order.view） */
export function getWaybillChannel() {
  return request.get<ApiResult<ShippingChannelInfo>>('/admin/shippings/waybill-channel')
}

/** 补出 / 重打电子面单（POST /admin/shippings/{id}/waybill/reissue，权限 shipping.manage） */
export function reissueWaybill(id: number) {
  return request.post<ApiResult<{ tracking_no: string; channel: string }>>(
    `/admin/shippings/${id}/waybill/reissue`,
  )
}

/** 切换电子面单申请渠道（PUT /admin/shippings/waybill-channel，权限 shipping.manage）；密钥仍走 .env */
export function updateWaybillChannel(channel: string) {
  return request.put<ApiResult<{ configured: string; channel: string | null }>>('/admin/shippings/waybill-channel', { channel })
}

/** 运单轨迹详情（GET /admin/shippings/{id}，权限 order.view）—— 与用户端订单物流同口径 */
export interface ShippingDetail {
  id: number
  order_id: number
  order_no: string | null
  company_code: string
  company_name: string
  tracking_no: string
  phone: string | null
  trace_status: string
  shipped_at: string | null
  delivered_at: string | null
  pull_fail_count: number
  last_fail_message: string | null
  has_trace: boolean
  traces: Array<{ context: string; occurred_at: string }>
}

export function getShippingDetail(id: number) {
  return request.get<ApiResult<ShippingDetail>>(`/admin/shippings/${id}`)
}

/** 智能识别提示行（V1.1 三期）：识别结果与填写的公司编码不一致，已按填写执行，仅供参考 */
export interface BatchShipWarning {
  row: number
  order_no: string
  tracking_no: string
  filled: string
  detected: string
  detected_name: string
}

/** 批量发货结果（预校验失败时 failed 非空，success=0） */
export interface BatchShipResult {
  success: number
  total: number
  /** 字段名与后端保持一致：reason（非 message） */
  failed: Array<{ row: number; order_no: string; reason: string }>
  /** 仅成功返回时存在；空数组表示全部一致 */
  warnings?: BatchShipWarning[]
}

/** 运单号智能识别快递公司（V1.1 三期） */
export interface DetectCompanyResult {
  tracking_no: string
  candidates: Array<{ code: string; name: string }>
  /** 相似度最高的候选内部编码；无法识别为 null */
  guess: string | null
}

export function detectShippingCompany(trackingNo: string) {
  return request.post<ApiResult<DetectCompanyResult>>('/admin/orders/detect-company', { tracking_no: trackingNo })
}

export function batchShipImport(file: File) {
  const form = new FormData()
  form.append('file', file)
  return request.post<ApiResult<BatchShipResult>>('/admin/orders/batch-ship', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 60000,
  })
}

/** 批量发货模板下载（xlsx） */
export async function downloadBatchShipTemplate() {
  const response = await request.get('/admin/orders/batch-ship/template', {
    responseType: 'blob',
    timeout: 60000,
  })

  let name = '批量发货模板.xlsx'
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

export const TRACE_STATUS_LABELS: Record<string, string> = {
  pending: '待查询',
  in_transit: '运输中',
  delivered: '已签收',
  failed: '查询失败',
}

export const TRACE_STATUS_CLASS: Record<string, string> = {
  pending: 'bg-slate-100 text-slate-500',
  in_transit: 'bg-cyan-100 text-cyan-600',
  delivered: 'bg-green-100 text-green-600',
  failed: 'bg-red-100 text-red-500',
}
