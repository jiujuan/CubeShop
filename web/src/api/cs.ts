import request from './request'
import type { ApiResult, Pagination } from './types'

// ================= 帮助中心 FAQ（用户端，CS-104） =================

export interface FaqCategory {
  id: number
  name: string
  sort: number
  published_count: number
}

export interface FaqArticle {
  id: number
  category_id: number
  category?: { id: number; name: string } | null
  title: string
  summary: string | null
  content: string
  status?: string
  sort: number
  is_hot: boolean
  view_count: number
  helpful_count: number
  unhelpful_count: number
  created_at: string
}

export interface FaqArticleDetailPayload {
  article: FaqArticle
  related: FaqArticle[]
}

export function getFaqCategories() {
  return request.get<ApiResult<FaqCategory[]>>('/cs/faq/categories')
}

export function getFaqArticles(params: { category_id?: number; keyword?: string; page?: number; per_page?: number } = {}) {
  return request.get<ApiResult<{ list: FaqArticle[]; pagination: Pagination }>>('/cs/faq/articles', { params })
}

export function getFaqArticle(id: number | string) {
  return request.get<ApiResult<FaqArticleDetailPayload>>(`/cs/faq/articles/${id}`)
}

export function postFaqFeedback(id: number | string, helpful: boolean) {
  return request.post<ApiResult<{ helpful_count: number; unhelpful_count: number }>>(
    `/cs/faq/articles/${id}/feedback`,
    { helpful },
  )
}

// ================= 服务工单（用户端，CS-106 / CS-107） =================

export interface TicketType {
  id: number
  name: string
  code: string
  require_order: boolean
}

export interface TicketMessage {
  id: number
  sender_type: 'user' | 'staff' | 'system'
  sender_name: string
  content: string | null
  images: string[]
  is_internal: boolean
  created_at: string
}

export interface TicketListItem {
  /** P2-11：工单对外标识（public_id 字符串，非自增主键） */
  id: string
  ticket_no: string
  type_id: number
  type_name: string | null
  order_id: string | number | null
  title: string
  status: string
  status_label: string
  priority: number
  priority_label: string
  contact: string | null
  can_reply: boolean
  can_close: boolean
  message_count: number
  last_message_at: string | null
  created_at: string
  closed_at: string | null
}

export interface TicketDetail extends Omit<TicketListItem, 'message_count'> {
  messages: TicketMessage[]
}

export interface TicketOrderSummary {
  order_no: string
  status: string
  pay_amount: string
  product_image: string | null
}

export function getTicketTypes() {
  return request.get<ApiResult<TicketType[]>>('/cs/ticket-types')
}

export function createTicket(data: {
  type_id: number
  title: string
  content: string
  order_id?: string | number | null
  images?: string[]
  contact?: string
}) {
  return request.post<ApiResult<{ ticket: TicketDetail }>>('/cs/tickets', data)
}

export function getTickets(params: { status?: string; page?: number; per_page?: number } = {}) {
  return request.get<ApiResult<{ list: TicketListItem[]; pagination: Pagination }>>('/cs/tickets', { params })
}

export function getTicket(id: number | string) {
  return request.get<ApiResult<{ ticket: TicketDetail; order: TicketOrderSummary | null }>>(`/cs/tickets/${id}`)
}

export function addTicketMessage(id: number | string, data: { content?: string; images?: string[] }) {
  return request.post<ApiResult<{ message: TicketMessage; ticket: TicketDetail }>>(`/cs/tickets/${id}/messages`, data)
}

export function closeTicket(id: number | string) {
  return request.post<ApiResult<TicketDetail>>(`/cs/tickets/${id}/close`)
}

export function uploadTicketImage(file: File) {
  const form = new FormData()
  form.append('image', file)
  return request.post<ApiResult<{ url: string }>>('/cs/upload-image', form)
}
