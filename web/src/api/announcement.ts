import request from './request'
import type { ApiResult, PublicPagination } from './types'

// ================= 公告（用户端，公开无需登录） =================

export interface AnnouncementListItem {
  /** P2-11：对外标识 public_id（ULID 字符串，非自增主键） */
  id: string
  title: string
  is_top: boolean
  published_at: string | null
  summary: string
}

export interface AnnouncementDetail {
  id: string
  title: string
  content: string
  is_top: boolean
  published_at: string | null
  created_at: string
  updated_at: string
}

export interface AnnouncementListResult {
  list: AnnouncementListItem[]
  pagination: PublicPagination
}

export function getAnnouncements(params: { page?: number; per_page?: number } = {}) {
  return request.get<ApiResult<AnnouncementListResult>>('/announcements', { params })
}

export function getAnnouncement(id: string) {
  return request.get<ApiResult<{ announcement: AnnouncementDetail }>>(`/announcements/${id}`)
}
