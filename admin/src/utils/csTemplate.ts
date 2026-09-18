/**
 * 快捷回复模板变量替换（CS-204）
 *
 * 与后端 `CsQuickReplyService::render()` 口径完全一致：
 * - {user_nickname} 工单发起人昵称
 * - {ticket_no}     工单号
 * - {order_no}      关联订单号
 *
 * 缺失的占位符统一替换为空串（与后端一致，不抛错）。
 */
export interface CsTemplateContext {
  user_nickname?: string | null
  ticket_no?: string | null
  order_no?: string | null
}

const KEYS: Array<keyof CsTemplateContext> = ['user_nickname', 'ticket_no', 'order_no']

export function renderCsTemplate(template: string, ctx: CsTemplateContext = {}): string {
  return template.replace(/{(user_nickname|ticket_no|order_no)}/g, (match) => {
    const key = match.slice(1, -1) as keyof CsTemplateContext
    return ctx[key] ?? ''
  })
}

/** 模板中可能出现的占位符（用于 UI 提示） */
export const CS_TEMPLATE_VARS = KEYS.map((k) => `{${k}}`)
