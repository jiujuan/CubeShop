import request from './request'
import type { ApiResult } from './request'

/**
 * 媒体库（图片资产治理 P2，权限 media.*）
 *
 * 后端接口见 docs/design/CubeShop_Media_Library_v1.0.md §6.2。
 * 注意：`url` 是出口拼好的绝对 URL（前端只代理 /api，必须绝对）；
 * `path` 才是写回业务字段之前库里存的那份相对路径。前端两种都可能需要：
 * - 展示用 `url`；
 * - 提交表单时后端模型 cast 会自动把绝对 URL 归一回相对路径，因此**直接提交 url 即可**。
 */
export interface MediaFile {
  id: number
  path: string
  url: string
  original_name: string | null
  module: string
  mime: string | null
  size: number
  size_human: string
  width: number | null
  height: number | null
  /** 引用计数（扫描得出，非实时）；> 0 表示仍被业务引用，不允许删除 */
  usage_count: number
  /** 物理文件是否还在盘上（登记了但文件丢失时用于提示） */
  exists_on_disk: boolean
  created_at: string | null
  updated_at: string | null
}

export interface MediaList {
  list: MediaFile[]
  /** 模块字典，供筛选下拉 */
  modules: string[]
  pagination: { page: number; page_size: number; total: number; total_pages: number }
}

export type MediaSort = 'latest' | 'oldest' | 'largest' | 'name'

export interface MediaQuery {
  keyword?: string
  module?: string
  unused?: boolean
  size_from?: number
  sort?: MediaSort
  page?: number
  per_page?: number
}

function toParams(q: MediaQuery): Record<string, string | number> {
  const params: Record<string, string | number> = {}
  if (q.keyword) params.keyword = q.keyword
  if (q.module) params.module = q.module
  if (q.unused) params.unused = 1
  if (q.size_from !== undefined) params.size_from = q.size_from
  if (q.sort) params.sort = q.sort
  if (q.page) params.page = q.page
  if (q.per_page) params.per_page = q.per_page

  return params
}

/** 分页检索（GET /admin/media，权限 media.view） */
export function getMediaList(query: MediaQuery = {}) {
  return request.get<ApiResult<MediaList>>('/admin/media', { params: toParams(query) })
}

/** 上传并登记（POST /admin/media，权限 media.upload） */
export function uploadMedia(file: File, module?: string) {
  const form = new FormData()
  form.append('file', file)
  if (module) form.append('module', module)

  return request.post<ApiResult<{ url: string; path: string; reused: boolean; media: MediaFile | null }>>(
    '/admin/media',
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
}

/** 改名 / 换模块（PATCH /admin/media/{id}，权限 media.manage） */
export function updateMedia(id: number, data: { original_name?: string; module?: string }) {
  return request.patch<ApiResult<MediaFile>>(`/admin/media/${id}`, data)
}

/** 替换文件、保留 path（POST /admin/media/{id}/replace，权限 media.manage） */
export function replaceMedia(id: number, file: File) {
  const form = new FormData()
  form.append('file', file)

  return request.post<ApiResult<{ url: string; path: string; media: MediaFile; reused_paths: string[] }>>(
    `/admin/media/${id}/replace`,
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
}

/** 软删（DELETE /admin/media/{id}，权限 media.manage；仍被引用时后端拒绝） */
export function deleteMedia(id: number) {
  return request.delete<ApiResult<null>>(`/admin/media/${id}`)
}
