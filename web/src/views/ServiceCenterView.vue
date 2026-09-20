<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  ChevronRight, HelpCircle, LifeBuoy, RotateCcw, Search, Truck,
} from 'lucide-vue-next'
import {
  getFaqArticles, getTickets,
  type FaqArticle, type TicketListItem,
} from '@/api/cs'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useFaqStore } from '@/stores/faq'

/**
 * 服务中心首页（CS-111）
 *
 * 用户端「客户服务」统一入口：搜索 → 快捷工具 → 热门问题 → 帮助分类 → 最近工单。
 * 区块独立降级：任一接口失败仅该区块展示重试，不影响整页（AC-111.2）。
 */
const router = useRouter()

const loading = ref(true)
const searchKeyword = ref('')

const hotFaq = ref<FaqArticle[]>([])
const tickets = ref<TicketListItem[]>([])
const faqError = ref(false)
const ticketError = ref(false)

/**
 * 分类区读取共享的栏目树（CMS-201）
 * 与分类页/列表页共用同一个 store，用户从服务中心点进帮助中心不会重复拉一次分类。
 */
const faqStore = useFaqStore()
const categories = computed(() => faqStore.tree)

/** 快捷工具：查物流 / 申请退换货 / 联系客服 */
const quickTools = [
  { key: 'logistics', label: '查物流', icon: Truck, to: '/orders' },
  { key: 'refund', label: '申请退换货', icon: RotateCcw, to: '/orders?tab=after_sale' },
  { key: 'contact', label: '联系客服', icon: LifeBuoy, to: '/service-center/tickets/new' },
]

async function loadHot() {
  faqError.value = false
  try {
    const { data } = await getFaqArticles({ per_page: 5 })
    hotFaq.value = data.data.list
  } catch {
    faqError.value = true
  }
}

async function loadCategories() {
  try {
    await faqStore.loadTree()
  } catch {
    /* 分类失败不阻断，分类区留空 */
  }
}

async function loadTickets() {
  ticketError.value = false
  try {
    const { data } = await getTickets({ per_page: 3 })
    tickets.value = data.data.list.slice(0, 3)
  } catch {
    ticketError.value = true
  }
}

async function loadAll() {
  loading.value = true
  await Promise.all([loadHot(), loadCategories(), loadTickets()])
  loading.value = false
}

function goSearch() {
  const kw = searchKeyword.value.trim()
  router.push({ path: '/service-center/faq/list', query: kw ? { keyword: kw } : {} })
}

function openTool(to: string) {
  router.push(to)
}

