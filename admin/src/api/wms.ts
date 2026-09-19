import request from './request'
import type { ApiResult } from './request'
import type { Pagination } from './announcement'

// ================= WMS 对接配置（WMS 计划 P0，权限 wms.config.manage） =================

export type WmsProvider = 'cainiao' | 'jd_cloud'
export type WmsApiEnv = 'prod' | 'sandbox'
export type WmsMappingMode = 'same' | 'manual'

export const WMS_PROVIDER_LABELS: Record<WmsProvider, string> = {
  cainiao: '菜鸟（奇门）',
  jd_cloud: '京东云仓',
}

export const WMS_API_ENV_LABELS: Record<WmsApiEnv, string> = {
  prod: '生产环境',
  sandbox: '沙箱环境',
}

export const WMS_MAPPING_MODE_LABELS: Record<WmsMappingMode, string> = {
  same: '跟随平台 SKU 编码',
  manual: '手工映射',
}

export interface WarehouseWmsSummary {
  provider: WmsProvider
  provider_label: string
  enabled: boolean
  api_env: WmsApiEnv
  sku_mapping_mode: WmsMappingMode
}

export interface WarehouseRow {
  id: number
  code: string
  name: string
  contact_name: string | null
  contact_phone: string | null
  province: string | null
  city: string | null
  district: string | null
  address: string | null
  status: number
  created_at: string | null
  wms: WarehouseWmsSummary | null
}

export interface WarehousePayload {
  code: string
  name: string
  contact_name?: string | null
  contact_phone?: string | null
  province?: string | null
  city?: string | null
  district?: string | null
  address?: string | null
  status: number
}

export interface WmsConfigData {
  configured: boolean
  id: number | null
  warehouse_id: number
  provider: WmsProvider
  provider_label: string
  enabled: boolean
  auto_push: boolean
  auto_push_return: boolean
  push_retry_times: number
  sku_mapping_mode: WmsMappingMode
  app_key: string | null
  /** 只读掩码：明文永不返回（SEC-01） */
  app_secret_masked: string | null
  has_app_secret: boolean
  access_token_masked: string | null
  has_access_token: boolean
  customer_id: string | null
  owner_no: string | null
  warehouse_code: string | null
  warehouse_no: string | null
  api_env: WmsApiEnv
  extra_config: Record<string, unknown> | null
  remark: string | null
  /** 只读回调地址（含仓库 token），供复制到菜鸟后台 */
  callback_url: string | null
  updated_at: string | null
}

/** 保存载荷：`app_secret` / `access_token` 留空表示「不修改原值」 */
export interface WmsConfigPayload {
  provider: WmsProvider
  enabled: boolean
  auto_push: boolean
  auto_push_return: boolean
  push_retry_times: number
  sku_mapping_mode: WmsMappingMode
  app_key?: string | null
  app_secret?: string
  access_token?: string
  customer_id?: string | null
  owner_no?: string | null
  warehouse_code?: string | null
  warehouse_no?: string | null
  api_env: WmsApiEnv
  remark?: string | null
}

export interface WmsTestResult {
  success: boolean
  mock: boolean | null
  provider: string
  duration_ms: number
  message: string
  error: string | null
  request_id: string | null
}

export interface WmsSkuMappingRow {
  id: number
  warehouse_id: number
  sku_id: string | null
  platform_sku_code: string
  wms_sku_code: string
  barcode: string | null
  status: number
  product_title: string | null
  sku_specs: Record<string, string> | null
  updated_at: string | null
}

export interface WmsImportRow {
  sku_code: string
  wms_sku_code: string
  barcode?: string
}

export interface WmsImportResult {
  total: number
  success_count: number
  failed_count: number
  results: { line: number; sku_code: string; success: boolean; message: string }[]
}

// ---------- 仓库档案 ----------

export function getWmsWarehouses(params: { keyword?: string; status?: number; page?: number; page_size?: number } = {}) {
  return request.get<ApiResult<{ list: WarehouseRow[]; pagination: Pagination }>>('/admin/wms/warehouses', { params })
}

