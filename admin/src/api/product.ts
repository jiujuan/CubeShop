import request from './request'
import type { ApiResult } from './request'

// ---------- 类型 ----------

export interface CategoryNode {
  id: number
  parent_id: number
  name: string
  sort: number
  status: number
  children: CategoryNode[]
}

export interface Sku {
  id?: number
  sku_code: string | null
  specs: Record<string, string>
  signature?: string
  price: string | number
  stock: number
  status: number
}

export interface AdminProduct {
  id: number
  title: string
  subtitle: string | null
  main_image: string | null
  price: string
  status: number
  sales_count: number
  total_stock?: number
  category?: { id: number; name: string } | null
  category_id?: number | null
  description?: string | null
  description_md?: string | null
  images?: string[]
  skus?: Sku[]
  created_at?: string
  in_stock_count?: number
  // V1.1 E01
  brand_id?: number | null
  brand?: { id: number; name: string } | null
  weight?: number
  video_url?: string | null
  keywords?: string | null
  sort?: number
  attribute_values?: Array<{ attribute_id: number; attribute_name?: string | null; value: string }>
  specs_selection?: Array<{ attribute_id: number | null; name: string; value_names: string[] }>
}

export interface ProductPayload {
  category_id: number | null
  title: string
  subtitle?: string
  main_image?: string | null
  description?: string
  /** 详情正文 markdown 源（md-editor-v3 编辑，与帮助中心文章一致）；后端派生 description(HTML) */
  description_md?: string
  status: number
  sort?: number
  /** V1.1 E01 新增 */
  brand_id?: number | null
  weight?: number
  video_url?: string | null
  keywords?: string | null
  attribute_values?: Array<{ attribute_id: number; value: string }>
  specs_selection?: Array<{ attribute_id: number; values: number[] }>
  skus: Array<{
    sku_code?: string | null
    signature?: string
    specs?: Record<string, string>
    price?: number | string
    stock?: number
    status?: number
  }>
  images: string[]
}

export interface Pagination {
  page: number
  page_size: number
  total: number
  total_pages: number
}

export interface ProductQuery {
  keyword?: string
  category_id?: number | ''
  status?: number | ''
  min_price?: string
  max_price?: string
  sort?: string
  page?: number
  page_size?: number
}

// ---------- 管理端分类（API 文档 8.2） ----------

export function getCategories() {
  return request.get<ApiResult<CategoryNode[]>>('/admin/categories')
}

export function createCategory(data: { parent_id: number; name: string; sort?: number; status?: number }) {
  return request.post<ApiResult<CategoryNode>>('/admin/categories', data)
}

export function updateCategory(id: number, data: Partial<{ parent_id: number; name: string; sort: number; status: number }>) {
  return request.put<ApiResult<CategoryNode>>(`/admin/categories/${id}`, data)
}

export function deleteCategory(id: number) {
  return request.delete<ApiResult<null>>(`/admin/categories/${id}`)
}

// ---------- 管理端商品（API 文档 8.1） ----------

export function getProducts(params: ProductQuery) {
  return request.get<ApiResult<{ list: AdminProduct[]; pagination: Pagination }>>('/admin/products', { params })
}

export function getProduct(id: number | string) {
  return request.get<ApiResult<AdminProduct>>(`/admin/products/${id}`)
}

export function createProduct(data: ProductPayload) {
  return request.post<ApiResult<{ id: number }>>('/admin/products', data)
}

export function updateProduct(id: number | string, data: ProductPayload) {
  return request.put<ApiResult<null>>(`/admin/products/${id}`, data)
}

export function updateProductStatus(id: number | string, status: number) {
  return request.post<ApiResult<null>>(`/admin/products/${id}/status`, { status })
}

export function batchProducts(ids: number[], action: 'on_shelf' | 'off_shelf') {
  return request.post<ApiResult<{ count: number }>>('/admin/products/batch', { ids, action })
}

// ---------- 图片上传（API 文档 12.5） ----------

export function uploadImage(file: File) {
  const form = new FormData()
  form.append('file', file)
  return request.post<ApiResult<{ url: string }>>('/admin/upload', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}
