<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ChevronLeft, ChevronRight, Coins } from 'lucide-vue-next'
import { getMyPoints, getMyPointLogs, type MyPoints, type PointLogPage } from '@/api/points'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 我的积分（会员成长计划 S3）
 * 概览（可用 / 累计获得 / 累计消耗）+ 流水分页。
 * 功能未开启（points.enabled=0）时给出提示，不报错。
 */
const auth = useAuthStore()

const loading = ref(true)
const error = ref('')
const overview = ref<MyPoints | null>(null)
const pageData = ref<PointLogPage | null>(null)
const page = ref(1)
const perPage = 10

const name = computed(() => overview.value?.name ?? '积分')
const lastPage = computed(() => pageData.value?.pagination.last_page ?? 1)

async function loadOverview() {
  const { data } = await getMyPoints()
  overview.value = data.data
}

async function loadLogs(target = 1) {
  const { data } = await getMyPointLogs(target, perPage)
  pageData.value = data.data
  page.value = target
}

function goto(target: number) {
  if (target < 1 || target > lastPage.value) return
  loadLogs(target).catch(() => (error.value = '加载积分流水失败'))
}

onMounted(async () => {
  if (!auth.token) {
    loading.value = false
    return
  }
  try {
    await loadOverview()
    // 功能未开启时流水区不展示，无需再拉一次
    if (overview.value?.enabled) await loadLogs(1)
  } catch {
    error.value = '加载积分信息失败'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />
    <main class="mx-auto w-full max-w-4xl flex-1 px-4 py-6" data-testid="points-view">
      <h1 class="mb-4 flex items-center gap-2 text-lg font-semibold text-slate-800">
        <Coins class="h-5 w-5 text-[#1677ff]" /> 我的{{ name }}
      </h1>

      <LoadingSpinner v-if="loading" />

      <template v-else>
        <div
          v-if="overview && !overview.enabled"
          class="rounded-xl border border-slate-100 bg-white p-8 text-center text-slate-400"
          data-testid="points-disabled"
        >{{ name }}功能未开启</div>

        <template v-else-if="overview">
          <!-- 概览卡片 -->
          <div class="mb-4 grid grid-cols-3 gap-4" data-testid="points-summary">
            <div class="rounded-xl border border-slate-100 bg-white p-5 text-center shadow-sm">
              <div class="text-xs text-slate-400">可用{{ name }}</div>
              <div class="mt-1 text-2xl font-bold text-[#1677ff]" data-testid="points-balance">{{ overview.account.balance }}</div>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-5 text-center shadow-sm">
              <div class="text-xs text-slate-400">累计获得</div>
              <div class="mt-1 text-2xl font-bold text-slate-800" data-testid="points-total-earn">{{ overview.account.total_earn }}</div>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-5 text-center shadow-sm">
              <div class="text-xs text-slate-400">累计消耗</div>
              <div class="mt-1 text-2xl font-bold text-slate-800" data-testid="points-total-spend">{{ overview.account.total_spend }}</div>
            </div>
          </div>

          <p v-if="error" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-[13px] text-red-500">{{ error }}</p>

          <!-- 流水列表 -->
          <div class="overflow-hidden rounded-xl border border-slate-100 bg-white shadow-sm">
            <table class="w-full text-left text-[13px]" data-testid="points-logs">
              <thead class="bg-slate-50 text-xs text-slate-500">
                <tr>
                  <th class="px-4 py-2.5 font-medium">类型</th>
                  <th class="px-4 py-2.5 font-medium">变动</th>
                  <th class="px-4 py-2.5 font-medium">说明</th>
                  <th class="px-4 py-2.5 font-medium">时间</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!pageData?.list.length">
                  <td colspan="4" class="px-4 py-10 text-center text-slate-400" data-testid="points-logs-empty">暂无{{ name }}流水</td>
                </tr>
                <tr v-for="log in pageData?.list" :key="log.id" class="border-t border-slate-50">
                  <td class="px-4 py-3 text-slate-700">{{ log.type_label }}</td>
                  <td
                    class="px-4 py-3 font-semibold"
                    :class="log.points > 0 ? 'text-[#1677ff]' : 'text-slate-500'"
                    :data-testid="`points-log-points-${log.id}`"
                  >{{ log.points > 0 ? '+' : '' }}{{ log.points }}</td>
                  <td class="max-w-[220px] truncate px-4 py-3 text-slate-500" :title="log.remark ?? ''">{{ log.remark || '-' }}</td>
                  <td class="px-4 py-3 text-slate-400">{{ log.created_at }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- 分页 -->
          <div v-if="(pageData?.pagination.last_page ?? 1) > 1" class="mt-3 flex items-center justify-end gap-2 text-[13px] text-slate-500">
            <button
              class="flex items-center gap-0.5 rounded-md border border-slate-200 px-2 py-1 disabled:opacity-40"
              :disabled="page <= 1"
              data-testid="points-logs-prev"
              @click="goto(page - 1)"
            ><ChevronLeft class="h-3.5 w-3.5" /> 上一页</button>
            <span>{{ page }} / {{ lastPage }}</span>
            <button
              class="flex items-center gap-0.5 rounded-md border border-slate-200 px-2 py-1 disabled:opacity-40"
              :disabled="page >= lastPage"
              data-testid="points-logs-next"
              @click="goto(page + 1)"
            >下一页 <ChevronRight class="h-3.5 w-3.5" /></button>
          </div>
        </template>
      </template>
    </main>
    <ShopFooter />
  </div>
</template>
