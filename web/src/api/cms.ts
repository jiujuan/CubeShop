import request from './request'
import type { ApiResult } from './types'

/**
 * 内容中心 CMS · 用户端（CMS-112）
 *
 * 单页与导航都是**公开**内容（决策 D4），无需登录即可访问。
 * 单页对外只暴露 slug，不暴露内部自增 id（决策 R3）。
 */

/** 导航栏目节点（/cms/nav，仅含标记了 show_in_nav 的根栏目及其子孙） */
export interface CmsNavNode {
  id: number
  name: string
  slug: string | null
  /** channel=栏目（挂文章列表） / page=单页 */
  type: 'channel' | 'page'
  template: string | null
  icon: string | null
  children: CmsNavNode[]
}

/** 单页 SEO 三元组（CMS-202；后端已做回落，前端直接写入 meta） */
export interface CmsPageSeo {
  title: string
  keywords: string
  description: string
}

/** 帮助中心嵌入区块带出的文章条目（`faq_embed` 专用） */
export interface CmsPageBlockItem {
  id: number
  title: string
  summary: string | null
}

/**
 * 单页区块（CMS-203）
 *
 * `data` 是后台存下的源值；`html` 是其中 markdown 字段的渲染产物（已净化），
 * `items` 只对 `faq_embed` 有值（后端随响应带出，前台因此零请求）。
 * 三者都由后端产出，前端不做二次加工。
 */
export interface CmsPageBlock {
  /** 区块 key，对应 views/blocks/Block{Key}.vue */
  type: string
  data: Record<string, unknown>
  /** markdown 字段的渲染产物，键为字段 key */
  html: Record<string, string>
  items: CmsPageBlockItem[]
}

/** 单页内容（/cms/pages/{slug}） */
export interface CmsPageContent {
  name: string
  slug: string
  /** 模板 key，对应 views/pages/Page{Key}.vue（`blocks` 为区块化模板） */
  template: string
  /** 字段原始值（与模板 schema 对齐，后端已并默认值） */
  fields: Record<string, unknown>
  /** markdown 字段的渲染产物（已由后端净化），前端直接 v-html */
  html: Record<string, string>
  /** 区块化单页的内容（固定模板单页为空数组） */
  blocks: CmsPageBlock[]
  /** 页面 SEO（后台可空，后端已按栏目名/正文回落） */
  seo: CmsPageSeo
  updated_at: string | null
}

export function getCmsNav() {
  return request.get<ApiResult<CmsNavNode[]>>('/cms/nav')
}

export function getCmsPage(slug: string) {
  return request.get<ApiResult<CmsPageContent>>(`/cms/pages/${slug}`)
}
