import { REFUND_TYPE_LABELS, type RefundType } from '@/api/refund'

/**
 * 退款处理流水 content 的友好格式化
 *
 * 后端 `sys_operation_log.content` 存的是 JSON 字符串（后台直接展示不可读）。
 * 详情接口额外返回解码后的 `content_data`，此模块把它转成「中文标签 + 值」的行，
 * 并识别图片数组/明细数组，供后台「处理记录」渲染。
 */

/** 流水字段中文标签（未知键回退原文键名） */
export const REFUND_LOG_KEY_LABELS: Record<string, string> = {
  order_no: '订单号',
  refund_no: '退款单号',
  type: '退款类型',
  amount: '退款金额',
  reason: '退款理由',
  images: '用户凭证图片',
  max_refundable: '可退上限',
  admin_remark: '处理理由',
  admin_images: '说明图片',
  received_details: '实收明细',
  return_details: '应退明细',
  exception_reason: '差异说明',
  coupon_id: '优惠券 ID',
  warehouse_id: '仓库 ID',
}

/** 金额类字段（展示时加 ¥ 前缀） */
const MONEY_KEYS = new Set(['amount', 'max_refundable'])

/** 商品状态中文 */
const CONDITION_LABELS: Record<string, string> = { good: '正品', defective: '残次' }

/** 形如 /storage/a/b.jpg 或 https://x/y.png 的图片地址 */
const IMAGE_URL_RE = /\.(png|jpe?g|gif|webp|bmp|svg)(\?.*)?$/i

export interface RefundLogField {
  key: string
  label: string
  /** text 文本 / money 金额 / images 图片 / lines 多行明细 / json 兜底 */
  kind: 'text' | 'money' | 'images' | 'lines' | 'json'
  text?: string
  images?: string[]
  lines?: string[]
  raw?: string
}

/** 解析 JSON 字符串（失败返回 null） */
function parseJson(content: string | null | undefined): unknown {
  if (!content) return null
  try {
    return JSON.parse(content)
  } catch {
    return null
  }
}

function isNonEmptyStringArray(value: unknown): value is string[] {
  return Array.isArray(value) && value.length > 0 && value.every((v) => typeof v === 'string' && v.trim() !== '')
}

/** 判断某字段值是否应作为图片展示 */
function looksLikeImages(key: string, value: unknown): boolean {
  if (!isNonEmptyStringArray(value)) return false
  if (key.includes('image')) return true
  return value.every((v) => IMAGE_URL_RE.test(v))
}

/** 明细行（应退/实收）转可读文本 */
function detailLine(row: unknown): string {
  if (row === null || row === undefined) return '-'
  if (typeof row !== 'object') return String(row)

  const r = row as Record<string, unknown>
  const title = typeof r.product_title === 'string' && r.product_title ? `${r.product_title} ` : ''
  const specs =
    r.sku_specs && typeof r.sku_specs === 'object' && !Array.isArray(r.sku_specs)
      ? Object.values(r.sku_specs as Record<string, string>).filter(Boolean).join(' / ')
      : ''
  const sku = r.sku_id ?? r.sku ?? '-'
  const qty = r.quantity ?? 0
  const condition = typeof r.condition === 'string' ? CONDITION_LABELS[r.condition] ?? r.condition : ''

  return `${title}SKU#${sku}${specs ? `（${specs}）` : ''} × ${qty}${condition ? `（${condition}）` : ''}`
}

function isEmptyValue(value: unknown): boolean {
  return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)
}

/** 单个键值 → 一行渲染描述 */
function toField(key: string, value: unknown): RefundLogField {
  const label = REFUND_LOG_KEY_LABELS[key] ?? key

  if (looksLikeImages(key, value)) {
    return { key, label, kind: 'images', images: value as string[] }
  }

  if (Array.isArray(value) && value.length > 0 && value.every((v) => v && typeof v === 'object')) {
    return { key, label, kind: 'lines', lines: value.map(detailLine) }
  }

  if (key === 'type' && typeof value === 'string') {
    return { key, label, kind: 'text', text: REFUND_TYPE_LABELS[value as RefundType] ?? value }
  }

  if (MONEY_KEYS.has(key) && value !== null && value !== undefined && value !== '') {
    return { key, label, kind: 'money', text: `¥${value}` }
  }

  if (value && typeof value === 'object') {
    return { key, label, kind: 'json', raw: JSON.stringify(value) }
  }

  return { key, label, kind: 'text', text: isEmptyValue(value) ? '-' : String(value) }
}

/**
 * 把一条流水格式化为可渲染字段列表
 *
 * @param content     原始 content（JSON 字符串或纯文本）
 * @param contentData 后端解码后的结构（优先使用）
 */
export function formatRefundLogFields(content: string | null | undefined, contentData?: unknown): RefundLogField[] {
  const data = contentData !== undefined && contentData !== null ? contentData : parseJson(content)

  // 无结构化数据：回退展示原始文本
  if (data === null || data === undefined) {
    return content ? [{ key: '_text', label: '', kind: 'text', text: content }] : []
  }

  // 纯字符串 / 数字
  if (typeof data !== 'object') {
    return [{ key: '_text', label: '', kind: 'text', text: String(data) }]
  }

  // 顶层数组：非对象数组按 JSON 兜底
  if (Array.isArray(data)) {
    if (data.length > 0 && data.every((v) => v && typeof v === 'object')) {
      return [{ key: '_lines', label: '', kind: 'lines', lines: data.map(detailLine) }]
    }
    return [{ key: '_json', label: '', kind: 'json', raw: JSON.stringify(data, null, 2) }]
  }

  return Object.entries(data as Record<string, unknown>)
    .filter(([, value]) => !isEmptyValue(value))
    .map(([key, value]) => toField(key, value))
}
