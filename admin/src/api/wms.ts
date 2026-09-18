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
