<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronLeft, ChevronRight, Flame, Newspaper, Tag } from 'lucide-vue-next'
import {
  getNewsArticles, getNewsChannels, getNewsHot, getNewsTags,
  type NewsChannel, type NewsChannelsResult, type NewsListItem, type NewsTag,
} from '@/api/news'
import type { PublicPagination } from '@/api/types'
import { applySeo } from '@/composables/useSeo'
import { BRAND_PLACEHOLDER } from '@/stores/site'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import AppImage from '@/components/AppImage.vue'

/**
 * 新闻中心列表（CMS 新闻中心，公开）
 *
 * - 顶部按「新闻中心」子栏目分 tab（图文新闻 / 列表新闻），加一个「全部」聚合；
 * - 各子栏目有自己的 list_style：card=图文卡片网格、list=列表行；「全部」默认 list 行；
 * - 右侧栏：热门排行（按浏览量）+ 标签云（点标签进专题页 /news/tag/:tag）（后期增强 §7）；
 * - 分页：后端是公开资源、不暴露精确总量（SEC-04），用 has_more 做上一页/下一页。
 * - 详情 URL：slug 优先、id 兜底（后期增强 §7）。
 */
const router = useRouter()

const root = ref<NewsChannelsResult['root']>(null)
const channels = ref<NewsChannel[]>([])
const activeChannelId = ref<number | null>(null)

const items = ref<NewsListItem[]>([])
const pagination = ref<PublicPagination>({ page: 1, page_size: 10, total: null, total_pages: null, has_more: false })
const loading = ref(true)
const loadingPage = ref(false)

const hot = ref<NewsListItem[]>([])
const tags = ref<NewsTag[]>([])

const activeChannel = computed(() => channels.value.find((c) => c.id === activeChannelId.value) ?? null)
const activeStyle = computed(() => activeChannel.value?.list_style ?? 'list')
const hasMore = computed(() => pagination.value.has_more)

async function fetchArticles(page = 1) {
  if (page === 1) loading.value = true
  else loadingPage.value = true
  try {
    const { data } = await getNewsArticles({
      channel_id: activeChannelId.value ?? undefined,
      page,
      per_page: 10,
    })
    items.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    if (page === 1) loading.value = false
    else loadingPage.value = false
  }
}

function goTab(channelId: number | null) {
  if (channelId === activeChannelId.value) return
  activeChannelId.value = channelId
  fetchArticles(1)
}

/** 详情 URL：slug 优先（语义化），无 slug 用 id */
function goDetail(a: NewsListItem) {
  router.push(`/news/${a.slug ?? a.id}`)
}

function goTag(tag: string) {
  router.push(`/news/tag/${encodeURIComponent(tag)}`)
}

async function loadChannels() {
  const { data } = await getNewsChannels()
  root.value = data.data.root
  channels.value = data.data.channels

  // CMS-202：首页级 SEO（根栏目 SEO 三列优先，缺则回落栏目名 + 品牌）
  if (root.value) {
    applySeo({
      title: root.value.seo_title ?? `${root.value.name} · ${BRAND_PLACEHOLDER}`,
      description: root.value.seo_description ?? undefined,
      keywords: root.value.seo_keywords ?? undefined,
    })
  }
}

async function loadSidebars() {
  try {
    const [hotRes, tagRes] = await Promise.all([getNewsHot({ limit: 5 }), getNewsTags()])
    hot.value = hotRes.data.data
    tags.value = tagRes.data.data.slice(0, 12)
  } catch {
    hot.value = []
    tags.value = []
  }
}

