<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getShippings, pullShipping, TRACE_STATUS_CLASS, TRACE_STATUS_LABELS, type ShippingRow } from '@/api/order'
import { ExternalLink, RefreshCw, Search } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 物流监控看板（V1.1 T-047，E03；列表权限 order.view，重试权限 order.ship）
 *
 * 异常口径（后端 abnormal）：轨迹拉取失败 / 发货超 48h 无轨迹 / 运输中轨迹停滞超 72h。
 * 单条「重试」调 POST /admin/shippings/{id}/pull 手动拉取轨迹。
 */
const router = useRouter()
const loading = ref(true)
const list = ref<ShippingRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })

const traceStatus = ref<'' | string>('')
const keyword = ref('')
const pullingId = ref<number | null>(null)
const retryMsg = ref('')

/** 筛选下拉：只读 + 异常视角常用项（failed 直接筛，停滞用前端 abnormal 标记展示） */
const statusOptions: Array<{ value: '' | string; label: string }> = [
  { value: '', label: '全部' },
  { value: 'in_transit', label: '运输中' },
  { value: 'pending', label: '待查询' },
  { value: 'delivered', label: '已签收' },
  { value: 'failed', label: '查询失败' },
]

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getShippings({
      trace_status: traceStatus.value || undefined,
      keyword: keyword.value.trim() || undefined,
      page,
      page_size: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

function search() {
  load(1)
}

/** 手动重试轨迹拉取 */
async function doPull(row: ShippingRow) {
  pullingId.value = row.id
  retryMsg.value = ''
  try {
    const { data } = await pullShipping(row.id)
    retryMsg.value = data.message
    await load(pagination.value.page)
  } catch {
    // 业务错误 toast 全局已提示（拉取失败 40000）
  } finally {
    pullingId.value = null
  }
}

function goOrder(row: ShippingRow) {
  router.push({ path: '/orders', query: { order_no: row.order_no ?? '' } })
}

function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

onMounted(() => load())
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-slate-800">物流监控</h2>
    <p class="mt-1 text-[13px] text-slate-500">
      运单轨迹集中监控：查询失败、发货超 48h 无轨迹、轨迹停滞超 72h 会标记为「异常」，可单条手动重试。
    </p>

    <!-- 筛选 -->
    <div class="mb-4 mt-4 flex flex-wrap items-center gap-2 text-[13px]">
      <select v-model="traceStatus" data-testid="monitor-status-filter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
      <input
        v-model="keyword" type="text" placeholder="订单号 / 运单号 / 快递公司"
        class="w-56 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="search"
      />
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" @click="search"><Search class="mr-1 h-4 w-4" /> 搜索</Button>
      <span v-if="retryMsg" data-testid="monitor-retry-msg" class="text-xs text-green-600">{{ retryMsg }}</span>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">订单号</th>
          <th class="px-3 py-1.5">快递公司 / 单号</th>
          <th class="w-20 px-3 py-1.5">轨迹状态</th>
          <th class="w-16 px-3 py-1.5">轨迹数</th>
          <th class="w-40 px-3 py-1.5">发货时间</th>
          <th class="w-24 px-3 py-1.5">标记</th>
          <th class="w-32 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5">
            <button class="font-mono text-[#1677ff] hover:underline" :data-testid="`monitor-order-${row.id}`" @click="goOrder(row)">
              {{ row.order_no || row.order_id }} <ExternalLink class="inline h-3 w-3" />
            </button>
          </td>
          <td class="px-3 py-1.5">
            <p class="text-black">{{ row.company_name || row.company_code }}</p>
            <p class="font-mono text-xs text-slate-500">{{ row.tracking_no }}</p>
          </td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="TRACE_STATUS_CLASS[row.trace_status] ?? 'bg-slate-100 text-slate-500'">
              {{ TRACE_STATUS_LABELS[row.trace_status] ?? row.trace_status }}
            </span>
          </td>
          <td class="px-3 py-1.5 text-slate-500">{{ row.trace_count }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.shipped_at || '—' }}</td>
          <td class="px-3 py-1.5">
            <span
              v-if="row.abnormal"
              class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-500"
              :title="row.last_fail_message ? `最近失败：${row.last_fail_message}（累计 ${row.pull_fail_count} 次）` : '发货超 48h 无轨迹 / 轨迹停滞超 72h'"
              :data-testid="`monitor-abnormal-${row.id}`"
            >异常</span>
            <span v-else class="text-slate-300">—</span>
          </td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-1 text-[#1677ff]">
              <button
                v-permission="'order.ship'"
                class="flex items-center gap-0.5 hover:underline disabled:opacity-50"
                :disabled="pullingId !== null"
                :data-testid="`monitor-pull-${row.id}`"
                @click="doPull(row)"
              >
                <RefreshCw class="h-3 w-3" :class="pullingId === row.id && 'animate-spin'" />
                {{ pullingId === row.id ? '拉取中…' : '重试' }}
              </button>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="7"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="7" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <TablePagination :pagination="pagination" @change="goPage" />
  </div>
</template>
