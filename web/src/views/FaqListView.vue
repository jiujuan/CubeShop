<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, FileText, ListTree, Search } from 'lucide-vue-next'
import { getFaqArticles, type FaqArticle } from '@/api/cs'
import type { Pagination } from '@/api/types'
import FaqBreadcrumb from '@/components/FaqBreadcrumb.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { applySeo } from '@/composables/useSeo'
import { useFaqStore } from '@/stores/faq'

/**
 * 帮助中心 · 文章列表 / 搜索结果（CS-112 / CMS-201）
 *
 * CMS-201 新增：左侧栏目树侧栏（`lg` 起常驻，窄屏折叠为「全部分类」按钮），
 * 面包屑按栏目链路渲染。栏目树来自 `useFaqStore`（单一真源）。
 *
 * 路由 query 是「当前筛选条件」的唯一真源：`category_id` 变化由 watch 驱动重新拉取，
 * 这样从面包屑/侧栏跳回本页（同路由不同 query，组件不会重建）也能正确刷新。
 * 关键词走本地输入 + 300ms 防抖（连续输入只触发一次请求）。
 */
const route = useRoute()
const router = useRouter()
const faq = useFaqStore()

const categoryId = ref<number | null>(
  route.query.category_id ? Number(route.query.category_id) : null,
)
const keyword = ref((route.query.keyword as string) ?? '')

const articles = ref<FaqArticle[]>([])
const pagination = ref<Pagination>({ page: 1, page_size: 10, total: 0, total_pages: 1 })
const loading = ref(true)
const loadingMore = ref(false)
/** 窄屏下栏目树是否展开 */
const treeOpen = ref(false)

const hasMore = computed(() => pagination.value.page < pagination.value.total_pages)
const currentCategory = computed(() => faq.findById(categoryId.value))

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
    router.replace({
      query: {
        ...route.query,
        keyword: keyword.value.trim() || undefined,
      },
    })
    reload()
  }, 300)
}

/** 侧栏/面包屑切换栏目：只改 query，由 watch 统一驱动刷新（避免双重请求） */
function goCategory(id: number | null) {
  treeOpen.value = false
  router.replace({
    query: id === null ? {} : { category_id: String(id) },
  })
}

watch(
  () => route.query.category_id,
  (next) => {
    const id = next ? Number(next) : null
    if (id === categoryId.value) return
    categoryId.value = id
    keyword.value = (route.query.keyword as string) ?? ''
    reload()
  },
)

/**
 * CMS-202：页面标题按当前栏目细化
 *
 * 只写 title 不写 description —— 列表页没有「一句真实的描述」可写，
 * 编一句泛泛的 description 对搜索排名是负收益（搜索引擎自己生成更好）。
 */
watch(
  currentCategory,
  (category) => applySeo({ title: category ? `${category.name} · 帮助中心` : '帮助中心' }),
  { immediate: true },
)

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

onMounted(() => {
  faq.loadTree()
  reload()
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-6 sm:px-6" data-testid="faq-list">
      <FaqBreadcrumb :category-id="categoryId" leaf="文章列表" />

      <div class="lg:grid lg:grid-cols-[220px_minmax(0,1fr)] lg:items-start lg:gap-5">
        <!-- 栏目树侧栏：lg 起常驻，窄屏折叠 -->
        <aside class="mb-4 lg:sticky lg:top-4 lg:mb-0" data-testid="faq-sidebar">
          <button
            class="flex w-full items-center justify-between rounded-xl bg-white px-4 py-3 text-sm text-slate-600 lg:hidden"
            data-testid="faq-tree-toggle"
            @click="treeOpen = !treeOpen"
          >
            <span class="flex items-center gap-2">
              <ListTree class="h-4 w-4 text-slate-400" />
              {{ currentCategory?.name ?? '全部分类' }}
            </span>
            <ChevronRight class="h-4 w-4 text-slate-300 transition-transform" :class="treeOpen ? 'rotate-90' : ''" />
          </button>

          <nav class="mt-2 rounded-xl bg-white p-2 lg:mt-0" :class="treeOpen ? 'block' : 'hidden lg:block'">
            <button
              class="flex w-full items-center rounded-md px-2 py-2 text-left text-[13px] transition-colors"
              :class="categoryId === null ? 'bg-[#e6f4ff] font-medium text-[#1677ff]' : 'text-slate-600 hover:bg-slate-50'"
              data-testid="faq-tree-all"
              @click="goCategory(null)"
            >
              全部分类
            </button>
            <button
              v-for="row in faq.flat" :key="row.node.id"
              class="flex w-full items-center justify-between gap-1 rounded-md py-2 pr-2 text-left text-[13px] transition-colors"
              :class="categoryId === row.node.id ? 'bg-[#e6f4ff] font-medium text-[#1677ff]' : 'text-slate-600 hover:bg-slate-50'"
              :style="{ paddingLeft: `${8 + row.depth * 12}px` }"
              :data-testid="`faq-tree-${row.node.id}`"
              @click="goCategory(row.node.id)"
            >
              <span class="min-w-0 flex-1 truncate">{{ row.node.name }}</span>
              <span class="shrink-0 text-[11px] text-slate-400">{{ row.node.published_count }}</span>
            </button>
          </nav>
        </aside>

        <!-- 列表区 -->
        <div class="min-w-0">
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
        </div>
      </div>
    </main>

    <ShopFooter />
  </div>
</template>