export function createWmsWarehouse(data: WarehousePayload) {
  return request.post<ApiResult<WarehouseRow>>('/admin/wms/warehouses', data)
}

export function updateWmsWarehouse(id: number, data: WarehousePayload) {
  return request.put<ApiResult<WarehouseRow>>(`/admin/wms/warehouses/${id}`, data)
}

// ---------- WMS 配置 ----------

export function getWmsConfig(warehouseId: number) {
  return request.get<ApiResult<WmsConfigData>>(`/admin/wms/warehouses/${warehouseId}/config`)
}

export function saveWmsConfig(warehouseId: number, data: WmsConfigPayload) {
  return request.put<ApiResult<WmsConfigData>>(`/admin/wms/warehouses/${warehouseId}/config`, data)
}

export function testWmsConnection(warehouseId: number) {
  return request.post<ApiResult<WmsTestResult>>(`/admin/wms/warehouses/${warehouseId}/config/test`)
}

// ---------- SKU 映射 ----------

export function getWmsSkuMappings(
  warehouseId: number,
  params: { keyword?: string; page?: number; page_size?: number } = {},
) {
  return request.get<ApiResult<{ list: WmsSkuMappingRow[]; pagination: Pagination }>>(
    `/admin/wms/warehouses/${warehouseId}/sku-mappings`,
    { params },
  )
}

export function importWmsSkuMappings(warehouseId: number, rows: WmsImportRow[]) {
  return request.post<ApiResult<WmsImportResult>>(`/admin/wms/warehouses/${warehouseId}/sku-mappings/batch`, { rows })
}

export function deleteWmsSkuMapping(warehouseId: number, skuId: string) {
  return request.delete<ApiResult<null>>(`/admin/wms/warehouses/${warehouseId}/sku-mappings/${skuId}`)
}


// ============ 履约中心（WMS 计划 P6，权限 wms.order.view / wms.order.manage） ============

export type FulfillmentStatus =
  | 'created' | 'pending_push' | 'pushing' | 'pushed' | 'picking'
  | 'packed' | 'shipped' | 'completed' | 'cancelled' | 'exception' | 'push_failed'

export type ReturnInboundStatus =
  | 'created' | 'pending_push' | 'pushing' | 'pushed' | 'receiving'
  | 'received' | 'completed' | 'cancelled' | 'exception' | 'push_failed'

export type InventoryType = 'ZP' | 'CC'

/** 发货单状态中文（后端 FulfillmentOrder::STATUS_LABELS 镜像） */
export const FULFILLMENT_STATUS_LABELS: Record<FulfillmentStatus, string> = {
  created: '已创建',
  pending_push: '待推送',
  pushing: '推送中',
  pushed: '已推送',
  picking: '拣货中',
  packed: '已打包',
  shipped: '已发货',
  completed: '已完成',
  cancelled: '已取消',
  exception: '异常',
  push_failed: '推送失败',
}

export const FULFILLMENT_STATUS_CLASS: Record<FulfillmentStatus, string> = {
  created: 'bg-slate-100 text-slate-600',
  pending_push: 'bg-blue-50 text-blue-600',
  pushing: 'bg-blue-50 text-blue-600',
  pushed: 'bg-cyan-50 text-cyan-600',
  picking: 'bg-indigo-50 text-indigo-600',
  packed: 'bg-violet-50 text-violet-600',
  shipped: 'bg-emerald-50 text-emerald-600',
  completed: 'bg-emerald-100 text-emerald-700',
  cancelled: 'bg-slate-100 text-slate-500',
  exception: 'bg-amber-50 text-amber-600',
  push_failed: 'bg-red-50 text-red-600',
}

