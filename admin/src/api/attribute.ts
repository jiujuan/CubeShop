import request from './request'
import type { ApiResult } from './request'

/**
 * V1.1 E01（T-008 / T-009 / T-010 / T-011）
 * 品牌库、属性库、分类属性模板、SKU 矩阵生成接口
 */

export interface Pagination {
  page: number
  page_size: number
  total: number
  total_pages: number
}

// ---------- 品牌 ----------

export interface BrandRow {
  id: number
  name: string
  logo: string | null
  sort: number
  status: number
  product_count: number
  created_at?: string
}

export function getBrands(params: { keyword?: string; status?: number | ''; page?: number; page_size?: number } = {}) {
  return request.get<ApiResult<{ list: BrandRow[]; pagination: Pagination }>>('/admin/brands', { params })
}

export function createBrand(data: { name: string; logo?: string | null; sort?: number; status?: number }) {
  return request.post<ApiResult<{ id: number }>>('/admin/brands', data)
}

export function updateBrand(id: number, data: Partial<{ name: string; logo: string | null; sort: number; status: number }>) {
  return request.put<ApiResult<null>>(`/admin/brands/${id}`, data)
}

export function deleteBrand(id: number) {
  return request.delete<ApiResult<null>>(`/admin/brands/${id}`)
}

// ---------- 属性库 ----------

export interface AttributeValueRow {
  id: number
  value: string
  sort: number
}

export interface AttributeRow {
  id: number
  name: string
  type: 'spec' | 'param'
  type_label: string
  is_filterable: boolean
  is_multiple: boolean
  allow_custom: boolean
  sort: number
  values: AttributeValueRow[]
}

export function getAttributes(params: { keyword?: string; type?: 'spec' | 'param' | ''; is_filterable?: number | ''; page?: number; page_size?: number } = {}) {
  return request.get<ApiResult<{ list: AttributeRow[]; pagination: Pagination }>>('/admin/attributes', { params })
}

export function createAttribute(data: {
  name: string
  type: 'spec' | 'param'
  is_filterable?: boolean
  is_multiple?: boolean
  allow_custom?: boolean
  sort?: number
  values?: string[]
}) {
  return request.post<ApiResult<{ id: number }>>('/admin/attributes', data)
}

export function updateAttribute(id: number, data: Partial<{ name: string; type: 'spec' | 'param'; is_filterable: boolean; is_multiple: boolean; allow_custom: boolean; sort: number }>) {
  return request.put<ApiResult<null>>(`/admin/attributes/${id}`, data)
}

export function deleteAttribute(id: number) {
  return request.delete<ApiResult<null>>(`/admin/attributes/${id}`)
}

export function getAttributeValues(id: number) {
  return request.get<ApiResult<AttributeValueRow[]>>(`/admin/attributes/${id}/values`)
}

export function addAttributeValue(id: number, value: string, sort = 0) {
  return request.post<ApiResult<{ id: number }>>(`/admin/attributes/${id}/values`, { value, sort })
}

export function batchSaveAttributeValues(id: number, values: string[]) {
  return request.post<ApiResult<{ created: number; removed: number; retained: string[] }>>(
    `/admin/attributes/${id}/values/batch`,
    { values },
  )
}

export function deleteAttributeValue(id: number, valueId: number) {
  return request.delete<ApiResult<null>>(`/admin/attributes/${id}/values/${valueId}`)
}

// ---------- 分类属性模板 ----------

export interface CategoryTemplate {
  category_id: number
  category_name: string
  attributes: Array<{
    attribute_id: number
    name: string
    type: 'spec' | 'param'
    is_filterable: boolean
    is_required: boolean
    sort: number
  }>
}

export function getCategoryTemplate(categoryId: number) {
  return request.get<ApiResult<CategoryTemplate>>(`/admin/categories/${categoryId}/attributes`)
}

export function saveCategoryTemplate(categoryId: number, attributes: Array<{ attribute_id: number; is_required?: boolean; sort?: number }>) {
  return request.put<ApiResult<null>>(`/admin/categories/${categoryId}/attributes`, { attributes })
}

// ---------- SKU 矩阵（T-009 / T-010） ----------

export interface SkuMatrixItem {
  signature: string
  specs: Record<string, string>
}

export interface SkuMatrixPreview {
  total: number
  created: SkuMatrixItem[]
  kept: SkuMatrixItem[]
  removed: Array<{ sku_id: number; specs: Record<string, string>; signature: string; action: 'disable' | 'delete' }>
  max_skus: number
}

export function previewSkuMatrix(data: {
  product_id?: number | null
  specs_selection: Array<{ attribute_id: number; values: number[] }>
}) {
  return request.post<ApiResult<SkuMatrixPreview>>('/admin/products/sku-matrix', data)
}

export function batchSetSkus(id: number, rule: {
  attribute?: string
  value?: string
  price_delta?: number
  price?: number
  stock?: number
  status?: number
}) {
  return request.post<ApiResult<{ affected: number }>>(`/admin/products/${id}/skus/batch-set`, rule)
}
