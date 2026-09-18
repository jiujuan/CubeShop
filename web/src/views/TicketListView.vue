<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronRight, Inbox, Plus } from 'lucide-vue-next'
import { getTickets, type TicketListItem } from '@/api/cs'
import type { Pagination } from '@/api/types'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 我的工单列表（CS-113）
 *
 * 状态 Tab 切换请求参数；分页「加载更多」累加。
 */
const router = useRouter()

const tabs = [
  { value: 'all', label: '全部' },
  { value: 'pending', label: '待处理' },
  { value: 'processing', label: '处理中' },
  { value: 'waiting_user', label: '等待回复' },
  { value: 'completed', label: '已完成' },
  { value: 'closed', label: '已关闭' },
]

const activeTab = ref('all')
const list = ref<TicketListItem[]>([])
const pagination = ref<Pagination>({ page: 1, page_size: 10, total: 0, total_pages: 1 })
const loading = ref(true)
const loadingMore = ref(false)

const hasMore = computed(() => pagination.value.page < pagination.value.total_pages)

async function fetchPage(page: number) {
  const { data } = await getTickets({ status: activeTab.value, page, per_page: 10 })
  list.value = page === 1 ? data.data.list : [...list.value, ...data.data.list]
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

function switchTab(value: string) {
  if (activeTab.value === value) return
  activeTab.value = value
  reload()
}

onMounted(reload)
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 sm:px-6" data-testid="ticket-list">
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/service-center')">服务中心</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">我的工单</span>
      </nav>

      <div class="mb-4 flex items-center justify-between">
        <h1 class="text-lg font-bold text-slate-800">我的工单</h1>
        <button class="flex items-center gap-1 rounded-full bg-[#1677ff] px-4 py-1.5 text-sm text-white hover:bg-[#4096ff]" data-testid="ticket-new-btn" @click="router.push('/service-center/tickets/new')">
          <Plus class="h-4 w-4" /> 提交工单
        </button>
      </div>

      <!-- 状态 Tab -->
      <div class="mb-4 flex gap-2 overflow-x-auto pb-1" data-testid="ticket-tabs">
        <button
          v-for="t in tabs" :key="t.value"
          class="shrink-0 rounded-full px-4 py-1.5 text-[13px] transition-colors"
          :class="activeTab === t.value ? 'bg-[#1677ff] text-white' : 'border border-slate-200 bg-white text-slate-600 hover:text-[#1677ff]'"
          :data-testid="`ticket-tab-${t.value}`"
          @click="switchTab(t.value)"
        >{{ t.label }}</button>
      </div>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="!list.length" class="rounded-xl bg-white py-16 text-center" data-testid="ticket-list-empty">
        <Inbox class="mx-auto mb-3 h-10 w-10 text-slate-200" />
        <p class="text-sm text-slate-400">暂无工单</p>
      </div>

      <div v-else class="space-y-3">
        <button
          v-for="t in list" :key="t.id"
          class="block w-full rounded-xl bg-white px-4 py-4 text-left transition-shadow hover:shadow-sm"
          :data-testid="`ticket-row-${t.id}`"
          @click="router.push(`/service-center/tickets/${t.id}`)"
        >
          <div class="flex items-center justify-between gap-3">
            <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-700">{{ t.title }}</span>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs" :class="t.status === 'closed' ? 'bg-slate-100 text-slate-400' : 'bg-[#e6f4ff] text-[#1677ff]'">{{ t.status_label }}</span>
          </div>
          <p class="mt-1.5 flex items-center gap-2 text-xs text-slate-400">
            <span>{{ t.ticket_no }}</span>
            <span v-if="t.type_name">· {{ t.type_name }}</span>
            <span>· {{ t.created_at }}</span>
          </p>
        </button>

        <div v-if="hasMore" class="pt-2 text-center">
          <button class="rounded-full border border-slate-200 bg-white px-6 py-2 text-sm text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff] disabled:opacity-50" :disabled="loadingMore" data-testid="ticket-load-more" @click="loadMore">
            {{ loadingMore ? '加载中…' : '加载更多' }}
          </button>
        </div>
      </div>
    </main>

    <ShopFooter />
  </div>
</template>