/** 退货入库单状态中文（后端 ReturnInboundOrder::STATUS_LABELS 镜像） */
export const RETURN_INBOUND_STATUS_LABELS: Record<ReturnInboundStatus, string> = {
  created: '已创建',
  pending_push: '待推送',
  pushing: '推送中',
  pushed: '已推送',
  receiving: '收货中',
  received: '已收货',
  completed: '已完成',
  cancelled: '已取消',
  exception: '异常',
  push_failed: '推送失败',
}

export const RETURN_INBOUND_STATUS_CLASS: Record<ReturnInboundStatus, string> = {
  created: 'bg-slate-100 text-slate-600',
  pending_push: 'bg-blue-50 text-blue-600',
  pushing: 'bg-blue-50 text-blue-600',
  pushed: 'bg-cyan-50 text-cyan-600',
  receiving: 'bg-indigo-50 text-indigo-600',
  received: 'bg-violet-50 text-violet-600',
  completed: 'bg-emerald-100 text-emerald-700',
  cancelled: 'bg-slate-100 text-slate-500',
  exception: 'bg-amber-50 text-amber-600',
  push_failed: 'bg-red-50 text-red-600',
}

/** 残次/正品（奇门 inventoryType） */
export const INVENTORY_TYPE_LABELS: Record<InventoryType, string> = {
  ZP: '正品',
  CC: '残次',
}

/** 单据详情里的调用流水摘要（完整报文去日志页按 request_id 查） */
export interface WmsLogBrief {
  id: number
  direction: 'outbound' | 'inbound'
  direction_label: string
  api_name: string
  request_id: string | null
  success: boolean
  error_msg: string | null
  http_status: number | null
  created_at: string | null
}

export interface FulfillmentOrderItem {
  id: number
  sku_id: number | null
  platform_sku_code: string
  wms_sku_code: string
  product_name: string
  qty: number
  shipped_qty: number
  barcode: string | null
}

export interface FulfillmentOrderRow {
  id: number
  order_id: number
  order_no: string
  outbound_no: string
  warehouse_id: number
  warehouse_name: string | null
  provider: string
  status: FulfillmentStatus
  status_label: string
  wms_outbound_no: string | null
  tracking_no: string | null
  carrier_code: string | null
  carrier_name: string | null
  push_request_id: string | null
  push_times: number
  last_push_at: string | null
  last_push_error: string | null
  shipped_at: string | null
  cancelled_at: string | null
  exception_reason: string | null
  can_push: boolean
  can_cancel: boolean
  created_at: string | null
  updated_at: string | null
  /** 仅详情返回 */
  items?: FulfillmentOrderItem[]
  logs?: WmsLogBrief[]
  buyer_info?: Record<string, unknown> | null
  shipping_info?: Record<string, unknown> | null
}

export interface ReturnInboundOrderItem {
  id: number
  sku_id: number | null
  platform_sku_code: string
  wms_sku_code: string
  product_name: string
  qty: number
  received_qty: number
  inventory_type: InventoryType | null
  barcode: string | null
}

export interface ReturnInboundOrderRow {
  id: number
  refund_id: number
  refund_no: string
  order_id: number
  order_no: string
  inbound_no: string
  warehouse_id: number
  warehouse_name: string | null
  provider: string
  status: ReturnInboundStatus
  status_label: string
  wms_inbound_no: string | null
  push_request_id: string | null
  push_times: number
  last_push_at: string | null
  last_push_error: string | null
  received_at: string | null
  cancelled_at: string | null
  exception_reason: string | null
  return_reason: string | null
  can_push: boolean
  can_cancel: boolean
  can_manual_received: boolean
  created_at: string | null
  updated_at: string | null
  /** 仅详情返回 */
  items?: ReturnInboundOrderItem[]
  logs?: WmsLogBrief[]
}

export interface FulfillmentOrderListParams {
  status?: FulfillmentStatus
  warehouse_id?: number
  outbound_no?: string
  order_no?: string
  page?: number
  page_size?: number
}

export interface ReturnInboundOrderListParams {
  status?: ReturnInboundStatus
  warehouse_id?: number
  inbound_no?: string
  refund_no?: string
  order_no?: string
  page?: number
  page_size?: number
}

