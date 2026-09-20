import { BRAND_PLACEHOLDER, currentSiteName } from '@/stores/site'

/**
 * 页面级 SEO（CMS-202）
 *
 * SPA 里 `<title>` 与 `<meta>` 都是运行时写入的，天然有两个坑，这里一次收口：
 *
 * 1. **meta 残留**：路由切换不会自动清掉上一页写的 `description`/`keywords`，
 *    结果「关于我们」的描述会挂在商品列表页上。所以 `router.afterEach` 必须调
 *    `resetSeo()`，页面拿到数据后再 `applySeo()` 覆盖。
 * 2. **标题品牌占位**：站点名统一写占位符 `CubeShop`（见 stores/site.ts），
 *    这里沿用同一套替换逻辑，避免两处各自拼站点名。
 *
 * 只管理 title / description / keywords 三项 —— 与后台单页可维护的字段一一对应，
 * 不做 OG/Twitter 卡片（需要配图与站点维度配置，属后续独立任务）。
 */
export interface SeoInput {
  /** 显式标题（后台填写）。省略则保持路由 meta 决定的标题 */
  title?: string
  description?: string
  keywords?: string
}

function upsertMeta(name: 'description' | 'keywords', content: string) {
  const existing = document.head.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)

  if (!content) {
    // 空值 = 移除标签，而不是留一个 content="" —— 后者同样会被爬虫当成有效描述
    existing?.remove()
    return
  }

  const tag = existing ?? document.createElement('meta')
  tag.setAttribute('name', name)
  tag.setAttribute('content', content)
  if (!existing) document.head.appendChild(tag)
}

/** 写入当前页面的 SEO 三元组 */
export function applySeo(input: SeoInput) {
  if (input.title) {
    document.title = input.title.split(BRAND_PLACEHOLDER).join(currentSiteName())
  }

  upsertMeta('description', input.description ?? '')
  upsertMeta('keywords', input.keywords ?? '')
}

/**
 * 清空页面级 SEO（路由切换时调用）
 *
 * 标题不在这里清：`router.afterEach` 紧接着会用新路由的 meta.title 重设 document.title。
 */
export function resetSeo() {
  upsertMeta('description', '')
  upsertMeta('keywords', '')
}
