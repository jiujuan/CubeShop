<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  getShippings,
  pullShipping,
  getShippingChannel,
  updateShippingChannel,
  getShippingDetail,
  TRACE_STATUS_CLASS,
  TRACE_STATUS_LABELS,
  type ShippingRow,
  type ShippingChannelInfo,
  type ShippingDetail,
} from '@/api/order'
import { ExternalLink, ListOrdered, RefreshCw, Search } from 'lucide-vue-next'
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

/** 当前轨迹查询渠道（只读回显；密钥不明文返回） */
const channelInfo = ref<ShippingChannelInfo | null>(null)
const switching = ref(false)

/** 运单轨迹详情弹层：与用户端订单页物流信息同口径 */
const detail = ref<ShippingDetail | null>(null)
const detailLoading = ref(false)

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

/** 读取当前渠道（失败只留空，不阻断看板） */
async function loadChannel() {
  try {
    const { data } = await getShippingChannel()
    channelInfo.value = data.data
  } catch {
    channelInfo.value = null
  }
}

/**
 * 切换查询渠道（权限 shipping.manage）
 *
 * 只切渠道本身；key / customer 属凭证，仍在 .env 维护，不在后台填写。
 */
async function switchChannel(value: string) {
  if (! channelInfo.value || switching.value) return
  switching.value = true
  try {
    await updateShippingChannel(value)
    await loadChannel()
  } finally {
    switching.value = false
  }
}

/** 查看运单轨迹（与用户端订单页物流信息一致） */
async function openDetail(row: ShippingRow) {
  detailLoading.value = true
  detail.value = null
  try {
    const { data } = await getShippingDetail(row.id)
    detail.value = data.data
  } finally {
    detailLoading.value = false
  }
}

function goOrder(row: ShippingRow) {
  router.push({ path: '/orders', query: { order_no: row.order_no ?? '' } })
}

function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

onMounted(() => {
  load()
  loadChannel()
})
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-slate-800">物流监控</h2>
    <p class="mt-1 text-[13px] text-slate-500">
      运单轨迹集中监控：查询失败、发货超 48h 无轨迹、轨迹停滞超 72h 会标记为「异常」，可单条手动重试。
    </p>

    <!-- 当前查询渠道：只读回显 + 切换（切换需 shipping.manage）；密钥不明文展示 -->
    <div
      v-if="channelInfo"
      class="mb-4 mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-[13px]"
      data-testid="channel-card"
    >
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-slate-500">当前查询渠道</span>
        <span class="font-medium text-slate-800" data-testid="channel-label">{{ channelInfo.label }}</span>
        <span
          class="rounded px-2 py-0.5 text-xs"
          :class="channelInfo.available ? 'bg-green-50 text-green-600' : 'bg-amber-50 text-amber-600'"
          data-testid="channel-available"
        >
          {{ channelInfo.available ? '可查询' : '不可用' }}
        </span>
        <span class="text-xs text-slate-400" data-testid="channel-source">
          {{ channelInfo.source === 'database' ? '后台配置' : '环境配置（.env）' }}
        </span>

        <select
          v-permission="'shipping.manage'"
          class="ml-auto rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff] disabled:opacity-50"
          data-testid="channel-switch"
          :value="channelInfo.configured"
          :disabled="switching"
          @change="switchChannel(($event.target as HTMLSelectElement).value)"
        >
          <option v-for="opt in channelInfo.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
      </div>
      <p class="mt-1.5 text-xs leading-5 text-slate-400">
        密钥：<span data-testid="channel-key">{{ channelInfo.key_configured ? '已配置' : '未配置' }}</span>
        · 授权 customer：<span>{{ channelInfo.customer_configured ? '已配置' : '未配置' }}</span>
        —— key / customer 属凭证，仅在 <code class="rounded bg-white px-1">.env</code> 维护
        （SHIPPING_CHANNEL_KEY / SHIPPING_CHANNEL_CUSTOMER），不在后台填写。
      </p>
    </div>

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
          <th class="w-40 px-3 py-1.5">操作</th>
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
            <div class="flex items-center gap-2 text-[#1677ff]">
              <!-- 轨迹详情：与用户端订单页物流信息同口径（权限 order.view） -->
              <button
                class="flex items-center gap-0.5 hover:underline"
                :data-testid="`monitor-detail-${row.id}`"
                @click="openDetail(row)"
              >
                <ListOrdered class="h-3 w-3" /> 轨迹
              </button>
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

  <!-- 运单轨迹详情弹层：与用户端订单页物流信息同口径 -->
  <div
    v-if="detailLoading || detail"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6"
    data-testid="detail-mask"
    @click.self="detail = null"
  >
    <div class="max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6">
      <h3 class="text-sm font-semibold text-slate-800">物流轨迹</h3>

      <template v-if="detail">
        <p class="mt-2 text-[13px] text-slate-500">
          订单 <span class="font-mono text-slate-700">{{ detail.order_no || detail.order_id }}</span>
        </p>
        <div class="mt-3 flex items-center gap-2 text-[13px]">
          <span class="text-slate-700">{{ detail.company_name || detail.company_code }}</span>
          <span class="font-mono text-slate-500">{{ detail.tracking_no }}</span>
          <span
            class="ml-auto rounded px-2 py-0.5 text-xs"
            :class="TRACE_STATUS_CLASS[detail.trace_status] ?? 'bg-slate-100 text-slate-500'"
            data-testid="detail-status"
          >
            {{ TRACE_STATUS_LABELS[detail.trace_status] ?? detail.trace_status }}
          </span>
        </div>
        <p v-if="detail.phone" class="mt-1 text-xs text-slate-400">收/寄件手机号：{{ detail.phone }}</p>

        <!-- 时间线：最新在顶 -->
        <ol
          v-if="detail.has_trace"
          class="relative mt-4 space-y-0 border-l border-slate-100 pl-4"
          data-testid="detail-trace-list"
        >
          <li v-for="(trace, idx) in detail.traces" :key="`${trace.occurred_at}-${idx}`" class="relative py-2">
            <span
              class="absolute -left-[21px] top-3.5 h-2.5 w-2.5 rounded-full"
              :class="idx === 0 ? 'bg-[#1677ff] ring-4 ring-blue-50' : 'bg-slate-200'"
            ></span>
            <div class="flex flex-col sm:flex-row sm:items-baseline sm:gap-3">
              <time class="shrink-0 text-xs text-slate-400 sm:w-36">{{ trace.occurred_at }}</time>
              <p class="min-w-0 break-words text-sm sm:flex-1" :class="idx === 0 ? 'font-semibold text-slate-800' : 'text-slate-500'">
                {{ trace.context }}
              </p>
            </div>
          </li>
        </ol>
        <p v-else class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-500" data-testid="detail-trace-empty">
          {{ detail.trace_status === 'delivered' ? '暂无轨迹记录' : '快递信息查询中，暂无轨迹' }}
        </p>

        <p v-if="detail.last_fail_message" class="mt-3 text-xs text-red-500" data-testid="detail-fail">
          最近失败：{{ detail.last_fail_message }}（累计 {{ detail.pull_fail_count }} 次）
        </p>

        <div class="mt-5 flex justify-end">
          <Button variant="outline" data-testid="detail-close" @click="detail = null">关闭</Button>
        </div>
      </template>

      <LoadingSpinner v-else />
    </div>
  </div>
</template>