export interface BatchPushResult {
  total: number
  succeeded: number
  results: { id: number; success: boolean; message: string }[]
}

/** 仓库下拉（只读运营 / 退货管理员也要能筛仓库，故单独放行） */
export function getWmsWarehouseOptions() {
  return request.get<ApiResult<{ id: number; name: string }[]>>('/admin/wms/warehouse-options')
}

// ---------- 发货单 ----------

export function getFulfillmentOrders(params: FulfillmentOrderListParams = {}) {
  return request.get<ApiResult<{ list: FulfillmentOrderRow[]; pagination: Pagination }>>(
    '/admin/wms/fulfillment-orders',
    { params },
  )
}

export function getFulfillmentOrder(id: number) {
  return request.get<ApiResult<FulfillmentOrderRow>>(`/admin/wms/fulfillment-orders/${id}`)
}

export function pushFulfillmentOrder(id: number) {
  return request.post<ApiResult<FulfillmentOrderRow>>(`/admin/wms/fulfillment-orders/${id}/push`)
}

export function cancelFulfillmentOrder(id: number, reason: string) {
  return request.post<ApiResult<FulfillmentOrderRow>>(`/admin/wms/fulfillment-orders/${id}/cancel`, { reason })
}

export function batchPushFulfillmentOrders(ids: number[]) {
  return request.post<ApiResult<BatchPushResult>>('/admin/wms/fulfillment-orders/batch-push', { ids })
}

// ---------- 退货入库单 ----------

export function getReturnInboundOrders(params: ReturnInboundOrderListParams = {}) {
  return request.get<ApiResult<{ list: ReturnInboundOrderRow[]; pagination: Pagination }>>(
    '/admin/wms/return-inbound-orders',
    { params },
  )
}

export function getReturnInboundOrder(id: number) {
  return request.get<ApiResult<ReturnInboundOrderRow>>(`/admin/wms/return-inbound-orders/${id}`)
}

export function pushReturnInboundOrder(id: number) {
  return request.post<ApiResult<ReturnInboundOrderRow>>(`/admin/wms/return-inbound-orders/${id}/push`)
}

export function cancelReturnInboundOrder(id: number, reason: string) {
  return request.post<ApiResult<ReturnInboundOrderRow>>(`/admin/wms/return-inbound-orders/${id}/cancel`, { reason })
}

/** 手工标记收货：缺省按「全部应退行足额正品实收」处理 */
export function manualReceiveReturnInbound(
  id: number,
  payload: {
    received_details?: {
      sku_id?: number
      platform_sku_code?: string
      quantity: number
      inventory_type?: InventoryType
    }[]
    exception_reason?: string
  } = {},
) {
  return request.post<ApiResult<ReturnInboundOrderRow>>(
    `/admin/wms/return-inbound-orders/${id}/manual-received`,
    payload,
  )
}

// ============ WMS 调用日志（P6 / F5，权限 wms.config.manage） ============

export interface WmsApiLogRow {
  id: number
  direction: 'outbound' | 'inbound'
  direction_label: string
  provider: string
  api_name: string
  request_id: string | null
  biz_no: string | null
  http_status: number | null
  success: boolean
  error_msg: string | null
  created_at: string | null
}

export interface WmsApiLogDetail extends WmsApiLogRow {
  request_body: unknown
  response_body: unknown
}

export interface WmsApiLogListParams {
  direction?: 'outbound' | 'inbound'
  api_name?: string
  success?: boolean
  request_id?: string
  biz_no?: string
  keyword?: string
  created_from?: string
  created_to?: string
  page?: number
  page_size?: number
}

export function getWmsLogs(params: WmsApiLogListParams = {}) {
  return request.get<ApiResult<{ list: WmsApiLogRow[]; pagination: Pagination }>>('/admin/wms/logs', { params })
}

