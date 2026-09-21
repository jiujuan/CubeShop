<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, Eye, ShoppingBag } from 'lucide-vue-next'
import { getNewsDetail, type NewsDetailResult } from '@/api/news'
import { applySeo } from '@/composables/useSeo'
import { BRAND_PLACEHOLDER, currentSiteName } from '@/stores/site'
import CmsArticleBody from '@/components/CmsArticleBody.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 新闻详情（CMS 新闻中心，公开）
 *
 * 正文 `content` 是后端渲染 + 净化后的安全 HTML（MarkdownRenderer → HtmlSanitizer），
 * 用 `.cms-prose` 全局类排版（净化器不放行 style/class，样式必须前端补齐）。
 * D8：附 Article 结构化数据（JSON-LD），利于搜索引擎收录。
 * 后期增强（§7）：slug 语义化 URL、文章级 SEO 三列、标签、关联种草商品。
 */
const route = useRoute()
const router = useRouter()

/** 路由 key：slug 或数字 id（后端 slug 优先、id 兜底） */
const key = computed(() => String(route.params.id))
const loading = ref(true)
const notFound = ref(false)
const data = ref<NewsDetailResult | null>(null)

const article = computed(() => data.value?.article ?? null)
const channelName = computed(() => article.value?.category?.name ?? '')
const tags = computed(() => article.value?.tags ?? [])
const embeddedProducts = computed(() => data.value?.embedded_products ?? [])

/** D8：Article JSON-LD（随详情数据生成；空则渲染空串，不挂垃圾标签） */
const jsonLd = computed(() => {
  const a = article.value
  if (!a) return ''
  const url = typeof window !== 'undefined' ? window.location.href : ''
  const ld: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'Article',
    headline: a.title,
    datePublished: a.published_at,
    dateModified: a.updated_at,
    publisher: { '@type': 'Organization', name: currentSiteName() },
  }
  if (a.cover_image) ld.image = [a.cover_image]
  if (url) ld.mainEntityOfPage = { '@type': 'WebPage', '@id': url }
  const desc = a.seo_description ?? a.summary
  if (desc) ld.description = desc
  if (tags.value.length) ld.keywords = tags.value.join(',')
  return JSON.stringify(ld)
})

/** D8：把 Article JSON-LD 注入 <head>（SFC 模板不允许直接写 <script>，故命令式注入） */
function injectJsonLd() {
  document.getElementById('news-jsonld')?.remove()
  const raw = jsonLd.value
  if (!raw) return
  const s = document.createElement('script')
  s.type = 'application/ld+json'
  s.id = 'news-jsonld'
  s.textContent = raw
  document.head.appendChild(s)
}

function removeJsonLd() {
  document.getElementById('news-jsonld')?.remove()
}

/** 详情 URL：slug 优先，无则 id */
function goNews(id: number, slug: string | null) {
  router.push(`/news/${slug ?? id}`)
}

