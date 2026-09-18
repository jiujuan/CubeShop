import request from './request'
import type { ApiResult } from './request'

export interface Pagination {
  page: number
  page_size: number
  total: number
  total_pages: number
}

// ================= 工单工作台（CS-108 / CS-109，权限 cs.ticket.view / cs.ticket.handle） =================

export interface CsTicketMessage {
  id: number
  sender_type: 'user' | 'staff' | 'system'
  sender_name: string
  content: string | null
  images: string[]
  is_internal: boolean
  created_at: string
}

export interface CsTicketRow {
  id: number
  ticket_no: string
  type_id: number
  type?: { id: number; name: string } | null
  type_name?: string | null
  user_id: number
  user?: { id: number; nickname: string | null; username: string } | null
  user_name?: string | null
  order_id: number | null
  title: string
  content?: string | null
  contact: string | null
  status: string
  status_label?: string
  priority: number
  priority_label?: string
  assignee_id: number | null
  assignee?: { id: number; username: string } | null
  assignee_name?: string | null
  first_replied_at?: string | null
  last_message_at: string | null
  created_at: string
  closed_at?: string | null
}

export interface CsTicketDetail extends CsTicketRow {
  messages: CsTicketMessage[]
}

export interface CsTicketUserSummary {
  id: number | null
  nickname: string | null
  phone_masked: string
  registered_at: string | null
  ticket_count: number
}

export interface CsTicketOrderSummary {
  order_no: string
  status: string
  pay_amount: string
  created_at: string
  product_image: string | null
}

/** CS-202：工单关联订单快照（实时聚合，见 CsTicketService::orderSnapshot） */
export interface CsTicketOrderSnapshotItem {
  product_id: number | null
  sku_id: number | null
  title: string
  specs: Record<string, string> | null
  image: string | null
  price: string
  quantity: number
  total_amount: string
  payable_amount: number
}

export interface CsTicketOrderSnapshotShipping {
  company_code: string
  company_name: string
  tracking_no: string
  trace_status: string
  shipped_at: string | null
  delivered_at: string | null
  latest_trace: { context: string; occurred_at: string } | null
}

export interface CsTicketOrderSnapshotRefund {
  refund_no: string
  amount: string
  status: string
  created_at: string
  /** 仅客服端下发；买家端不含该字段 */
  admin_remark?: string | null
}

export interface CsTicketOrderSnapshot {
  order_id: number
  order_no: string
  status: string
  status_label: string
  pay_amount: string
  created_at: string
  item_count: number
  items: CsTicketOrderSnapshotItem[]
  address: {
    contact_name: string | null
    phone_masked: string
    full_address: string | null
  }
  shipping: CsTicketOrderSnapshotShipping | null
  refunds: CsTicketOrderSnapshotRefund[]
  /** 仅后台下发：跳转订单详情/退款页所需参数（不代客操作） */
  jump?: { order_id: number; order_no: string; latest_refund_no: string | null }
}

export interface CsTicketActions {
  can_reply: boolean
  can_complete: boolean
  can_close: boolean
}

export interface CsTicketListResult {
  list: CsTicketRow[]
  pagination: Pagination
  meta: { pending_count: number }
}

export interface CsTicketTypeOption {
  id: number
  name: string
  code: string
  require_order: boolean
}

export function getCsTicketTypes() {
  return request.get<ApiResult<CsTicketTypeOption[]>>('/admin/cs/ticket-types')
}

/** 可转交的客服（后端按 cs.ticket.view 权限过滤，客服角色无需 account.manage 即可拿到） */
export interface CsAssigneeOption {
  id: number
  username: string
  nickname: string | null
}

export function getCsAssignees() {
  return request.get<ApiResult<CsAssigneeOption[]>>('/admin/cs/assignees')
}

export function getCsTickets(params: {
  status?: string
  type_id?: number
  assignee_id?: number
  priority?: number
  keyword?: string
  created_start?: string
  created_end?: string
  sort_by?: string
  sort_dir?: string
  page?: number
  per_page?: number
} = {}) {
  return request.get<ApiResult<CsTicketListResult>>('/admin/cs/tickets', { params })
}

export function getCsTicket(id: number) {
  return request.get<ApiResult<{
    ticket: CsTicketDetail
    user_summary: CsTicketUserSummary
    order_snapshot: CsTicketOrderSnapshot | null
    /** @deprecated CS-202 起改用 order_snapshot（旧形状兼容保留） */
    order: CsTicketOrderSummary | null
    actions: CsTicketActions
  }>>(`/admin/cs/tickets/${id}`)
}

export function replyCsTicket(id: number, data: { content?: string; images?: string[]; is_internal?: boolean }) {
  return request.post<ApiResult<{ message: CsTicketMessage; ticket: CsTicketDetail }>>(`/admin/cs/tickets/${id}/messages`, data)
}

export function changeCsTicketStatus(id: number, status: string) {
  return request.put<ApiResult<CsTicketDetail>>(`/admin/cs/tickets/${id}/status`, { status })
}

export function assignCsTicket(id: number, assigneeId: number | null) {
  return request.put<ApiResult<CsTicketDetail>>(`/admin/cs/tickets/${id}/assign`, { assignee_id: assigneeId })
}

export function setCsTicketPriority(id: number, priority: number) {
  return request.put<ApiResult<CsTicketDetail>>(`/admin/cs/tickets/${id}/priority`, { priority })
}

