<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, FileText, Search } from 'lucide-vue-next'
import { getFaqArticles, type FaqArticle } from '@/api/cs'
import type { Pagination } from '@/api/types'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 帮助中心 · 文章列表 / 搜索结果（CS-112）
 *
 * 支持按分类过滤与关键词搜索；关键词命中标题/摘要时以 <mark> 高亮（不做 HTML 拼接，避免 XSS）。
 * 列表分页「加载更多」累加。
 */
const route = useRoute()
const router = useRouter()

const categoryId = ref<number | null>(
  route.query.category_id ? Number(route.query.category_id) : null,
)
const keyword = ref((route.query.keyword as string) ?? '')

const articles = ref<FaqArticle[]>([])
const pagination = ref<Pagination>({ page: 1, page_size: 10, total: 0, total_pages: 1 })
const loading = ref(true)
const loadingMore = ref(false)

const hasMore = computed(() => pagination.value.page < pagination.value.total_pages)

async function fetchPage(page: number) {
  const { data } = await getFaqArticles({
    category_id: categoryId.value ?? undefined,
    keyword: keyword.value.trim() || undefined,
    page,
    per_page: 10,
  })
  articles.value = page === 1 ? data.data.list : [...articles.value, ...data.data.list]
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

/** 搜索防抖 300ms：连续输入只触发一次请求（AC-112.2） */
let timer: ReturnType<typeof setTimeout> | null = null
function onKeywordInput() {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => {
    router.replace({ query: keyword.value.trim() ? { keyword: keyword.value.trim() } : {} })
    reload()
  }, 300)
}

/** 关键词分段高亮（仅文本节点，不拼接 HTML） */
function highlight(text: string | null): Array<{ text: string; hit: boolean }> {
  const full = text ?? ''
  const kw = keyword.value.trim()
  if (!kw) return [{ text: full, hit: false }]

  const segs: Array<{ text: string; hit: boolean }> = []
  const lower = full.toLowerCase()
  const lkw = kw.toLowerCase()
  let i = 0
  while (i < full.length) {
    const idx = lower.indexOf(lkw, i)
    if (idx === -1) {
      segs.push({ text: full.slice(i), hit: false })
      break
    }
    if (idx > i) segs.push({ text: full.slice(i, idx), hit: false })
    segs.push({ text: full.slice(idx, idx + kw.length), hit: true })
    i = idx + kw.length
  }
  return segs.length ? segs : [{ text: full, hit: false }]
}

function contactService() {
  router.push({ path: '/service-center/tickets/new', query: { title: keyword.value.trim() } })
}

onMounted(reload)
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 sm:px-6" data-testid="faq-list">
      <!-- 面包屑 -->
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/service-center')">服务中心</button>
        <ChevronRight class="h-3 w-3" />
        <button class="hover:text-[#1677ff]" @click="router.push('/service-center/faq')">帮助中心</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">文章列表</span>
      </nav>

      <div class="mb-4 flex items-center gap-2 rounded-full bg-white p-1 pl-4">
        <Search class="h-4 w-4 shrink-0 text-slate-400" />
        <input
          v-model="keyword"
          type="text"
          placeholder="搜索常见问题"
          class="min-w-0 flex-1 bg-transparent py-2 text-sm text-slate-700 outline-none"
          data-testid="faq-search-input"
          @input="onKeywordInput"
        />
      </div>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="!articles.length" class="rounded-xl bg-white py-16 text-center" data-testid="faq-empty">
        <FileText class="mx-auto mb-3 h-10 w-10 text-slate-200" />
        <p class="text-sm text-slate-500">没找到答案？</p>
        <button class="mt-3 rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]" data-testid="faq-contact-service" @click="contactService">
          联系客服
        </button>
      </div>

      <div v-else class="space-y-3">
        <button
          v-for="a in articles" :key="a.id"
          class="block w-full rounded-xl bg-white px-4 py-4 text-left transition-shadow hover:shadow-sm"
          :data-testid="`faq-article-${a.id}`"
          @click="router.push(`/service-center/faq/${a.id}`)"
        >
          <p class="text-sm font-medium text-slate-700">
            <template v-for="(seg, i) in highlight(a.title)" :key="i">
              <mark v-if="seg.hit" class="bg-yellow-200 text-slate-800">{{ seg.text }}</mark>
              <template v-else>{{ seg.text }}</template>
            </template>
          </p>
          <p v-if="a.summary" class="mt-1 line-clamp-2 text-xs text-slate-400">
            <template v-for="(seg, i) in highlight(a.summary)" :key="i">
              <mark v-if="seg.hit" class="bg-yellow-200 text-slate-600">{{ seg.text }}</mark>
              <template v-else>{{ seg.text }}</template>
            </template>
          </p>
        </button>

        <div v-if="hasMore" class="pt-2 text-center">
          <button class="rounded-full border border-slate-200 bg-white px-6 py-2 text-sm text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff] disabled:opacity-50" :disabled="loadingMore" data-testid="faq-load-more" @click="loadMore">
            {{ loadingMore ? '加载中…' : '加载更多' }}
          </button>
        </div>
      </div>
    </main>

    <ShopFooter />
  </div>
</template>
