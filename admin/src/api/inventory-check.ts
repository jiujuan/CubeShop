import request from './request'
import type { ApiResult } from './request'

// ---------- 类型 ----------

export interface Pagination {
  page: number
  page_size: number
  total: number
  total_pages: number
}

export type CheckStatus = 'draft' | 'counting' | 'posted' | 'cancelled'
export type CheckScopeType = 'all' | 'category' | 'brand' | 'keyword' | 'custom'
export type CheckItemStatus = 'pending' | 'counted' | 'posted' | 'skipped'

export interface InventoryCheckBrief {
  id: number
  check_no: string
  title: string | null
  scope_type: CheckScopeType
  scope_label: string
  scope_value: string | null
  status: CheckStatus
  status_label: string
  item_count: number
  counted_count: number
  diff_count: number
  total_diff_qty: number
  remark: string | null
  created_by_name: string | null
  posted_by_name: string | null
  posted_at: string | null
  created_at: string | null
}

export interface InventoryCheckRow {
  id: number
  sku_id: number
  sku_code: string
  product_title: string | null
  specs_text: string | null
  /** 开单时账面（快照，仅供展示） */
  system_qty: number
  locked_qty: number
  counted_qty: number | null
  /** 过账时实际调整量（实盘 - 过账时刻账面） */
  diff_qty: number | null
  status: CheckItemStatus
  status_label: string
  remark: string | null
}

export interface InventoryCheckDetail {
  check: InventoryCheckBrief
  items: { list: InventoryCheckRow[]; pagination: Pagination }
}

export interface PostResult {
  adjusted: number
  skipped: number
  unchanged: number
  total_diff_qty: number
  check: InventoryCheckBrief
}

export interface ImportFailedRow {
  row: number
  sku_code: string
  reason: string
}

// ---------- 列表 / 详情 ----------

export function getInventoryChecks(params: { check_no?: string; status?: CheckStatus; page?: number; page_size?: number }) {
  return request.get<ApiResult<{ list: InventoryCheckBrief[]; pagination: Pagination }>>('/admin/inventory-checks', { params })
}

export function getInventoryCheck(
  id: number,
  params: { item_status?: CheckItemStatus; only_diff?: 1; keyword?: string; page?: number; page_size?: number } = {},
) {
  return request.get<ApiResult<InventoryCheckDetail>>(`/admin/inventory-checks/${id}`, { params })
}

/** 建单：custom 模式需带 file（xlsx，第一列 SKU 编码） */
export function createInventoryCheck(payload: {
  title?: string
  scope_type: CheckScopeType
  scope_value?: string
  remark?: string
  file?: File
}) {
  if (!payload.file) {
    return request.post<ApiResult<InventoryCheckBrief>>('/admin/inventory-checks', payload)
  }
  const form = new FormData()
  form.append('title', payload.title ?? '')
  form.append('scope_type', payload.scope_type)
  form.append('scope_value', payload.scope_value ?? '')
  form.append('remark', payload.remark ?? '')
  form.append('file', payload.file)

  return request.post<ApiResult<InventoryCheckBrief>>('/admin/inventory-checks', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 60000,
  })
}

/** 录入实盘（单行或批量） */
export function recordInventoryCount(id: number, items: { item_id: number; counted_qty: number; remark?: string }[]) {
  return request.post<ApiResult<{ updated: number }>>(`/admin/inventory-checks/${id}/count`, { items })
}

/** 导入实盘（xlsx：SKU编码 / 实盘数量 / 备注） */
export function importInventoryCount(id: number, file: File) {
  const form = new FormData()
  form.append('file', file)
  return request.post<ApiResult<{ updated: number }>>(`/admin/inventory-checks/${id}/import`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 60000,
  })
}

/** 导出明细 CSV */
export async function exportInventoryCheck(id: number) {
  const response = await request.get(`/admin/inventory-checks/${id}/export`, {
    responseType: 'blob',
    timeout: 60000,
  })

  const url = URL.createObjectURL(response.data as Blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `盘点明细-${id}.csv`
  link.click()
  URL.revokeObjectURL(url)
}

/** 过账（权限 inventory.manage，会按差异改写库存） */
export function postInventoryCheck(id: number) {
  return request.post<ApiResult<PostResult>>(`/admin/inventory-checks/${id}/post`)
}

/** 作废 */
export function cancelInventoryCheck(id: number) {
  return request.post<ApiResult<null>>(`/admin/inventory-checks/${id}/cancel`)
}

// ---------- 字典 ----------

export const CHECK_STATUS_LABELS: Record<string, string> = {
  draft: '待盘点',
  counting: '盘点中',
  posted: '已过账',
  cancelled: '已作废',
}

export const CHECK_SCOPE_LABELS: Record<string, string> = {
  all: '全部商品',
  category: '按分类',
  brand: '按品牌',
  keyword: '按关键词',
  custom: '自定义清单',
}

export const CHECK_ITEM_STATUS_LABELS: Record<string, string> = {
  pending: '待盘点',
  counted: '已盘点',
  posted: '已过账',
  skipped: '已跳过',
}
