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
  /** 父栏目 id，0=根 */
  parent_id: number
  /** 层级，根为 1 */
  level: number
  /** 物化路径，如 /1/5/ */
  path: string
  /** channel=栏目（挂文章） / page=单页 */
  type: 'channel' | 'page'
  /** 单页 URL 标识（前台 /p/{slug}） */
  slug: string | null
  /** 单页模板 key（后端 CmsPageTemplate 真源） */
  template: string | null
  /** CMS 新闻中心：列表形态 card=图文卡片 / list=列表行（channel 才有意义，page 恒空） */
  list_style: string
  /** 是否进入前台导航 */
  show_in_nav: boolean
  icon: string | null
  sort: number
  is_active: boolean
  /** CMS-202：SEO（可为空，空则前台按栏目名/正文回落） */
  seo_title: string | null
  seo_keywords: string | null
  seo_description: string | null
  articles_count: number
  published_count: number
  /** 仅树形接口返回 */
  children?: CsFaqCategoryRow[]
}

export interface CsFaqArticleRow {
  id: number
  category_id: number
  category?: { id: number; name: string } | null
  title: string
  /** 后期增强：语义化 URL 标识（前台 /news/{slug}；空则用 id） */
  slug: string | null
  summary: string | null
  /** 后期增强：文章级 SEO 三列（可空，详情页回落栏目/标题摘要） */
  seo_title: string | null
  seo_keywords: string | null
  seo_description: string | null
  /** 后期增强：标签（字符串数组，用于专题聚合） */
  tags: string[] | null
  /** CMS 新闻中心：封面图（图文卡片用；存上传返回的 URL/相对路径） */
  cover_image: string | null
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
  /** 后期增强：已关联的种草商品 id（编辑器多选回填） */
  product_ids?: number[]
  created_at: string
}

export interface CsFaqArticlePayload {
  category_id: number
  title: string
  /** 后期增强：留空则由后端按标题自动生成 slug（编辑时留空不覆盖既有 slug） */
  slug?: string | null
  summary?: string | null
  /** 后期增强：文章级 SEO 三列（空串表示清空） */
  seo_title?: string | null
  seo_keywords?: string | null
  seo_description?: string | null
  /** 后期增强：标签（逗号分隔字符串或数组，后端归一） */
  tags?: string | null
  /** CMS 新闻中心：封面图（图文新闻卡片用；存上传返回的 URL/相对路径） */
  cover_image?: string | null
  /** 正文 markdown 源；HTML 产物由后端渲染 + 净化派生，不由客户端提供 */
  content_md: string
  sort?: number
  is_hot?: boolean
  status?: 'draft' | 'published' | 'offline'
  /** 后期增强：关联种草商品 id 列表（不传表示不改关联） */
  product_ids?: number[]
}

export function getCsFaqCategories() {
  return request.get<ApiResult<CsFaqCategoryRow[]>>('/admin/cs/faq/categories')
}

/** 栏目新建/更新入参（CMS-104：支持父子、类型与单页属性） */
export interface CsFaqCategoryPayload {
  name: string
  parent_id?: number
  type?: 'channel' | 'page'
  slug?: string | null
  template?: string | null
  show_in_nav?: boolean
  icon?: string | null
  sort?: number
  is_active?: boolean
  /** CMS 新闻中心：列表形态 card=图文卡片 / list=列表行（channel 才有意义，page 忽略） */
  list_style?: string | null
  /** CMS-202：SEO 三列（空串表示清空，后端归一为 null） */
  seo_title?: string | null
  seo_keywords?: string | null
  seo_description?: string | null
}

export function createCsFaqCategory(data: CsFaqCategoryPayload) {
  return request.post<ApiResult<CsFaqCategoryRow>>('/admin/cs/faq/categories', data)
}

export function updateCsFaqCategory(id: number, data: Partial<CsFaqCategoryPayload>) {
  return request.put<ApiResult<CsFaqCategoryRow>>(`/admin/cs/faq/categories/${id}`, data)
}

