<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import {
  getReconcileSummary,
  PAYMENT_CHANNEL_LABELS,
  PAY_PLATFORM_LABELS,
  RECONCILE_DIFF_TYPE_BAR,
  RECONCILE_DIFF_TYPE_LABELS,
  type PayPlatform,
  type ReconcileDiffType,
  type ReconcileStats,
} from '@/api/reconcile'
import { useAuthStore } from '@/stores/auth'

/**
 * 前台对账看板（A7 增强：web 商城侧只读汇总）
 *
 * 面向持有 payment.reconcile.view 权限的运营/财务人员（登录后按 /auth/me
 * 返回的 permissions 判定）；普通买家看到「无权限」占位。
 * 数据只读：差异工单明细与处置在 admin 后台，这里只看整体态势。
 * 平台筛选（web/H5/小程序）不选默认全部。
 */
const auth = useAuthStore()

const loading = ref(true)
const forbidden = ref(false)
const platform = ref<'' | PayPlatform>('')
const stats = ref<ReconcileStats | null>(null)

/** 是否持有对账查看权限（/auth/me 对运营账号返回 permissions） */
const canView = computed(() => (auth.user?.permissions ?? []).includes('payment.reconcile.view'))

/** 分布展示的差异类型 */
const distTypes = computed<ReconcileDiffType[]>(() => {
  const byType = stats.value?.by_type ?? {}
  const base: ReconcileDiffType[] = ['MISSING_LOCAL', 'MISSING_CHANNEL', 'AMOUNT_MISMATCH', 'DUPLICATE_CALLBACK']
  if ((byType.UNKNOWN ?? 0) > 0) base.push('UNKNOWN')
  return base
})

function distPct(count: number): string {
  const byType = stats.value?.by_type ?? {}
  const max = Math.max(1, ...distTypes.value.map((t) => byType[t] ?? 0))
  return `${Math.round((count / max) * 100)}%`
}

function channelPct(diffs: number): string {
  const rows = stats.value?.by_channel ?? []
  const max = Math.max(1, ...rows.map((r) => r.diffs))
  return `${Math.max(4, Math.round((diffs / max) * 100))}%`
}

function trendHeight(diffs: number): string {
  const trend = stats.value?.trend ?? []
  const max = Math.max(1, ...trend.map((p) => p.diffs))
  return `${Math.max(4, Math.round((diffs / max) * 100))}%`
}

