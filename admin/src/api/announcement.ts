import request from './request'
import type { ApiResult } from './request'

export interface Pagination {
  page: number
  page_size: number
  total: number
  total_pages: number
}

// ================= 公告管理（P-Announcement，权限 announcement.manage） =================

export interface AnnouncementRow {
  id: number
  title: string
  is_top: boolean
  status: 'draft' | 'published' | 'offline'
  status_label: string
  published_at: string | null
  created_at: string
  summary?: string
}

export interface AnnouncementDetail {
  id: number
  title: string
  /** 正文 markdown 源（编辑器回显用）；未迁移的存量行可能为 null */
  content_md: string | null
  /** 渲染后的 HTML 产物（预览 v-html 用，已由后端净化） */
  content: string
  is_top: boolean
  status: 'draft' | 'published' | 'offline'
  published_at: string | null
  created_at: string
  updated_at: string
}

export interface AnnouncementPayload {
  title: string
  /** 正文 markdown 源；HTML 产物由后端渲染 + 净化派生，不由客户端提供 */
  content_md: string
  is_top?: boolean
  status?: 'draft' | 'published' | 'offline'
}

export function getAnnouncements(params: { status?: string; keyword?: string; page?: number; per_page?: number } = {}) {
  return request.get<ApiResult<{ list: AnnouncementRow[]; pagination: Pagination }>>('/admin/announcements', { params })
}

export function createAnnouncement(data: AnnouncementPayload) {
  return request.post<ApiResult<AnnouncementDetail>>('/admin/announcements', data)
}

export function updateAnnouncement(id: number, data: Partial<AnnouncementPayload>) {
  return request.put<ApiResult<AnnouncementDetail>>(`/admin/announcements/${id}`, data)
}

export function deleteAnnouncement(id: number) {
  return request.delete<ApiResult<null>>(`/admin/announcements/${id}`)
}

export function publishAnnouncement(id: number) {
  return request.post<ApiResult<AnnouncementDetail>>(`/admin/announcements/${id}/publish`)
}

export function offlineAnnouncement(id: number) {
  return request.post<ApiResult<AnnouncementDetail>>(`/admin/announcements/${id}/offline`)
}

export function previewAnnouncement(id: number) {
  return request.get<ApiResult<AnnouncementDetail>>(`/admin/announcements/${id}/preview`)
}
