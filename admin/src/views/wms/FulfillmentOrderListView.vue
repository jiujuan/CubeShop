<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RefreshCw, Search, X } from 'lucide-vue-next'

import {
  batchPushFulfillmentOrders,
  cancelFulfillmentOrder,
  getFulfillmentOrder,
  getFulfillmentOrders,
  getWmsWarehouseOptions,
  pushFulfillmentOrder,
  FULFILLMENT_STATUS_CLASS,
  FULFILLMENT_STATUS_LABELS,
  WMS_PROVIDER_LABELS,
  type FulfillmentOrderRow,
  type FulfillmentStatus,
  type WmsProvider,
} from '@/api/wms'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 履约中心 / 发货单列表（WMS 计划 P6 / F1、F2）
 *
 * 查看权限 `wms.order.view`，重推与取消需 `wms.order.manage`（按钮级 `v-permission`）。
 * 推送失败行直接展示错误摘要，运营不必再去翻日志；详情抽屉给行项目与推送流水。
 */
const list = ref<FulfillmentOrderRow[]>([])
const pagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')

const statusFilter = ref<'' | FulfillmentStatus>('')
const warehouseId = ref<'' | number>('')
const outboundNo = ref('')
const orderNo = ref('')
const warehouses = ref<{ id: number; name: string }[]>([])

/** 行内操作进行中的单据 id（避免整页 loading 抖动） */
const pendingId = ref<number | null>(null)
const selectedIds = ref<number[]>([])
const batching = ref(false)

/** 详情抽屉 */
const detail = ref<{ open: boolean; loading: boolean; tip: string; data: FulfillmentOrderRow | null }>({
  open: false, loading: false, tip: '', data: null,
})

/** 二次确认：取消发货单必须填理由 */
const confirmState = ref<{ id: number; title: string; message: string; reason: string; danger: boolean } | null>(null)

const allChecked = computed(
  () => list.value.length > 0 && list.value.every((r) => selectedIds.value.includes(r.id)),
)

