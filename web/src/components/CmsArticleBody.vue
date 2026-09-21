<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ShoppingBag } from 'lucide-vue-next'
import type { NewsProduct } from '@/api/news'

/**
 * CMS 正文渲染（文章正文 + 内联商品卡）
 *
 * 正文 HTML 由后端渲染并按白名单净化，这里只负责两件事：
 * 1. 按商品卡占位把正文切成「HTML 片段 / 真卡片」交错的分段；
 * 2. 卡片用**实时**商品数据渲染（正文里只存位置，不存卡片 HTML，所以改价立刻生效）。
 *
 * 为什么是切分而不是「v-html 之后去改 DOM」：卡片是真正的 Vue 节点（可点、可测），
 * 也不用和生产 DOM 抢所有权。占位一定落在顶层块之间（后端只认「独占一段」的标记），
 * 所以切分不会切坏 `<ul>`/`<blockquote>` 这类嵌套结构。
 *
 * `products` 为空时占位会被**整段摘掉**（连回退文案一起），不留「［商品卡］」空壳 ——
 * 帮助中心等不支持内联卡的场景传空数组即可安全退化。
 */
const props = defineProps<{
  /** 后端渲染 + 净化后的正文 HTML */
  content: string
  /** 正文里出现的商品（id 为 public_id，与占位容器 id 同一口径）；未传则不渲染卡片 */
  products?: NewsProduct[]
}>()

const router = useRouter()

/** 占位容器 id 前缀（与后端 `App\Support\ProductEmbed` 同一口径） */
const EMBED_ID_PREFIX = 'news-product-'
/** 只认 id；属性允许后端后续追加，故标签其余部分放宽 */
const EMBED_PLACEHOLDER = new RegExp(`<div id="${EMBED_ID_PREFIX}([A-Za-z0-9]+)"[^>]*>[^<]*</div>`, 'g')

type ContentSegment =
  | { kind: 'html'; html: string }
  | { kind: 'product'; product: NewsProduct }

const segments = computed<ContentSegment[]>(() => {
  const html = props.content ?? ''
  const products = new Map((props.products ?? []).map((p) => [p.id, p]))
  const result: ContentSegment[] = []
  let cursor = 0

  for (const match of html.matchAll(EMBED_PLACEHOLDER)) {
    const product = products.get(match[1])
    const at = match.index ?? 0

    // 先把占位之前的 HTML 落成一段（空片段不推，避免多出空 div）
    if (at > cursor) result.push({ kind: 'html', html: html.slice(cursor, at) })

    // 取不到商品数据时只跳过卡片本身；游标照常推到占位之后，占位与回退文案一并被摘掉
    if (product) result.push({ kind: 'product', product })

    cursor = at + match[0].length
  }

  if (cursor < html.length) result.push({ kind: 'html', html: html.slice(cursor) })

  return result
})
</script>

<template>
  <template v-for="(seg, i) in segments" :key="i">
    <button
      v-if="seg.kind === 'product'"
      type="button"
      class="my-3 flex w-full items-center gap-3 overflow-hidden rounded-lg border border-slate-100 bg-white p-2 text-left transition-shadow hover:shadow-sm"
      :data-testid="`cms-inline-product-${seg.product.id}`"
      @click="router.push(`/product/${seg.product.id}`)"
    >
      <img v-if="seg.product.main_image" :src="seg.product.main_image" alt="" class="h-16 w-16 shrink-0 rounded object-cover" />
      <span class="min-w-0 flex-1">
        <span class="line-clamp-2 block text-xs text-slate-700">{{ seg.product.title }}</span>
        <span v-if="seg.product.subtitle" class="mt-0.5 line-clamp-1 block text-xs text-slate-400">{{ seg.product.subtitle }}</span>
        <span class="mt-1 block text-xs font-medium text-[#ff4d4f]">¥{{ seg.product.price }}</span>
      </span>
      <ShoppingBag class="h-4 w-4 shrink-0 text-[#1677ff]" />
    </button>
    <!-- v-html 安全：正文来自后端 HtmlSanitizer 白名单净化（唯一写入入口） -->
    <div v-else v-html="seg.html" />
  </template>
</template>