export function batchAssignCsTickets(ids: number[], assigneeId: number | null) {
  return request.post<ApiResult<{ affected: number }>>('/admin/cs/tickets/batch-assign', { ids, assignee_id: assigneeId })
}

// ================= FAQ 管理（CS-110，权限 cs.faq.manage） =================

export interface CsFaqCategoryRow {
  id: number
  name: string
  sort: number
  is_active: boolean
  articles_count: number
  published_count: number
}

export interface CsFaqArticleRow {
  id: number
  category_id: number
  category?: { id: number; name: string } | null
  title: string
  summary: string | null
  /** 正文 markdown 源（编辑器回显用）；未迁移的存量行可能为 null */
  content_md: string | null
  /** 渲染后的 HTML 产物（预览 v-html 用，已由后端净化） */
  content: string
  status: 'draft' | 'published' | 'offline'
  sort: number
  is_hot: boolean
  view_count: number
  helpful_count: number
  unhelpful_count: number
  helpful_rate: number | null
  created_at: string
}

export interface CsFaqArticlePayload {
  category_id: number
  title: string
  summary?: string | null
  /** 正文 markdown 源；HTML 产物由后端渲染 + 净化派生，不由客户端提供 */
  content_md: string
  sort?: number
  is_hot?: boolean
  status?: 'draft' | 'published' | 'offline'
}

export function getCsFaqCategories() {
  return request.get<ApiResult<CsFaqCategoryRow[]>>('/admin/cs/faq/categories')
}

export function createCsFaqCategory(data: { name: string; sort?: number; is_active?: boolean }) {
  return request.post<ApiResult<CsFaqCategoryRow>>('/admin/cs/faq/categories', data)
}

export function updateCsFaqCategory(id: number, data: Partial<{ name: string; sort: number; is_active: boolean }>) {
  return request.put<ApiResult<CsFaqCategoryRow>>(`/admin/cs/faq/categories/${id}`, data)
}

export function deleteCsFaqCategory(id: number) {
  return request.delete<ApiResult<null>>(`/admin/cs/faq/categories/${id}`)
}

export function sortCsFaqCategories(items: Array<{ id: number; sort: number }>) {
  return request.post<ApiResult<null>>('/admin/cs/faq/categories/sort', { items })
}

export function getCsFaqArticles(params: { category_id?: number; status?: string; keyword?: string; page?: number; per_page?: number } = {}) {
  return request.get<ApiResult<{ list: CsFaqArticleRow[]; pagination: Pagination }>>('/admin/cs/faq/articles', { params })
}

export function createCsFaqArticle(data: CsFaqArticlePayload) {
  return request.post<ApiResult<CsFaqArticleRow>>('/admin/cs/faq/articles', data)
}

export function updateCsFaqArticle(id: number, data: Partial<CsFaqArticlePayload>) {
  return request.put<ApiResult<CsFaqArticleRow>>(`/admin/cs/faq/articles/${id}`, data)
}

export function deleteCsFaqArticle(id: number) {
  return request.delete<ApiResult<null>>(`/admin/cs/faq/articles/${id}`)
}

export function publishCsFaqArticle(id: number) {
  return request.post<ApiResult<CsFaqArticleRow>>(`/admin/cs/faq/articles/${id}/publish`)
}

export function offlineCsFaqArticle(id: number) {
  return request.post<ApiResult<CsFaqArticleRow>>(`/admin/cs/faq/articles/${id}/offline`)
}

export function previewCsFaqArticle(id: number) {
  return request.get<ApiResult<{
    id: number; title: string; summary: string | null; content: string; category_name: string | null
    is_hot: boolean; status: string; view_count: number; helpful_count: number; unhelpful_count: number; helpful_rate: number
  }>>(`/admin/cs/faq/articles/${id}/preview`)
}

// ================= 快捷回复模板（CS-203 / CS-204） =================

export interface CsQuickReplyRow {
  id: number
  type_id: number | null
  type_name?: string | null
  title: string
  content: string
  sort: number
  created_by: number | null
  updated_by: number | null
  created_at: string
  updated_at: string
}

export interface CsQuickReplyPayload {
  title: string
  content: string
  type_id?: number | null
  sort?: number
}

/** 管理列表（全量）。需 cs.faq.manage；工作台下拉用下方按类型取用 */
export function getCsQuickReplies() {
  return request.get<ApiResult<CsQuickReplyRow[]>>('/admin/cs/quick-replies')
}

/** 工作台下拉：通用 + 类型专属（type_id 不传则只有通用） */
export function getCsQuickRepliesByType(typeId: number | null) {
  return request.get<ApiResult<CsQuickReplyRow[]>>('/admin/cs/quick-replies', {
    params: typeId == null ? {} : { type_id: typeId },
  })
}

export function createCsQuickReply(data: CsQuickReplyPayload) {
  return request.post<ApiResult<CsQuickReplyRow>>('/admin/cs/quick-replies', data)
}

export function updateCsQuickReply(id: number, data: Partial<CsQuickReplyPayload>) {
  return request.put<ApiResult<CsQuickReplyRow>>(`/admin/cs/quick-replies/${id}`, data)
}

export function deleteCsQuickReply(id: number) {
  return request.delete<ApiResult<null>>(`/admin/cs/quick-replies/${id}`)
}
