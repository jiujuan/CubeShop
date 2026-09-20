/**
 * 内容中心文章的口径常量与文案（与后端迁移 000098 / AnnouncementController 一致）
 *
 * 公告并入内容中心后寄存在名为「公告」的栏目下，该栏目里的 `is_hot` 在业务上
 * 就是「置顶」（公开接口把它映射回 `is_top`）。文案必须按栏目语境显示，
 * 否则运营无从判断这条公告会不会被顶到最前。
 */
export const ANNOUNCEMENT_CHANNEL_NAME = '公告'

/** 标记文案：公告栏目叫「置顶」，其他栏目叫「热门」 */
export function hotLabelFor(categoryName: string | null | undefined): string {
  return categoryName === ANNOUNCEMENT_CHANNEL_NAME ? '置顶' : '热门'
}
