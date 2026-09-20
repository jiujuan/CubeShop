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

/**
 * 文章状态选项（与后端 `CsFaqArticle::STATUS_*` 同一口径）
 *
 * `hint` 是前台可见性说明——运营最常踩的坑就是存了草稿以为已经上线，
 * 所以下拉里必须把「前台可不可见」写在选项上，而不是靠列表页的徽标事后发现。
 */
export const ARTICLE_STATUS_OPTIONS = [
  { value: 'draft', label: '草稿', hint: '前台不可见' },
  { value: 'published', label: '已发布', hint: '前台立即可见' },
  { value: 'offline', label: '已下架', hint: '前台不可见' },
] as const

export type ArticleStatus = (typeof ARTICLE_STATUS_OPTIONS)[number]['value']

/** 列表徽标用的短标签（不含可见性提示） */
export const ARTICLE_STATUS_LABELS: Record<string, string> = Object.fromEntries(
  ARTICLE_STATUS_OPTIONS.map((o) => [o.value, o.label]),
)
