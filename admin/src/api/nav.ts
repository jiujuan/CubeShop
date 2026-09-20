import request from './request'
import type { ApiResult } from './request'

// ================= 前台顶部导航（权限 nav.manage） =================

/**
 * 导航条目类型
 * - `category`：商品分类引用（标题与链接由分类派生，改名自动跟随）
 * - `custom`：自定义链接（可指向站内任意路径或站外地址）
 */
export type NavItemType = 'category' | 'custom'

export interface NavItemRow {
  id: number
  type: NavItemType
  type_label: string
  /** 仅 custom 有值；category 型为 null（标题取自分类） */
  title: string | null
  url: string | null
  category_id: number | null
  /** 引用分类的当前名称（后台核对用，改名自动跟随） */
  category_name: string | null
  /** true = 引用已失效（分类被删除或禁用），公开接口会跳过该条 */
  category_missing: boolean
  target: '_self' | '_blank'
  sort: number
  is_active: boolean
}

export interface NavItemPayload {
  type: NavItemType
  category_id?: number | null
  title?: string | null
  url?: string | null
  target?: '_self' | '_blank'
  sort?: number
  is_active?: boolean
}

export function getNavItems() {
  return request.get<ApiResult<NavItemRow[]>>('/admin/nav-items')
}

export function createNavItem(data: NavItemPayload) {
  return request.post<ApiResult<NavItemRow>>('/admin/nav-items', data)
}

export function updateNavItem(id: number, data: Partial<NavItemPayload>) {
  return request.put<ApiResult<NavItemRow>>(`/admin/nav-items/${id}`, data)
}

export function deleteNavItem(id: number) {
  return request.delete<ApiResult<null>>(`/admin/nav-items/${id}`)
}
