<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronLeft, ChevronRight, Newspaper } from 'lucide-vue-next'
import {
  getNewsArticles, getNewsChannels, type NewsChannel, type NewsChannelsResult,
} from '@/api/news'
import type { PublicPagination } from '@/api/types'
import { applySeo } from '@/composables/useSeo'
import { BRAND_PLACEHOLDER } from '@/stores/site'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 新闻中心列表（CMS 新闻中心，一期，公开）
 *
 * - 顶部按「新闻中心」子栏目分 tab（图文新闻 / 列表新闻），加一个「全部」聚合；
 * - 各子栏目有自己的 list_style：card=图文卡片网格、list=列表行；
 *   「全部」没有独立形态，默认用 list 行样式渲染。
 * - 分页：后端是公开资源、不暴露精确总量（SEC-04），用 has_more 做上一页/下一页。
 */
const router = useRouter()

const root = ref<NewsChannelsResult['root']>(null)
const channels = ref<NewsChannel[]>([])
const activeChannelId = ref<number | null>(null)

const items = ref<import('@/api/news').NewsListItem[]>([])
const pagination = ref<PublicPagination>({ page: 1, page_size: 10, total: null, total_pages: null, has_more: false })
const loading = ref(true)
const loadingPage = ref(false)

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

function goDetail(id: number) {
  router.push(`/news/${id}`)
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

onMounted(async () => {
  try {
    await loadChannels()
  } catch {
    root.value = null
    channels.value = []
  }
  await fetchArticles(1)
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-4xl flex-1 px-4 py-6 sm:px-6" data-testid="news-list">
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/')">首页</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">{{ root?.name ?? '新闻中心' }}</span>
      </nav>

      <h1 class="mb-4 flex items-center gap-2 text-lg font-bold text-slate-800">
        <Newspaper class="h-5 w-5 text-[#1677ff]" /> {{ root?.name ?? '新闻中心' }}
      </h1>

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
          @click="goDetail(a.id)"
        >
          <img v-if="a.cover_image" :src="a.cover_image" alt="" class="h-40 w-full object-cover" />
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
          @click="goDetail(a.id)"
        >
          <img v-if="a.cover_image" :src="a.cover_image" alt="" class="h-16 w-16 shrink-0 rounded-md object-cover" />
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
    </main>

    <ShopFooter />
  </div>
</template>
