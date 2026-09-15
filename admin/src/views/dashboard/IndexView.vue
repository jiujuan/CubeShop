<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { getDashboard, type DashboardData } from '@/api/order'
import { AlertTriangle, BadgeDollarSign, ClipboardList, PackageCheck, TrendingDown, TrendingUp, Undo2 } from 'lucide-vue-next'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 运营工作台（Roadmap P6）：数据概览 + 库存预警
 */
const loading = ref(true)
const error = ref('')
const data = ref<DashboardData | null>(null)

const salesDelta = computed(() => {
  if (!data.value) return 0
  const today = Number(data.value.today_sales)
  const yesterday = Number(data.value.yesterday_sales)
  if (yesterday === 0) return today > 0 ? 100 : 0
  return Math.round(((today - yesterday) / yesterday) * 100)
})

const cards = computed(() => {
  if (!data.value) return []
  return [
    { key: 'today_orders', label: '今日订单量', value: String(data.value.today_orders), sub: `昨日 ${data.value.yesterday_orders} 单`, icon: ClipboardList, tone: 'text-[#1677ff] bg-blue-50' },
    { key: 'today_sales', label: '今日销售额', value: `¥${data.value.today_sales}`, sub: `昨日 ¥${data.value.yesterday_sales}`, icon: BadgeDollarSign, tone: 'text-green-600 bg-green-50', delta: salesDelta.value },
    { key: 'pending_ship', label: '待发货', value: String(data.value.pending_ship), sub: '已支付待发货订单', icon: PackageCheck, tone: 'text-orange-500 bg-orange-50' },
    { key: 'pending_refund', label: '待处理退款', value: String(data.value.pending_refund), sub: '退款中订单', icon: Undo2, tone: 'text-purple-500 bg-purple-50' },
  ]
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await getDashboard()
    data.value = res.data.data
  } catch (e) {
    error.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-lg font-semibold">工作台</h1>
      <button class="text-[13px] text-slate-400 hover:text-[#1677ff]" @click="load">刷新</button>
    </div>

    <div v-if="loading" class="rounded-lg border border-slate-200 bg-white p-10"><LoadingSpinner /></div>

    <div v-else-if="error" class="rounded-lg border border-red-200 bg-red-50 p-4 text-[13px] text-red-500">{{ error }}</div>

    <template v-else-if="data">
      <!-- 统计卡片 -->
      <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div
          v-for="card in cards"
          :key="card.key"
          class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-4"
        >
          <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg" :class="card.tone">
            <component :is="card.icon" class="h-5 w-5" />
          </div>
          <div class="min-w-0">
            <p class="text-xs text-slate-400">{{ card.label }}</p>
            <p class="truncate text-xl font-semibold text-slate-800">{{ card.value }}</p>
            <p class="mt-0.5 flex items-center gap-1 text-xs text-slate-400">
              <template v-if="'delta' in card && card.delta !== undefined">
                <component :is="card.delta >= 0 ? TrendingUp : TrendingDown" class="h-3 w-3" :class="card.delta >= 0 ? 'text-red-500' : 'text-green-600'" />
                较昨日 {{ card.delta >= 0 ? '+' : '' }}{{ card.delta }}%
              </template>
              <template v-else>{{ card.sub }}</template>
            </p>
          </div>
        </div>
      </div>

      <!-- 库存预警 -->
      <div class="rounded-lg border border-slate-200 bg-white">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <h2 class="flex items-center gap-2 text-sm font-medium">
            <AlertTriangle class="h-4 w-4 text-amber-500" />
            库存预警
            <span class="rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-600">可用库存 ≤ {{ data.stock_warning_threshold }} 件</span>
          </h2>
          <span class="text-xs text-slate-400">{{ data.stock_warnings.length ? `共 ${data.stock_warnings.length} 个 SKU` : '暂无预警' }}</span>
        </div>

        <div v-if="data.stock_warnings.length" class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="bg-slate-50 text-left text-slate-400">
                <th class="px-4 py-2 font-normal">SKU</th>
                <th class="px-4 py-2 font-normal">商品</th>
                <th class="px-4 py-2 font-normal">规格</th>
                <th class="px-4 py-2 font-normal">可用库存</th>
                <th class="px-4 py-2 font-normal">锁定库存</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="w in data.stock_warnings" :key="w.sku_id" class="border-t border-slate-50">
                <td class="px-4 py-2 font-mono text-slate-500">#{{ w.sku_id }}</td>
                <td class="max-w-56 truncate px-4 py-2 text-slate-700">{{ w.product_title }}</td>
                <td class="px-4 py-2 text-slate-500">{{ Object.values(w.specs).join(' / ') || '默认规格' }}</td>
                <td class="px-4 py-2">
                  <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="w.stock === 0 ? 'bg-red-100 text-red-500' : 'bg-amber-100 text-amber-600'">
                    {{ w.stock === 0 ? '售罄' : `${w.stock} 件` }}
                  </span>
                </td>
                <td class="px-4 py-2 text-slate-500">{{ w.locked_stock }} 件</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="px-4 py-8 text-center text-[13px] text-slate-400">所有在售商品库存充足</p>
      </div>
    </template>
  </div>
</template>