async function load() {
  loading.value = true
  notFound.value = false
  try {
    const { data: res } = await getNewsDetail(key.value)
    data.value = res.data
    const a = res.data.article
    // 后期增强：文章级 SEO 三列优先，缺则回落标题 + 摘要
    applySeo({
      title: a.seo_title ?? `${a.title} · ${BRAND_PLACEHOLDER}`,
      description: a.seo_description ?? a.summary ?? undefined,
      keywords: a.seo_keywords ?? undefined,
    })
    injectJsonLd()
  } catch {
    data.value = null
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
onBeforeUnmount(removeJsonLd)
watch(() => route.params.id, (val, old) => {
  if (val !== old && route.name === 'news-detail') {
    removeJsonLd()
    load()
  }
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 sm:px-6" data-testid="news-detail">
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/')">首页</button>
        <ChevronRight class="h-3 w-3" />
        <button class="hover:text-[#1677ff]" @click="router.push('/news')">新闻中心</button>
        <template v-if="channelName">
          <ChevronRight class="h-3 w-3" />
          <span class="text-slate-600">{{ channelName }}</span>
        </template>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">详情</span>
      </nav>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="notFound || !article" class="rounded-xl bg-white py-20 text-center" data-testid="news-not-found">
        <p class="text-sm text-slate-500">文章不存在或已下架</p>
        <button class="mt-3 rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-500 hover:text-[#1677ff]" @click="router.push('/news')">返回新闻列表</button>
      </div>

      <article v-else class="rounded-xl bg-white p-5 sm:p-7" data-testid="news-article">
        <h1 class="text-lg font-bold text-slate-800" data-testid="news-title">{{ article.title }}</h1>
        <p class="mt-2 flex items-center gap-3 text-xs text-slate-400">
          <span v-if="channelName" class="text-[#1677ff]">{{ channelName }}</span>
          <span class="inline-flex items-center gap-1"><Eye class="h-3.5 w-3.5" /> {{ article.view_count }} 阅读</span>
          <span v-if="article.published_at">发布于 {{ article.published_at.slice(0, 10) }}</span>
        </p>

        <!-- 标签（点标签进专题页） -->
        <div v-if="tags.length" class="mt-3 flex flex-wrap gap-2" data-testid="news-detail-tags">
          <button
            v-for="t in tags" :key="t"
            class="rounded-full bg-slate-100 px-3 py-0.5 text-xs text-slate-600 hover:bg-[#e6f4ff] hover:text-[#1677ff]"
            @click="router.push(`/news/tag/${encodeURIComponent(t)}`)"
          ># {{ t }}</button>
        </div>

        <img v-if="article.cover_image" :src="article.cover_image" alt="" class="mt-4 max-h-72 w-full rounded-lg object-cover" data-testid="news-cover" />

        <!--
          正文交给共享的 CmsArticleBody：HTML 片段 v-html，内联商品卡渲染成真组件
          （卡片用接口的实时数据渲染，所以改价后正文里的卡片立刻跟着变）。
        -->
        <div class="cms-prose mt-5 break-words text-sm leading-7 text-slate-700" data-testid="news-content">
          <CmsArticleBody :content="article.content" :products="embeddedProducts" />
        </div>

        <!-- 底部「相关商品」：作者没插进正文的那些（插进正文的不在这里重复） -->
        <section v-if="data?.products?.length" class="mt-8 border-t border-slate-100 pt-5" data-testid="news-products">
          <h2 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
            <ShoppingBag class="h-4 w-4 text-[#1677ff]" /> 相关商品
          </h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <button
              v-for="p in data.products" :key="p.id"
              class="overflow-hidden rounded-lg border border-slate-100 bg-white text-left hover:shadow-sm"
              :data-testid="`news-product-${p.id}`"
              @click="router.push(`/product/${p.id}`)"
            >
              <img v-if="p.main_image" :src="p.main_image" alt="" class="h-28 w-full object-cover" />
              <div class="p-2">
                <p class="line-clamp-2 text-xs text-slate-700">{{ p.title }}</p>
                <p class="mt-1 text-xs font-medium text-[#ff4d4f]">¥{{ p.price }}</p>
              </div>
            </button>
          </div>
        </section>

        <!-- 上一篇 / 下一篇 -->
        <div v-if="data?.prev || data?.next" class="mt-8 grid grid-cols-2 gap-3 border-t border-slate-100 pt-5 text-sm">
          <button
            v-if="data?.prev"
            class="truncate text-left text-slate-500 hover:text-[#1677ff]"
            data-testid="news-prev-link"
            @click="goNews(data.prev!.id, data.prev!.slug)"
          >← {{ data.prev.title }}</button>
          <span v-else />
          <button
            v-if="data?.next"
            class="truncate text-right text-slate-500 hover:text-[#1677ff]"
            data-testid="news-next-link"
            @click="goNews(data.next!.id, data.next!.slug)"
          >{{ data.next.title }} →</button>
        </div>
      </article>

      <!-- 相关新闻 -->
      <section v-if="data?.related?.length" class="mt-6">
        <h2 class="mb-3 text-sm font-semibold text-slate-700">相关新闻</h2>
        <div class="space-y-2">
          <button
            v-for="r in data.related" :key="r.id"
            class="flex w-full items-center gap-3 rounded-lg bg-white px-4 py-3 text-left text-sm hover:shadow-sm"
            :data-testid="`news-related-${r.id}`"
            @click="goNews(r.id, r.slug)"
          >
            <img v-if="r.cover_image" :src="r.cover_image" alt="" class="h-12 w-12 shrink-0 rounded object-cover" />
            <div class="min-w-0 flex-1">
              <p class="truncate text-slate-700">{{ r.title }}</p>
              <p class="mt-0.5 text-xs text-slate-400">{{ r.published_at?.slice(0, 10) }}</p>
            </div>
          </button>
        </div>
      </section>
    </main>

    <ShopFooter />
  </div>
</template>