onMounted(loadAll)
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-4xl flex-1 px-4 py-6 sm:px-6" data-testid="service-center">
      <!-- 搜索区 -->
      <section class="mb-5 rounded-xl bg-gradient-to-r from-[#1677ff] to-[#4096ff] p-5 text-white sm:p-7">
        <h1 class="text-lg font-bold sm:text-xl">客户服务中心</h1>
        <p class="mt-1 text-xs text-white/80 sm:text-sm">遇到问题？先搜一搜，多数答案都在这里</p>
        <div class="mt-4 flex items-center gap-2 rounded-full bg-white p-1 pl-4">
          <Search class="h-4 w-4 shrink-0 text-slate-400" />
          <input
            v-model="searchKeyword"
            type="text"
            placeholder="搜索常见问题，如：如何退货"
            class="min-w-0 flex-1 bg-transparent py-2 text-sm text-slate-700 outline-none"
            data-testid="service-search-input"
            @keyup.enter="goSearch"
          />
          <button
            class="shrink-0 rounded-full bg-[#1677ff] px-5 py-2 text-sm text-white hover:bg-[#4096ff]"
            data-testid="service-search-btn"
            @click="goSearch"
          >搜索</button>
        </div>
      </section>

      <LoadingSpinner v-if="loading" />

      <template v-else>
        <!-- 快捷工具 -->
        <section class="mb-5 rounded-xl bg-white p-4 sm:p-5" data-testid="quick-tools">
          <div class="grid grid-cols-3 gap-2">
            <button
              v-for="t in quickTools" :key="t.key"
              class="flex flex-col items-center gap-2 rounded-lg py-4 text-slate-600 transition-colors hover:bg-slate-50 hover:text-[#1677ff]"
              :data-testid="`quick-${t.key}`"
              @click="openTool(t.to)"
            >
              <component :is="t.icon" class="h-6 w-6 text-[#1677ff]" />
              <span class="text-xs sm:text-sm">{{ t.label }}</span>
            </button>
          </div>
        </section>

        <!-- 热门问题 -->
        <section class="mb-5 rounded-xl bg-white p-4 sm:p-5" data-testid="hot-faq">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="flex items-center gap-1.5 text-sm font-semibold text-slate-700">
              <HelpCircle class="h-4 w-4 text-[#1677ff]" /> 热门问题
            </h2>
            <button class="flex items-center text-xs text-slate-400 hover:text-[#1677ff]" data-testid="more-faq" @click="router.push('/service-center/faq')">
              全部帮助 <ChevronRight class="h-3.5 w-3.5" />
            </button>
          </div>
          <p v-if="faqError" class="py-6 text-center text-xs text-slate-400" data-testid="faq-error">
            加载失败，<button class="text-[#1677ff]" @click="loadHot">重试</button>
          </p>
          <p v-else-if="!hotFaq.length" class="py-6 text-center text-xs text-slate-400" data-testid="faq-empty">暂无常见问题</p>
          <ul v-else class="divide-y divide-slate-100">
            <li v-for="a in hotFaq" :key="a.id">
              <button
                class="flex w-full items-center justify-between gap-2 py-3 text-left text-sm text-slate-600 hover:text-[#1677ff]"
                :data-testid="`hot-faq-${a.id}`"
                @click="router.push(`/service-center/faq/${a.id}`)"
              >
                <span class="min-w-0 flex-1 truncate">{{ a.title }}</span>
                <span v-if="a.is_hot" class="shrink-0 rounded bg-[#fff1f0] px-1.5 py-0.5 text-[10px] text-[#ff4d4f]">热门</span>
                <ChevronRight class="h-3.5 w-3.5 shrink-0 text-slate-300" />
              </button>
            </li>
          </ul>
        </section>

        <!-- 帮助分类 -->
        <section v-if="categories.length" class="mb-5 rounded-xl bg-white p-4 sm:p-5" data-testid="faq-categories">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">帮助分类</h2>
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <button
              v-for="c in categories" :key="c.id"
              class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-3 text-left text-[13px] text-slate-700 transition-colors hover:border-[#1677ff] hover:text-[#1677ff]"
              :data-testid="`faq-category-${c.id}`"
              @click="router.push({ path: '/service-center/faq/list', query: { category_id: c.id } })"
            >
              <span class="min-w-0 truncate">{{ c.name }}</span>
              <span class="shrink-0 text-xs text-slate-400">{{ c.published_count }}</span>
            </button>
          </div>
        </section>

        <!-- 最近工单 -->
        <section class="rounded-xl bg-white p-4 sm:p-5" data-testid="recent-tickets">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">我的工单</h2>
            <button class="flex items-center text-xs text-slate-400 hover:text-[#1677ff]" data-testid="view-all-tickets" @click="router.push('/service-center/tickets')">
              查看全部 <ChevronRight class="h-3.5 w-3.5" />
            </button>
          </div>
          <p v-if="ticketError" class="py-6 text-center text-xs text-slate-400" data-testid="ticket-error">
            加载失败，<button class="text-[#1677ff]" @click="loadTickets">重试</button>
          </p>
          <p v-else-if="!tickets.length" class="py-8 text-center text-xs text-slate-400" data-testid="ticket-empty">
            暂无工单，有问题可<button class="text-[#1677ff]" @click="router.push('/service-center/tickets/new')">提交工单</button>
          </p>
          <ul v-else class="divide-y divide-slate-100">
            <li v-for="t in tickets" :key="t.id">
              <button
                class="flex w-full items-center justify-between gap-3 py-3 text-left"
                :data-testid="`recent-ticket-${t.id}`"
                @click="router.push(`/service-center/tickets/${t.id}`)"
              >
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm text-slate-700">{{ t.title }}</span>
                  <span class="mt-0.5 block text-xs text-slate-400">{{ t.ticket_no }} · {{ t.type_name }}</span>
                </span>
                <span class="shrink-0 rounded-full bg-[#e6f4ff] px-2 py-0.5 text-xs text-[#1677ff]">{{ t.status_label }}</span>
              </button>
            </li>
          </ul>
        </section>
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
