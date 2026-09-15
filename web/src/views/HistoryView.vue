<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Clock, Trash2 } from 'lucide-vue-next'
import { clearHistories, getHistories, type HistoryItem } from '@/api/favorite'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 浏览足迹（V1.1 F05 / T-025）
 * 按日期分组（今天 / 昨天 / 更早），支持清空（二次确认）。
 */
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const tip = ref('')
const list = ref<HistoryItem[]>([])
const confirmOpen = ref(false)

type GroupKey = 'today' | 'yesterday' | 'earlier'

/** 按浏览日期分组 */
const grouped = computed(() => {
  const buckets: Record<GroupKey, HistoryItem[]> = { today: [], yesterday: [], earlier: [] }
  const now = new Date()
  const dayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()

  for (const item of list.value) {
    const t = item.browsed_at ? new Date(item.browsed_at.replace(' ', 'T')).getTime() : 0
    if (t >= dayStart) buckets.today.push(item)
    else if (t >= dayStart - 86400000) buckets.yesterday.push(item)
    else buckets.earlier.push(item)
  }

  return ([
    { key: 'today', label: '今天', items: buckets.today },
    { key: 'yesterday', label: '昨天', items: buckets.yesterday },
    { key: 'earlier', label: '更早', items: buckets.earlier },
  ] as Array<{ key: GroupKey; label: string; items: HistoryItem[] }>).filter((g) => g.items.length > 0)
})

async function load() {
  loading.value = true
  tip.value = ''
  try {
    const { data } = await getHistories({ page: 1, page_size: 90 })
    list.value = data.data.list
  } finally {
    loading.value = false
  }
}

async function doClear() {
  confirmOpen.value = false
  try {
    await clearHistories()
    list.value = []
    tip.value = '已清空浏览足迹'
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '清空失败'
  }
}

function goDetail(item: HistoryItem) {
  if (!item.is_available) return
  router.push(`/product/${item.id}`)
}

onMounted(() => {
  if (auth.token) load()
  else loading.value = false
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />
    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6" data-testid="history-view">
      <div class="mb-4 flex items-center justify-between">
        <h1 class="flex items-center gap-2 text-lg font-semibold text-slate-800">
          <Clock class="h-5 w-5 text-[#1677ff]" /> 浏览足迹
        </h1>
        <button
          v-if="list.length"
          class="flex items-center gap-1 rounded-md border border-slate-200 px-3 py-1.5 text-[13px] text-slate-500 hover:border-red-300 hover:text-red-500"
          data-testid="clear-history-btn"
          @click="confirmOpen = true"
        >
          <Trash2 class="h-3.5 w-3.5" /> 清空足迹
        </button>
      </div>

      <p v-if="tip" class="mb-3 rounded-md bg-green-50 px-3 py-2 text-[13px] text-green-600">{{ tip }}</p>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="!list.length" class="rounded-xl border border-slate-100 bg-white py-20 text-center">
        <p class="text-slate-400" data-testid="history-empty">还没有浏览记录</p>
        <button class="mt-4 rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]" @click="router.push('/')">去逛逛</button>
      </div>

      <div v-else class="space-y-6">
        <section v-for="group in grouped" :key="group.key" :data-testid="`history-group-${group.key}`">
          <h2 class="mb-2 text-sm font-medium text-slate-500">{{ group.label }}</h2>
          <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <div
              v-for="item in group.items" :key="item.id"
              class="group relative flex flex-col overflow-hidden rounded-xl border bg-white transition-all"
              :class="item.is_available ? 'border-slate-100 shadow-sm hover:-translate-y-0.5 hover:shadow-md' : 'border-slate-100 opacity-70'"
            >
              <span
                v-if="!item.is_available"
                class="absolute z-10 rounded bg-slate-700/85 px-1.5 py-0.5 text-[11px] text-white"
                style="margin: 8px;"
              >已失效</span>
              <div
                class="flex aspect-square cursor-pointer items-center justify-center bg-gradient-to-br from-[#f5faff] to-[#e6f4ff]"
                @click="goDetail(item)"
              >
                <img v-if="item.main_image" :src="item.main_image" class="h-full w-full object-cover" :class="!item.is_available && 'grayscale'" alt="" />
                <span v-else class="text-5xl">📦</span>
              </div>
              <div class="flex flex-1 flex-col gap-1 p-3">
                <div class="truncate text-sm font-semibold text-slate-800" :title="item.title">{{ item.title }}</div>
                <div class="text-xs text-slate-400" :data-testid="`history-time-${item.id}`">浏览于 {{ item.browsed_at }}</div>
                <div class="mt-auto flex items-end justify-between pt-1.5">
                  <div class="text-base font-bold text-[#ff4d4f]"><span class="text-xs">¥</span>{{ item.price }}</div>
                  <button
                    class="rounded-full border border-[#1677ff] px-3 py-1 text-xs text-[#1677ff] hover:bg-[#1677ff] hover:text-white"
                    @click="goDetail(item)"
                  >{{ item.is_available ? '去看看' : '已失效' }}</button>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </main>
    <ShopFooter />

    <ConfirmDialog
      v-model="confirmOpen"
      title="确认清空浏览足迹？"
      content="清空后无法恢复，不影响已收藏的商品。"
      confirm-text="确认清空"
      @confirm="doClear"
      @cancel="confirmOpen = false"
    />
  </div>
</template>
