export interface ApiResult<T = unknown> {
  code: number
  message: string
  data: T
}

export interface Pagination {
  page: number
  page_size: number
  total: number
  total_pages: number
}

export interface CategoryNode {
  id: number
  name: string
  children: Array<{ id: number; name: string }>
}

export interface ProductBrief {
  id: number
  title: string
  subtitle: string | null
  main_image: string | null
  price: string
  sales_count: number
  total_stock?: number
  category?: { id: number; name: string } | null
}

export interface ProductSku {
  id: number
  sku_code: string
  specs: Record<string, string>
  price: string
  stock: number
  status: number
}

export interface ProductDetail {
  id: number
  title: string
  subtitle: string | null
  main_image: string | null
  images: string[]
  description: string | null
  price: string
  sales_count: number
  status: number
  category: { id: number; name: string } | null
  total_stock: number
  skus: ProductSku[]
}
