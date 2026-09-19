<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RefreshCw } from 'lucide-vue-next'

import { getWmsHealth, WMS_HEALTH_STATUS_CLASS, WMS_HEALTH_STATUS_LABELS, type WmsHealthReport } from '@/api/wms'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * WMS 健康看板（WMS 计划 P6 / F7，权限 wms.config.manage）
 *
 * 直接渲染 `GET /admin/wms/health`（= `wms:health` 命令的只读版）：
 * 概览卡片给数量级，下方逐项列出巡检结论与明细。
 * ⚠️ 本页**只读**——不触发告警（避免每次打开都给运营推站内信），
 * 告警由定时任务 `wms:health --alert` 负责。
 */
const report = ref<WmsHealthReport | null>(null)
const loading = ref(true)
const tip = ref('')

async function load() {
  loading.value = true
  tip.value = ''
  try {
    const { data } = await getWmsHealth()
    report.value = data.data
  } catch (e) {
    tip.value = (e as { message?: string })?.message ?? '加载失败'
  } finally {
    loading.value = false
  }
}

const cards = ref<{ key: string; label: string; value: string; hint: string; danger: boolean }[]>([])

function buildCards(r: WmsHealthReport) {
  cards.value = [
    {
      key: 'pushing_timeout', label: '推送卡死', value: String(r.summary.pushing_timeout),
      hint: '卡在「推送中」超过阈值', danger: r.summary.pushing_timeout > 0,
    },
    {
      key: 'push_failed', label: '推送失败', value: String(r.summary.push_failed),
      hint: '需人工重推或修复', danger: r.summary.push_failed > 0,
    },
    {
      key: 'exception', label: '异常单据', value: String(r.summary.exception),
      hint: '缺映射等，修复后重推', danger: r.summary.exception > 0,
    },
    {
      key: 'fail_rate', label: '接口失败率', value: `${(r.summary.fail_rate * 100).toFixed(1)}%`,
      hint: '近 24 小时 WMS 调用', danger: r.summary.fail_rate > 0.2,
    },
    {
      key: 'queue_backlog', label: '队列积压', value: String(r.summary.queue_backlog),
      hint: '待处理作业数', danger: r.summary.queue_backlog > 500,
    },
  ]
}

onMounted(async () => {
  await load()
  if (report.value) buildCards(report.value)
})

async function refresh() {
  await load()
  if (report.value) buildCards(report.value)
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">WMS 健康看板</h2>
      <button
        class="flex items-center gap-1 rounded border border-slate-300 px-3 py-1.5 text-[13px] text-slate-600 transition-colors hover:bg-slate-50"
        data-testid="refresh"
        @click="refresh"
      ><RefreshCw class="h-3.5 w-3.5" /> 重新检查</button>
    </div>

    <p v-if="tip" class="mb-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500" data-testid="tip">{{ tip }}</p>
    <div v-if="loading" class="py-10"><LoadingSpinner /></div>

    <template v-else-if="report">
      <div class="mb-3 flex items-center gap-2 text-[13px]">
        <span
          class="rounded px-2 py-0.5 text-xs font-medium"
          :class="report.healthy ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'"
          data-testid="health-badge"
        >{{ report.healthy ? '健康' : '发现问题' }}</span>
        <span class="text-slate-400">检查时间 {{ report.checked_at }}</span>
      </div>

      <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <div
          v-for="card in cards"
          :key="card.key"
          class="rounded-lg border p-3"
          :class="card.danger ? 'border-amber-200 bg-amber-50/40' : 'border-slate-100'"
          :data-testid="`card-${card.key}`"
        >
          <p class="text-xs text-slate-500">{{ card.label }}</p>
          <p class="mt-1 text-xl font-semibold" :class="card.danger ? 'text-amber-600' : 'text-slate-800'">{{ card.value }}</p>
          <p class="mt-0.5 text-[11px] text-slate-400">{{ card.hint }}</p>
        </div>
      </div>

      <section class="mt-5">
        <h3 class="mb-2 text-xs font-semibold text-slate-500">巡检明细</h3>
        <table class="w-full text-[13px]">
          <thead>
            <tr class="border-b border-slate-200 text-left text-slate-500">
              <th class="w-40 px-3 py-1.5">项目</th>
              <th class="w-20 px-3 py-1.5">结论</th>
              <th class="px-3 py-1.5">说明</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in report.checks" :key="c.key" class="border-b border-slate-100" :data-testid="`check-${c.key}`">
              <td class="px-3 py-1.5 text-black">{{ c.title }}</td>
              <td class="px-3 py-1.5">
                <span class="rounded px-2 py-0.5 text-xs" :class="WMS_HEALTH_STATUS_CLASS[c.status]">{{ WMS_HEALTH_STATUS_LABELS[c.status] }}</span>
              </td>
              <td class="px-3 py-1.5 text-black">{{ c.detail }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>