/** 栏目换父（防环与子树级联由后端 CmsCategoryService 保证） */
export function moveCsFaqCategory(id: number, parentId: number) {
  return request.post<ApiResult<CsFaqCategoryRow>>(`/admin/cs/faq/categories/${id}/move`, {
    parent_id: parentId,
  })
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

/**
 * 单篇文章详情（后台独立编辑页回源用）
 *
 * 编辑页是独立路由，刷新/直达时拿不到列表页内存里的行数据，必须按 id 回查。
 */
export interface CsFaqArticleDetail extends CsFaqArticleRow {
  /**
   * 已关联种草商品（编辑页 chips 直接展示，免二次请求）
   *
   * `id` 是管理端自增主键（勾选/提交用），`public_id` 是对外标识 ——
   * 「插入正文」往正文里写的标记只放 public_id（见 utils/csArticle 的 buildProductToken）。
   */
  products: Array<{ id: number; public_id: string; title: string }>
}

export function getCsFaqArticle(id: number) {
  return request.get<ApiResult<CsFaqArticleDetail>>(`/admin/cs/faq/articles/${id}`)
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
    /** 后期增强：关联种草商品（编辑器回填 chips 标题用） */
    products?: Array<{ id: number; public_id: string; title: string; main_image: string | null; price: string }>
  }>>(`/admin/cs/faq/articles/${id}/preview`)
}

// ================= 单页内容（CMS-105，权限 cs.faq.manage） =================

/** 单页字段类型（与后端 CmsField::TYPES 一一对应；select/channels 为 CMS-203 新增） */
export type CmsPageFieldType =
  | 'text' | 'textarea' | 'markdown' | 'image' | 'image_list' | 'repeater' | 'select' | 'channels'

export interface CmsPageField {
  key: string
  label: string
  type: CmsPageFieldType
  required?: boolean
  default?: unknown
  hint?: string
  /** 仅 repeater：子字段 schema */
  item?: CmsPageField[]
  /** 仅 select：可选值（真源在后端 schema） */
  options?: Array<{ value: string; label: string }>
}

export interface CmsPageTemplateOption {
  key: string
  label: string
}

/** 区块定义（CMS-203，真源在后端 CmsBlock）；fields 与单页字段同一套结构 */
export interface CmsPageBlockOption {
  key: string
  label: string
  description: string
  fields: CmsPageField[]
}

/** 待保存的区块：`type` 取自区块库，`data` 按该区块的 schema 填写 */
export interface CmsPageBlockPayload {
  type: string
  data: Record<string, unknown>
}

/** 频道类栏目下拉项（`channels` 字段用，只含 type=channel） */
export interface CsChannelOption {
  id: number
  name: string
  level: number
}

export interface CmsPageDetail {
  category: {
    id: number
    name: string
    slug: string | null
    template: string | null
    is_active: boolean
  }
  template: {
    key: string
    label: string
    fields: CmsPageField[]
    /** CMS-203：内容改由 blocks 编排（此时 fields 恒为空数组） */
    is_blocks: boolean
  }
  /** 字段值（后端已与 schema 默认值合并，前端不做默认值兜底） */
  values: Record<string, unknown>
  /** 区块值（固定模板恒为空数组） */
  blocks: CmsPageBlockPayload[]
  updated_at: string | null
}

/** 单页模板下拉（新增单页时选择；真源在后端注册表） */
export function getCsFaqPageTemplates() {
  return request.get<ApiResult<CmsPageTemplateOption[]>>('/admin/cs/faq/page-templates')
}

/** 区块库（选中「自由区块」模板后才需要，故与模板下拉分开） */
export function getCsFaqPageBlocks() {
  return request.get<ApiResult<CmsPageBlockOption[]>>('/admin/cs/faq/page-blocks')
}

/** 取单页模板 schema 与当前字段值 */
export function getCsFaqPage(id: number) {
  return request.get<ApiResult<CmsPageDetail>>(`/admin/cs/faq/pages/${id}`)
}

/** 保存单页字段（schema 外的未知键由后端丢弃） */
export function saveCsFaqPage(id: number, fields: Record<string, unknown>) {
  return request.put<ApiResult<null>>(`/admin/cs/faq/pages/${id}`, { fields })
}

/** 保存区块化单页（与 saveCsFaqPage 是同一接口的另一种载体，后端按模板分流） */
export function saveCsFaqPageBlocks(id: number, blocks: CmsPageBlockPayload[]) {
  return request.put<ApiResult<null>>(`/admin/cs/faq/pages/${id}`, { blocks })
}

/** 单页图片上传（落 uploads/cms，与商品图片分开存放便于运维清理） */
export function uploadCmsImage(file: File) {
  const form = new FormData()
  form.append('file', file)
  return request.post<ApiResult<{ url: string }>>('/admin/cs/faq/upload', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
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