onMounted(async () => {
  try {
    await loadChannels()
  } catch {
    root.value = null
    channels.value = []
  }
  await Promise.all([fetchArticles(1), loadSidebars()])
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6" data-testid="news-list">
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/')">首页</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">{{ root?.name ?? '新闻中心' }}</span>
      </nav>

      <h1 class="mb-4 flex items-center gap-2 text-lg font-bold text-slate-800">
        <Newspaper class="h-5 w-5 text-[#1677ff]" /> {{ root?.name ?? '新闻中心' }}
      </h1>

      <div class="lg:grid lg:grid-cols-[1fr_280px] lg:gap-6">
        <div class="min-w-0">
          <!-- 子栏目 tab -->
          <div v-if="channels.length" class="mb-4 flex flex-wrap gap-2" data-testid="news-tabs">
            <button
              class="rounded-full px-4 py-1.5 text-sm transition-colors"
              :class="activeChannelId === null ? 'bg-[#1677ff] text-white' : 'bg-white text-slate-600 hover:text-[#1677ff]'"
              data-testid="news-tab-all"
              @click="goTab(null)"
            >全部</button>
            <button
              v-for="c in channels" :key="c.id"
              class="rounded-full px-4 py-1.5 text-sm transition-colors"
              :class="activeChannelId === c.id ? 'bg-[#1677ff] text-white' : 'bg-white text-slate-600 hover:text-[#1677ff]'"
              :data-testid="`news-tab-${c.id}`"
              @click="goTab(c.id)"
            >{{ c.name }}</button>
          </div>

          <LoadingSpinner v-if="loading" />

          <div v-else-if="!items.length" class="rounded-xl bg-white py-16 text-center" data-testid="news-empty">
            <Newspaper class="mx-auto mb-3 h-10 w-10 text-slate-200" />
            <p class="text-sm text-slate-500">暂无新闻</p>
          </div>

          <!-- 图文卡片形态（card） -->
          <div v-else-if="activeStyle === 'card'" class="grid grid-cols-1 gap-4 sm:grid-cols-2" data-testid="news-card-grid">
            <button
              v-for="a in items" :key="a.id"
              class="flex flex-col overflow-hidden rounded-xl bg-white text-left transition-shadow hover:shadow-sm"
              :data-testid="`news-card-${a.id}`"
              @click="goDetail(a)"
            >
              <AppImage v-if="a.cover_image" :src="a.cover_image" alt="" class="h-40 w-full object-cover" />
              <div class="flex-1 p-4">
                <p class="truncate text-sm font-medium text-slate-800">{{ a.title }}</p>
                <p v-if="a.summary" class="mt-1 line-clamp-2 text-xs text-slate-400">{{ a.summary }}</p>
                <p class="mt-2 text-xs text-slate-400">{{ a.published_at?.slice(0, 10) }} · {{ a.view_count }} 阅读</p>
              </div>
            </button>
          </div>

          <!-- 列表行形态（list） -->
          <div v-else class="space-y-3" data-testid="news-list-rows">
            <button
              v-for="a in items" :key="a.id"
              class="flex w-full items-center gap-4 rounded-xl bg-white px-4 py-4 text-left transition-shadow hover:shadow-sm"
              :data-testid="`news-row-${a.id}`"
              @click="goDetail(a)"
            >
              <AppImage v-if="a.cover_image" :src="a.cover_image" alt="" class="h-16 w-16 shrink-0 rounded-md object-cover" />
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-slate-700">{{ a.title }}</p>
                <p v-if="a.summary" class="mt-1 line-clamp-1 text-xs text-slate-400">{{ a.summary }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ a.channel_name ?? '新闻' }} · {{ a.published_at?.slice(0, 10) }} · {{ a.view_count }} 阅读</p>
              </div>
            </button>
          </div>

          <!-- 分页（上一页/下一页，依赖 has_more） -->
          <div v-if="items.length && (pagination.page > 1 || hasMore)" class="mt-5 flex items-center justify-center gap-3">
            <button
              v-if="pagination.page > 1"
              class="flex items-center gap-1 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff] disabled:opacity-40"
              data-testid="news-prev"
              @click="fetchArticles(pagination.page - 1)"
            ><ChevronLeft class="h-4 w-4" /> 上一页</button>
            <span class="text-xs text-slate-400">第 {{ pagination.page }} 页</span>
            <button
              v-if="hasMore"
              class="flex items-center gap-1 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff] disabled:opacity-40"
              :disabled="loadingPage"
              data-testid="news-next"
              @click="fetchArticles(pagination.page + 1)"
            >下一页 <ChevronRight class="h-4 w-4" /></button>
          </div>
        </div>

        <!-- 右侧栏：热门排行 + 标签云（后期增强 §7） -->
        <aside class="mt-6 lg:mt-0">
          <section v-if="hot.length" class="rounded-xl bg-white p-4" data-testid="news-hot">
            <h2 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
              <Flame class="h-4 w-4 text-[#ff6b35]" /> 热门排行
            </h2>
            <ol class="space-y-2">
              <li v-for="(a, i) in hot" :key="a.id">
                <button class="flex w-full items-start gap-2 text-left text-xs hover:text-[#1677ff]" :data-testid="`news-hot-${a.id}`" @click="goDetail(a)">
                  <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded text-[10px] font-medium" :class="i < 3 ? 'bg-[#ff6b35] text-white' : 'bg-slate-100 text-slate-500'">{{ i + 1 }}</span>
                  <span class="line-clamp-2 text-slate-600">{{ a.title }}</span>
                </button>
              </li>
            </ol>
          </section>

          <section v-if="tags.length" class="mt-4 rounded-xl bg-white p-4" data-testid="news-tags">
            <h2 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
              <Tag class="h-4 w-4 text-[#1677ff]" /> 标签
            </h2>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="t in tags" :key="t.tag"
                class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600 hover:bg-[#e6f4ff] hover:text-[#1677ff]"
                :data-testid="`news-tag-${t.tag}`"
                @click="goTag(t.tag)"
              >{{ t.tag }} ({{ t.count }})</button>
            </div>
          </section>
        </aside>
      </div>
    </main>

    <ShopFooter />
  </div>
</template>