async function load(page = 1) {
  loading.value = true
  tip.value = ''
  try {
    const { data } = await getFulfillmentOrders({
      status: statusFilter.value || undefined,
      warehouse_id: warehouseId.value === '' ? undefined : Number(warehouseId.value),
      outbound_no: outboundNo.value.trim() || undefined,
      order_no: orderNo.value.trim() || undefined,
      page,
      page_size: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
    selectedIds.value = []
  } catch (e) {
    tip.value = errorText(e)
  } finally {
    loading.value = false
  }
}

/** 统一错误态文案：带上 request_id 便于与后端日志对账（P6 验收项） */
function errorText(e: unknown): string {
  const err = e as { message?: string; data?: { request_id?: string } }
  const rid = err?.data?.request_id
  return rid ? `${err?.message ?? '请求失败'}（request_id: ${rid}）` : (err?.message ?? '请求失败')
}

async function loadWarehouses() {
  try {
    const { data } = await getWmsWarehouseOptions()
    warehouses.value = data.data
  } catch {
    warehouses.value = []   // 下拉失败不阻塞列表本身
  }
}

function search() {
  load(1)
}

function goPage(page: number) {
  load(page)
}

function toggleAll() {
  selectedIds.value = allChecked.value ? [] : list.value.map((r) => r.id)
}

async function doPush(row: FulfillmentOrderRow) {
  pendingId.value = row.id
  try {
    await pushFulfillmentOrder(row.id)
    await load(pagination.value.page)
    if (detail.value.open && detail.value.data?.id === row.id) await openDetail(row.id)
  } catch (e) {
    tip.value = errorText(e)
  } finally {
    pendingId.value = null
  }
}

async function doBatchPush() {
  if (selectedIds.value.length === 0) return
  batching.value = true
  try {
    const { data } = await batchPushFulfillmentOrders(selectedIds.value)
    const failed = data.data.results.filter((r) => !r.success)
    tip.value = failed.length
      ? `批量重推完成：成功 ${data.data.succeeded}/${data.data.total}；失败：${failed.map((f) => `#${f.id} ${f.message}`).join('；')}`
      : `批量重推完成：${data.data.succeeded} 张已重新入队`
    await load(pagination.value.page)
  } catch (e) {
    tip.value = errorText(e)
  } finally {
    batching.value = false
  }
}

function askCancel(row: FulfillmentOrderRow) {
  confirmState.value = {
    id: row.id, title: '取消发货单',
    message: `取消后 WMS 侧同步作废（单号 ${row.outbound_no}），请填写原因：`,
    reason: '', danger: true,
  }
}

async function doCancel() {
  const state = confirmState.value
  if (!state) return
  const reason = state.reason.trim()
  if (!reason) {
    tip.value = '请填写取消原因'
    return
  }
  pendingId.value = state.id
  confirmState.value = null
  try {
    await cancelFulfillmentOrder(state.id, reason)
    await load(pagination.value.page)
    if (detail.value.open && detail.value.data?.id === state.id) await openDetail(state.id)
  } catch (e) {
    tip.value = errorText(e)
  } finally {
    pendingId.value = null
  }
}

async function openDetail(id: number) {
  detail.value = { open: true, loading: true, tip: '', data: null }
  try {
    const { data } = await getFulfillmentOrder(id)
    detail.value.data = data.data
  } catch (e) {
    detail.value.tip = errorText(e)
  } finally {
    detail.value.loading = false
  }
}

function providerLabel(p: string): string {
  return WMS_PROVIDER_LABELS[p as WmsProvider] ?? p
}

onMounted(async () => {
  await loadWarehouses()
  await load()
})
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">发货单</h2>
      <button
        v-permission="'wms.order.manage'"
        class="rounded bg-[#1677ff] px-3 py-1.5 text-[13px] text-white transition-colors hover:bg-[#4096ff] disabled:opacity-40"
        :disabled="selectedIds.length === 0 || batching"
        data-testid="batch-push"
        @click="doBatchPush"
      >{{ batching ? '重推中…' : `批量重推${selectedIds.length ? `（${selectedIds.length}）` : ''}` }}</button>
    </div>

    <p v-if="tip" class="mb-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500" data-testid="tip">{{ tip }}</p>

    <!-- 筛选行 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <select v-model="statusFilter" class="h-8 rounded border border-slate-200 px-2 text-black" data-testid="filter-status">
        <option value="">全部状态</option>
        <option v-for="(label, key) in FULFILLMENT_STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <select v-model="warehouseId" class="h-8 rounded border border-slate-200 px-2 text-black" data-testid="filter-warehouse">
        <option value="">全部仓库</option>
        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>
      <input v-model="outboundNo" placeholder="出库单号" class="h-8 w-40 rounded border border-slate-200 px-2 text-black" data-testid="filter-outbound" />
      <input v-model="orderNo" placeholder="订单号" class="h-8 w-40 rounded border border-slate-200 px-2 text-black" data-testid="filter-order" />
      <button class="flex h-8 items-center gap-1 rounded bg-[#1677ff] px-3 text-white hover:bg-[#4096ff]" data-testid="search" @click="search">
        <Search class="h-3.5 w-3.5" /> 查询
      </button>
    </div>

    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-10 px-3 py-1.5">
            <input type="checkbox" :checked="allChecked" data-testid="check-all" @change="toggleAll" />
          </th>
          <th class="px-3 py-1.5">出库单号</th>
          <th class="px-3 py-1.5">订单号</th>
          <th class="w-28 px-3 py-1.5">仓库</th>
          <th class="w-24 px-3 py-1.5">状态</th>
          <th class="px-3 py-1.5">运单号</th>
          <th class="w-16 px-3 py-1.5">推送</th>
          <th class="w-44 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`row-${row.id}`">
          <td class="px-3 py-1.5">
            <input type="checkbox" :value="row.id" v-model="selectedIds" />
          </td>
          <td class="px-3 py-1.5 font-mono text-black">
            {{ row.outbound_no }}
            <span v-if="row.wms_outbound_no" class="ml-1 text-xs text-slate-400" :title="`WMS 单号 ${row.wms_outbound_no}`">· {{ providerLabel(row.provider) }}</span>
          </td>
          <td class="px-3 py-1.5 font-mono text-black">{{ row.order_no }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.warehouse_name || '-' }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="FULFILLMENT_STATUS_CLASS[row.status]">{{ FULFILLMENT_STATUS_LABELS[row.status] }}</span>
          </td>
          <td class="px-3 py-1.5 font-mono text-black">
            {{ row.tracking_no || '-' }}
            <span v-if="row.carrier_name" class="ml-1 text-xs text-slate-400">{{ row.carrier_name }}</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ row.push_times }} 次</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-2">
              <button class="text-[#1677ff] hover:underline" :data-testid="`detail-${row.id}`" @click="openDetail(row.id)">详情</button>
              <template v-if="row.can_push">
                <span class="text-slate-200">|</span>
                <button
                  v-permission="'wms.order.manage'"
                  class="flex items-center gap-1 text-emerald-600 hover:underline disabled:opacity-40"
                  :disabled="pendingId === row.id"
                  :data-testid="`push-${row.id}`"
                  @click="doPush(row)"
                ><RefreshCw class="h-3 w-3" />{{ pendingId === row.id ? '重推中…' : '重推' }}</button>
              </template>
              <template v-if="row.can_cancel">
                <span class="text-slate-200">|</span>
                <button
                  v-permission="'wms.order.manage'"
                  class="text-red-500 hover:underline"
                  :data-testid="`cancel-${row.id}`"
                  @click="askCancel(row)"
                >取消</button>
              </template>
            </div>
            <!-- 失败/异常原因直接铺在行下方，运营不必再翻日志 -->
            <p v-if="row.last_push_error" class="mt-1 max-w-80 truncate text-xs text-red-500" :title="row.last_push_error" :data-testid="`error-${row.id}`">
              {{ row.last_push_error }}
            </p>
            <p v-else-if="row.exception_reason" class="mt-1 max-w-80 truncate text-xs text-amber-600" :title="row.exception_reason" :data-testid="`error-${row.id}`">
              {{ row.exception_reason }}
            </p>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="8"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="8" class="px-3 py-12 text-center text-slate-400" data-testid="empty">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <TablePagination :pagination="pagination" @change="goPage" />

    <!-- 详情弹层 -->
    <div
      v-if="detail.open"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-8"
      @click.self="detail.open = false"
    >
      <div class="w-full max-w-3xl rounded-lg bg-white p-5 shadow-lg" data-testid="fo-detail">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="text-base font-semibold text-slate-800">发货单详情</h3>
          <button class="text-slate-400 hover:text-slate-600" data-testid="detail-close" @click="detail.open = false"><X class="h-4 w-4" /></button>
        </div>

        <p v-if="detail.tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ detail.tip }}</p>
        <div v-if="detail.loading" class="py-10"><LoadingSpinner /></div>

        <template v-else-if="detail.data">
          <div class="grid gap-4 sm:grid-cols-2">
            <section class="rounded-lg border border-slate-100 p-3 text-[13px]">
              <h4 class="mb-2 text-xs font-semibold text-slate-500">基本信息</h4>
              <p class="text-black">出库单号：<span class="font-mono">{{ detail.data.outbound_no }}</span></p>
              <p class="mt-1 text-black">WMS 单号：<span class="font-mono">{{ detail.data.wms_outbound_no || '-' }}</span></p>
              <p class="mt-1 text-black">订单号：<span class="font-mono">{{ detail.data.order_no }}</span></p>
              <p class="mt-1 text-black">仓库：{{ detail.data.warehouse_name || '-' }}｜{{ providerLabel(detail.data.provider) }}</p>
              <p class="mt-1 text-black">
                状态：<span class="rounded px-1.5 py-0.5 text-xs" :class="FULFILLMENT_STATUS_CLASS[detail.data.status]">{{ FULFILLMENT_STATUS_LABELS[detail.data.status] }}</span>
              </p>
            </section>

            <section class="rounded-lg border border-slate-100 p-3 text-[13px]">
              <h4 class="mb-2 text-xs font-semibold text-slate-500">物流与推送</h4>
              <p class="text-black">承运商：{{ detail.data.carrier_name || detail.data.carrier_code || '-' }}</p>
              <p class="mt-1 text-black">运单号：<span class="font-mono">{{ detail.data.tracking_no || '-' }}</span></p>
              <p class="mt-1 text-black">推送次数：{{ detail.data.push_times }} 次｜最后推送：{{ detail.data.last_push_at || '-' }}</p>
              <p class="mt-1 text-black">
                request_id：<span class="font-mono">{{ detail.data.push_request_id || '-' }}</span>
              </p>
              <p v-if="detail.data.last_push_error" class="mt-1 text-red-500">最后错误：{{ detail.data.last_push_error }}</p>
              <p v-if="detail.data.exception_reason" class="mt-1 text-amber-600">异常原因：{{ detail.data.exception_reason }}</p>
            </section>
          </div>

          <!-- 行项目（应发 / 实发） -->
          <section class="mt-4 rounded-lg border border-slate-100 p-3" data-testid="detail-items">
            <h4 class="mb-2 text-xs font-semibold text-slate-500">行项目</h4>
            <table class="w-full text-[13px]">
              <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                  <th class="px-2 py-1.5">商品</th>
                  <th class="w-40 px-2 py-1.5">平台 SKU</th>
                  <th class="w-32 px-2 py-1.5">WMS SKU</th>
                  <th class="w-16 px-2 py-1.5">应发</th>
                  <th class="w-16 px-2 py-1.5">实发</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in detail.data.items ?? []" :key="item.id" class="border-b border-slate-50">
                  <td class="px-2 py-1.5 text-black">{{ item.product_name }}</td>
                  <td class="px-2 py-1.5 font-mono text-black">{{ item.platform_sku_code }}</td>
                  <td class="px-2 py-1.5 font-mono text-black">{{ item.wms_sku_code }}</td>
                  <td class="px-2 py-1.5 text-black">{{ item.qty }}</td>
                  <td class="px-2 py-1.5 text-black">{{ item.shipped_qty }}</td>
                </tr>
                <tr v-if="!(detail.data.items ?? []).length">
                  <td colspan="5" class="px-2 py-6 text-center text-slate-400">无行项目</td>
                </tr>
              </tbody>
            </table>
          </section>

          <!-- 推送流水（摘要，完整报文去 WMS 日志页按 request_id 查） -->
          <section class="mt-4 rounded-lg border border-slate-100 p-3" data-testid="detail-logs">
            <h4 class="mb-2 text-xs font-semibold text-slate-500">最近调用流水</h4>
            <table class="w-full text-[13px]">
              <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                  <th class="w-20 px-2 py-1.5">方向</th>
                  <th class="px-2 py-1.5">接口</th>
                  <th class="w-16 px-2 py-1.5">结果</th>
                  <th class="px-2 py-1.5">错误</th>
                  <th class="w-40 px-2 py-1.5">时间</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="log in detail.data.logs ?? []" :key="log.id" class="border-b border-slate-50">
                  <td class="px-2 py-1.5 text-black">{{ log.direction_label }}</td>
                  <td class="px-2 py-1.5 font-mono text-black">{{ log.api_name }}</td>
                  <td class="px-2 py-1.5">
                    <span :class="log.success ? 'text-emerald-600' : 'text-red-500'">{{ log.success ? '成功' : '失败' }}</span>
                  </td>
                  <td class="max-w-60 truncate px-2 py-1.5 text-black" :title="log.error_msg || ''">{{ log.error_msg || '-' }}</td>
                  <td class="px-2 py-1.5 text-black">{{ log.created_at }}</td>
                </tr>
                <tr v-if="!(detail.data.logs ?? []).length">
                  <td colspan="5" class="px-2 py-6 text-center text-slate-400">暂无调用记录</td>
                </tr>
              </tbody>
            </table>
          </section>
        </template>
      </div>
    </div>

    <!-- 取消确认（必须填理由） -->
    <ConfirmDialog
      :open="!!confirmState"
      :title="confirmState?.title ?? ''"
      :message="confirmState?.message ?? ''"
      danger
      @cancel="confirmState = null"
      @confirm="doCancel"
    >
      <template #default>
        <textarea
          v-if="confirmState"
          v-model="confirmState.reason"
          rows="2"
          placeholder="取消原因（必填，最多 200 字）"
          class="mt-3 w-full rounded border border-slate-200 px-2 py-1.5 text-[13px] text-black outline-none focus:border-[#1677ff]"
          data-testid="cancel-reason"
        ></textarea>
      </template>
    </ConfirmDialog>
  </div>
</template>
