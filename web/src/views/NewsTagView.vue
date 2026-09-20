<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronLeft, ChevronRight, Tag } from 'lucide-vue-next'
import { getNewsArticles, type NewsListItem } from '@/api/news'
import type { PublicPagination } from '@/api/types'
import { applySeo } from '@/composables/useSeo'
import { BRAND_PLACEHOLDER } from '@/stores/site'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 新闻专题页（按标签聚合，公开）
 *
 * 后期增强（§7）：标签/专题。标签来自文章 `tags` 字段（JSON 数组），
 * 本页按 tag 过滤新闻列表并分页，是标签云 / 详情页标签的落地页。
 */
const route = useRoute()
const router = useRouter()

const tag = computed(() => decodeURIComponent(String(route.params.tag ?? '')))
const items = ref<NewsListItem[]>([])
const pagination = ref<PublicPagination>({ page: 1, page_size: 10, total: null, total_pages: null, has_more: false })
const loading = ref(true)
const loadingPage = ref(false)
const hasMore = computed(() => pagination.value.has_more)

function goDetail(a: NewsListItem) {
  router.push(`/news/${a.slug ?? a.id}`)
}

async function load(page = 1) {
  if (page === 1) loading.value = true
  else loadingPage.value = true
  try {
    const { data } = await getNewsArticles({ tag: tag.value, page, per_page: 10 })
    items.value = data.data.list
    pagination.value = data.data.pagination
    if (page === 1) {
      applySeo({
        title: `#${tag.value} · 新闻中心 · ${BRAND_PLACEHOLDER}`,
        description: `新闻中心「${tag.value}」专题`,
      })
    }
  } catch {
    items.value = []
  } finally {
    loading.value = false
    loadingPage.value = false
  }
}

onMounted(() => load(1))
watch(tag, () => load(1))
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-4xl flex-1 px-4 py-6 sm:px-6" data-testid="news-tag">
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/')">首页</button>
        <ChevronRight class="h-3 w-3" />
        <button class="hover:text-[#1677ff]" @click="router.push('/news')">新闻中心</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600"># {{ tag }}</span>
      </nav>

      <h1 class="mb-4 flex items-center gap-2 text-lg font-bold text-slate-800">
        <Tag class="h-5 w-5 text-[#1677ff]" /> 专题：{{ tag }}
      </h1>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="!items.length" class="rounded-xl bg-white py-16 text-center" data-testid="news-tag-empty">
        <p class="text-sm text-slate-500">该标签下暂无新闻</p>
        <button class="mt-3 rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-500 hover:text-[#1677ff]" @click="router.push('/news')">返回新闻列表</button>
      </div>

      <div v-else class="space-y-3" data-testid="news-tag-rows">
        <button
          v-for="a in items" :key="a.id"
          class="flex w-full items-center gap-4 rounded-xl bg-white px-4 py-4 text-left transition-shadow hover:shadow-sm"
          :data-testid="`news-tag-row-${a.id}`"
          @click="goDetail(a)"
        >
          <img v-if="a.cover_image" :src="a.cover_image" alt="" class="h-16 w-16 shrink-0 rounded-md object-cover" />
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-slate-700">{{ a.title }}</p>
            <p v-if="a.summary" class="mt-1 line-clamp-1 text-xs text-slate-400">{{ a.summary }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ a.channel_name ?? '新闻' }} · {{ a.published_at?.slice(0, 10) }} · {{ a.view_count }} 阅读</p>
          </div>
        </button>
      </div>

      <div v-if="items.length && (pagination.page > 1 || hasMore)" class="mt-5 flex items-center justify-center gap-3">
        <button
          v-if="pagination.page > 1"
          class="flex items-center gap-1 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff]"
          data-testid="news-tag-prev"
          @click="load(pagination.page - 1)"
        ><ChevronLeft class="h-4 w-4" /> 上一页</button>
        <span class="text-xs text-slate-400">第 {{ pagination.page }} 页</span>
        <button
          v-if="hasMore"
          class="flex items-center gap-1 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff]"
          :disabled="loadingPage"
          data-testid="news-tag-next"
          @click="load(pagination.page + 1)"
        >下一页 <ChevronRight class="h-4 w-4" /></button>
      </div>
    </main>

    <ShopFooter />
  </div>
</template>
