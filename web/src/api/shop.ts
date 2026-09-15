import request from './request'
import type { ApiResult, CategoryNode, Pagination, ProductBrief, ProductDetail, ProductSku } from './types'

export type { CategoryNode, Pagination, ProductBrief, ProductDetail, ProductSku }

/** 商品列表 / 搜索（API 文档 4.1） */
export function getProducts(params: {
  keyword?: string
  category_id?: number
  min_price?: number
  max_price?: number
  sort?: string
  page?: number
  page_size?: number
}) {
  return request.get<ApiResult<{ list: ProductBrief[]; pagination: Pagination }>>('/products', { params })
}

/** 商品详情（API 文档 4.2） */
export function getProduct(id: number | string) {
  return request.get<ApiResult<ProductDetail>>(`/products/${id}`)
}

/** 分类树（API 文档 4.3） */
export function getCategories() {
  return request.get<ApiResult<CategoryNode[]>>('/products/categories')
}

/** 热销 / 新品（API 文档 4.4） */
export function getHot(limit = 8) {
  return request.get<ApiResult<{ hot: ProductBrief[]; newest: ProductBrief[] }>>('/products/hot', {
    params: { limit },
  })
}
