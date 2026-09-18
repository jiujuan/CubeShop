<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronRight, Megaphone } from 'lucide-vue-next'
import { getAnnouncements, type AnnouncementListItem } from '@/api/announcement'
import type { PublicPagination } from '@/api/types'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 公告列表（P-Announcement，公开）
 *
 * 展示前台可见公告（已发布 + 到达发布时间），按置顶优先、发布时间倒序；
 * 分页用 has_more 累加加载（SEC-04 公开资源不暴露精确总量）。
 */
const router = useRouter()

const items = ref<AnnouncementListItem[]>([])
const pagination = ref<PublicPagination>({ page: 1, page_size: 20, total: null, total_pages: null, has_more: false })
const loading = ref(true)
const loadingMore = ref(false)

const hasMore = computed(() => pagination.value.has_more)

async function fetchPage(page: number) {
  const { data } = await getAnnouncements({ page, per_page: 20 })
  items.value = page === 1 ? data.data.list : [...items.value, ...data.data.list]
  pagination.value = data.data.pagination
}

async function reload() {
  loading.value = true
  try {
    await fetchPage(1)
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  if (!hasMore.value || loadingMore.value) return
  loadingMore.value = true
  try {
    await fetchPage(pagination.value.page + 1)
  } finally {
    loadingMore.value = false
  }
}

onMounted(reload)
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 sm:px-6" data-testid="announcement-list">
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/')">首页</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">公告</span>
      </nav>

      <h1 class="mb-4 flex items-center gap-2 text-lg font-bold text-slate-800">
        <Megaphone class="h-5 w-5 text-[#1677ff]" /> 公告
      </h1>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="!items.length" class="rounded-xl bg-white py-16 text-center">
        <Megaphone class="mx-auto mb-3 h-10 w-10 text-slate-200" />
        <p class="text-sm text-slate-500">暂无公告</p>
      </div>

      <div v-else class="space-y-3">
        <button
          v-for="a in items" :key="a.id"
          class="block w-full rounded-xl bg-white px-4 py-4 text-left transition-shadow hover:shadow-sm"
          :data-testid="`announcement-${a.id}`"
          @click="router.push(`/announcements/${a.id}`)"
        >
          <div class="flex items-center gap-2">
            <span v-if="a.is_top" class="rounded bg-[#eaf4ff] px-1.5 py-0.5 text-xs text-[#1677ff]">置顶</span>
            <p class="min-w-0 flex-1 truncate text-sm font-medium text-slate-700">{{ a.title }}</p>
            <span class="shrink-0 text-xs text-slate-400">{{ a.published_at?.slice(0, 10) }}</span>
          </div>
          <p v-if="a.summary" class="mt-1 line-clamp-2 text-xs text-slate-400">{{ a.summary }}</p>
        </button>

        <div v-if="hasMore" class="pt-2 text-center">
          <button
            class="rounded-full border border-slate-200 bg-white px-6 py-2 text-sm text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff] disabled:opacity-50"
            :disabled="loadingMore"
            data-testid="announcement-load-more"
            @click="loadMore"
          >{{ loadingMore ? '加载中…' : '加载更多' }}</button>
        </div>
      </div>
    </main>

    <ShopFooter />
  </div>
</template>