export function getWmsLog(id: number) {
  return request.get<ApiResult<WmsApiLogDetail>>(`/admin/wms/logs/${id}`)
}

// ============ 库存快照 / 差异 / 健康（P6 / F6、F7，权限 wms.config.manage） ============

export type InventoryDiffStatus = 'pending' | 'resolved' | 'ignored'

export const INVENTORY_DIFF_STATUS_LABELS: Record<InventoryDiffStatus, string> = {
  pending: '待处理',
  resolved: '已校准',
  ignored: '已忽略',
}

export const INVENTORY_DIFF_STATUS_CLASS: Record<InventoryDiffStatus, string> = {
  pending: 'bg-amber-50 text-amber-600',
  resolved: 'bg-emerald-50 text-emerald-600',
  ignored: 'bg-slate-100 text-slate-500',
}

export interface WmsInventorySnapshotRow {
  id: number
  warehouse_id: number
  warehouse_name: string | null
  sku_id: number | null
  sku_code: string | null
  wms_sku_code: string
  available_qty: number
  locked_qty: number
  synced_at: string | null
}

export interface WmsInventoryDiffRow {
  id: number
  warehouse_id: number
  warehouse_name: string | null
  sku_id: number | null
  sku_code: string | null
  wms_sku_code: string
  platform_qty: number
  wms_qty: number
  diff: number
  status: InventoryDiffStatus
  status_label: string
  remark: string | null
  handled_at: string | null
  created_at: string | null
}

export interface WmsHealthCheck {
  key: string
  status: 'ok' | 'warning' | 'error' | 'unknown'
  title: string
  detail: string
  value: Record<string, number | string>
}

export interface WmsHealthReport {
  checked_at: string
  healthy: boolean
  summary: {
    pushing_timeout: number
    push_failed: number
    exception: number
    fail_rate: number
    queue_backlog: number
  }
  checks: WmsHealthCheck[]
}

export const WMS_HEALTH_STATUS_LABELS: Record<WmsHealthCheck['status'], string> = {
  ok: '正常',
  warning: '告警',
  error: '异常',
  unknown: '未知',
}

export const WMS_HEALTH_STATUS_CLASS: Record<WmsHealthCheck['status'], string> = {
  ok: 'bg-emerald-50 text-emerald-600',
  warning: 'bg-amber-50 text-amber-600',
  error: 'bg-red-50 text-red-600',
  unknown: 'bg-slate-100 text-slate-500',
}

export function getWmsInventorySnapshots(
  params: { warehouse_id?: number; keyword?: string; page?: number; page_size?: number } = {},
) {
  return request.get<ApiResult<{ list: WmsInventorySnapshotRow[]; pagination: Pagination }>>(
    '/admin/wms/inventory/snapshots',
    { params },
  )
}

export function getWmsInventoryDiffs(
  params: {
    warehouse_id?: number
    status?: InventoryDiffStatus
    keyword?: string
    page?: number
    page_size?: number
  } = {},
) {
  return request.get<ApiResult<{ list: WmsInventoryDiffRow[]; pagination: Pagination }>>(
    '/admin/wms/inventory/diffs',
    { params },
  )
}

/** action=resolve（默认）按 WMS 校准平台库存；ignore 只关单 */
export function resolveWmsInventoryDiff(
  id: number,
  payload: { action?: 'resolve' | 'ignore'; apply?: boolean; remark?: string } = {},
) {
  return request.post<ApiResult<{ id: number; status: InventoryDiffStatus; status_label: string }>>(
    `/admin/wms/inventory/diffs/${id}/resolve`,
    payload,
  )
}

export function syncWmsInventory(payload: { warehouse_id?: number; apply?: boolean } = {}) {
  return request.post<ApiResult<{ summary: { warehouse_id: number; synced: number }[]; errors: string[] }>>(
    '/admin/wms/inventory/sync',
    payload,
  )
}

export function getWmsHealth() {
  return request.get<ApiResult<WmsHealthReport>>('/admin/wms/health')
}
