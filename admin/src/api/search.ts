import request from './request'
import type { ApiResult } from './request'

/**
 * 站内搜索后台配置（V1.2 站内搜索 S1-08/S1-09，权限 search.manage）
 *
 * 后端：app/Http/Controllers/Admin/SearchController.php
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §6
 *
 * ⚠️ 引擎取值语义（与后端 `REQUEST_ENGINES` 对齐，勿在前端自行放宽）：
 * - 省略 / null = 不改动；
 * - `'auto'` = 清空库值、跟随 .env（**不能**用 `''` 表达 —— 全局中间件
 *   `ConvertEmptyStringsToNull` 会把空串变成 null，语义就丢了）；
 * - `'postgres'` / `'like'` 强制指定；后端配置里还有 `'off'`（等同 like），
 *   只出现在读取结果里，提交时统一用 like。
 */

export type SearchEngineKey = 'auto' | 'postgres' | 'like' | 'off'

/** GET /admin/search/config 的引擎选项（key 可能为 ''，表示「自动」） */
export interface SearchEngineOption {
  /** '' = 自动（PG 可用则用，否则降级 LIKE） */
  key: string
  label: string
  /** 该引擎当前环境是否可用（如 PG 未连接时 postgres 为 false） */
  available: boolean
  current: boolean
}

export interface SearchConfigData {
  /** 库里配的引擎（'' / 'auto' = 自动） */
  engine: string
  /** 实际生效的引擎名（resolver 解析后）—— 两者不一致即处于降级状态 */
  active_engine: string
  degraded: boolean
  engines: SearchEngineOption[]
  /** 后台可维护开关的当前值（键 => 值，值是字符串：'1'/'0' 或数值串） */
  switches: Record<string, string>
  /** 索引版本号：命中集缓存 key 的一部分，任何配置变更 / 重建都会递增 */
  index_version: number
}

export interface SearchConfigUpdatePayload {
  /** 省略 = 不改动；'auto' = 清空库值跟随 .env */
  engine?: SearchEngineKey
  /** 只认后端白名单内的键，其余会被静默忽略 */
  switches?: Record<string, string>
}

export interface SearchConfigUpdateResult {
  engine: string
  switches: Record<string, string>
  index_version: number
}

export interface SearchKeywordRow {
  id: number
  keyword: string
  hit_count: number
  /** 最近一次搜索的命中数；**0 且 hit_count 高** = 有人搜但搜不到（运营最该关注） */
  result_count: number
  last_hit_at: string | null
  status: number
  updated_at: string | null
}

export interface SearchKeywordQuery {
  keyword?: string
  page?: number
  page_size?: number
}

export interface SearchKeywordList {
  list: SearchKeywordRow[]
  pagination: {
    page: number
    page_size: number
    total: number | null
    total_pages: number | null
    has_more: boolean
  }
}

export interface SearchReindexResult {
  updated: number
  index_version: number
  elapsed_ms: number
}

/** 搜索配置详情（GET /admin/search/config） */
export function getSearchConfig() {
  return request.get<ApiResult<SearchConfigData>>('/admin/search/config')
}

/** 更新配置（PUT /admin/search/config）。两种变更后端都会递增 index_version */
export function updateSearchConfig(payload: SearchConfigUpdatePayload) {
  return request.put<ApiResult<SearchConfigUpdateResult>>('/admin/search/config', payload)
}

/** 热搜词列表（GET /admin/search/keywords，page_size 上限 100） */
export function getSearchKeywords(query: SearchKeywordQuery = {}) {
  const params: Record<string, string | number> = {}
  if (query.keyword) params.keyword = query.keyword
  if (query.page) params.page = query.page
  if (query.page_size) params.page_size = query.page_size
  return request.get<ApiResult<SearchKeywordList>>('/admin/search/keywords', { params })
}

/** 重建检索索引（POST /admin/search/reindex）。同步执行，秒级返回统计 */
export function reindexSearch() {
  return request.post<ApiResult<SearchReindexResult>>('/admin/search/reindex')
}