async function load() {
  loading.value = true
  forbidden.value = false
  try {
    const { data } = await getReconcileSummary({ platform: platform.value || undefined })
    stats.value = data.data
  } catch (e) {
    const status = (e as { status?: number })?.status
    forbidden.value = status === 401 || status === 403
    stats.value = null
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  // 直接刷新进入时 user 尚未引导，先补一次
  if (!auth.user && auth.token) {
    try {
      await auth.fetchUser()
    } catch {
      // token 失效由拦截器清理，页面展示无权限态
    }
  }
  await load()
})
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 py-6">
    <!-- 无权限：普通买家 / 未授权运营 -->
    <div v-if="!loading && (forbidden || !canView)" class="rounded-lg bg-white p-10 text-center shadow-sm" data-testid="reconcile-forbidden">
      <p class="text-lg font-semibold text-slate-800">无权访问对账看板</p>
      <p class="mt-2 text-sm text-slate-500">对账数据仅对持有财务权限的运营人员开放，如需访问请联系管理员。</p>
    </div>

    <template v-else-if="canView">
      <div class="mb-4 flex items-center justify-between">
        <div>
          <h1 class="text-xl font-semibold text-slate-900">支付对账看板</h1>
          <p class="mt-1 text-xs text-slate-500">渠道账单 ↔ 本地支付单日终对账态势（只读；明细与处置请在管理后台操作）</p>
        </div>
        <select
          v-model="platform"
          class="h-8 rounded border border-slate-200 px-2 text-[13px] text-black"
          data-testid="reconcile-platform"
          @change="load"
        >
          <option value="">全部平台</option>
          <option v-for="(label, key) in PAY_PLATFORM_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>

      <div v-if="loading" class="rounded-lg bg-white p-10 text-center text-sm text-slate-400 shadow-sm" data-testid="reconcile-loading">加载中…</div>

      <template v-else-if="stats">
        <!-- 统计卡 -->
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
          <div class="rounded-lg bg-white p-5 shadow-sm">
            <div class="text-xs text-slate-400">对账批次总数</div>
            <div class="mt-1 text-2xl font-semibold text-slate-800" data-testid="reconcile-runs">{{ stats.total_runs }}</div>
          </div>
          <div class="rounded-lg bg-white p-5 shadow-sm">
            <div class="text-xs text-slate-400">差异工单总数</div>
            <div class="mt-1 text-2xl font-semibold text-slate-800" data-testid="reconcile-diffs">{{ stats.total_diffs }}</div>
          </div>
          <div class="rounded-lg bg-white p-5 shadow-sm">
            <div class="text-xs text-slate-400">待处理</div>
            <div class="mt-1 text-2xl font-semibold" :class="stats.pending_diffs > 0 ? 'text-amber-600' : 'text-emerald-600'" data-testid="reconcile-pending">{{ stats.pending_diffs }}</div>
          </div>
          <div class="rounded-lg bg-white p-5 shadow-sm">
            <div class="text-xs text-slate-400">已闭环（处置+忽略）</div>
            <div class="mt-1 text-2xl font-semibold text-slate-800" data-testid="reconcile-closed">{{ stats.resolved_diffs + stats.ignored_diffs }}</div>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
          <!-- 差异类型分布 -->
          <div class="rounded-lg bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-sm font-semibold text-slate-800">差异类型分布</h2>
            <div class="space-y-3">
              <div v-for="t in distTypes" :key="t" class="text-[13px]" :data-testid="`reconcile-dist-${t}`">
                <div class="mb-1 flex justify-between">
                  <span class="text-slate-600">{{ RECONCILE_DIFF_TYPE_LABELS[t] }}</span>
                  <span class="font-mono text-slate-800">{{ stats.by_type[t] ?? 0 }}</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded bg-slate-100">
                  <div class="h-2 rounded" :class="RECONCILE_DIFF_TYPE_BAR[t]" :style="{ width: distPct(stats.by_type[t] ?? 0) }"></div>
                </div>
              </div>
              <div v-if="!distTypes.length" class="py-6 text-center text-[13px] text-slate-400">暂无差异数据</div>
            </div>
          </div>

          <!-- 近 14 天差异趋势 -->
          <div class="rounded-lg bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-sm font-semibold text-slate-800">近 14 天差异趋势</h2>
            <div class="flex h-40 items-end gap-1" data-testid="reconcile-trend">
              <div
                v-for="(p, idx) in stats.trend"
                :key="p.date"
                class="flex flex-1 flex-col items-center justify-end"
                :title="`${p.date}：${p.diffs} 笔`"
              >
                <div class="w-full rounded bg-[#1677ff]" :style="{ height: trendHeight(p.diffs) }"></div>
                <div class="mt-1 text-[10px] text-slate-400">{{ idx % 2 === 0 ? p.date.slice(5) : '' }}</div>
              </div>
            </div>
          </div>
        </div>

        <!-- 按渠道拆分 -->
        <div class="mt-4 rounded-lg bg-white p-5 shadow-sm" data-testid="reconcile-by-channel">
          <h2 class="mb-4 text-sm font-semibold text-slate-800">按渠道拆分</h2>
          <div v-if="!stats.by_channel.length" class="py-6 text-center text-[13px] text-slate-400">暂无差异数据</div>
          <div class="space-y-3">
            <div v-for="r in stats.by_channel" :key="r.channel" class="text-[13px]">
              <div class="mb-1 flex items-center justify-between">
                <span class="text-slate-600">{{ PAYMENT_CHANNEL_LABELS[r.channel] ?? r.channel }}</span>
                <span class="text-slate-500">
                  差异 <b class="font-mono text-slate-800">{{ r.diffs }}</b>
                  · 待处理 <b class="font-mono text-amber-600">{{ r.pending }}</b>
                  · 已处置 <b class="font-mono text-emerald-600">{{ r.resolved }}</b>
                  · 已忽略 <b class="font-mono text-slate-500">{{ r.ignored }}</b>
                </span>
              </div>
              <div class="h-2 w-full overflow-hidden rounded bg-slate-100">
                <div class="h-2 rounded bg-[#1677ff]" :style="{ width: channelPct(r.diffs) }"></div>
              </div>
            </div>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
