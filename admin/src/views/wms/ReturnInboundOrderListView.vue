<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RefreshCw, Search, X } from 'lucide-vue-next'

import {
  cancelReturnInboundOrder,
  getReturnInboundOrder,
  getReturnInboundOrders,
  getWmsWarehouseOptions,
  manualReceiveReturnInbound,
  pushReturnInboundOrder,
  INVENTORY_TYPE_LABELS,
  RETURN_INBOUND_STATUS_CLASS,
  RETURN_INBOUND_STATUS_LABELS,
  WMS_PROVIDER_LABELS,
  type InventoryType,
  type ReturnInboundOrderRow,
  type ReturnInboundStatus,
  type WmsProvider,
} from '@/api/wms'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 履约中心 / 退货入库单列表（WMS 计划 P6 / F3、F4）
 *
 * 权限 `wms.return.manage`。与发货单页的差异：
 * - 多「关联退款单号」「退货原因」列；
 * - 详情行项目含 `inventory_type`（ZP 正品 / CC 残次），残次不回可售库存；
 * - 「手动标记收货」是回传丢失的兜底，走与 WMS 回传完全相同的链路。
 */
const list = ref<ReturnInboundOrderRow[]>([])
const pagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')

const statusFilter = ref<'' | ReturnInboundStatus>('')
const warehouseId = ref<'' | number>('')
const inboundNo = ref('')
const refundNo = ref('')
const warehouses = ref<{ id: number; name: string }[]>([])

const pendingId = ref<number | null>(null)

const detail = ref<{ open: boolean; loading: boolean; tip: string; data: ReturnInboundOrderRow | null }>({
  open: false, loading: false, tip: '', data: null,
})

/** 确认状态：kind 区分「取消」「手工收货」两种动作 */
const confirmState = ref<{
  kind: 'cancel' | 'receive'
  id: number
  title: string
  message: string
  reason: string
  danger: boolean
} | null>(null)

/** 收货数量编辑：key = 行项目 id，缺省按应退数量足额实收 */
const receiveQty = ref<Record<number, number>>({})
const receiveType = ref<Record<number, InventoryType>>({})

const detailDiffHint = computed(() => {
  const d = detail.value.data
  if (!d?.items?.length) return ''
  const expected = d.items.reduce((sum, i) => sum + i.qty, 0)
  const received = d.items.reduce((sum, i) => sum + i.received_qty, 0)
  if (received === 0) return ''
  if (received > expected) return `超收 ${received - expected} 件：请核对后再确认，残次勾选将不回可售库存`
  if (received < expected) return `短收 ${expected - received} 件：差异部分不会退回库存`
  return '应退与实收一致'
})

async function load(page = 1) {
  loading.value = true
  tip.value = ''
  try {
    const { data } = await getReturnInboundOrders({
      status: statusFilter.value || undefined,
      warehouse_id: warehouseId.value === '' ? undefined : Number(warehouseId.value),
      inbound_no: inboundNo.value.trim() || undefined,
      refund_no: refundNo.value.trim() || undefined,
      page,
      page_size: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } catch (e) {
    tip.value = errorText(e)
  } finally {
    loading.value = false
  }
}

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
    warehouses.value = []
  }
}

function search() {
  load(1)
}

function goPage(page: number) {
  load(page)
}

async function doPush(row: ReturnInboundOrderRow) {
  pendingId.value = row.id
  try {
    await pushReturnInboundOrder(row.id)
    await load(pagination.value.page)
    if (detail.value.open && detail.value.data?.id === row.id) await openDetail(row.id)
  } catch (e) {
    tip.value = errorText(e)
  } finally {
    pendingId.value = null
  }
}

function askCancel(row: ReturnInboundOrderRow) {
  confirmState.value = {
    kind: 'cancel', id: row.id, title: '取消退货入库单',
    message: `取消后不再等待仓方收货（单号 ${row.inbound_no}），请填写原因：`,
    reason: '', danger: true,
  }
}

function askReceive(row: ReturnInboundOrderRow) {
  confirmState.value = {
    kind: 'receive', id: row.id, title: '手动标记收货',
    message: `仅为回传丢失时的兜底：确认后按填写的实收数量回库存并放款（单号 ${row.inbound_no}）。`,
    reason: '', danger: false,
  }
}

/** 打开收货确认时按「足额正品」预填 */
function prefillReceive() {
  const d = detail.value.data
  receiveQty.value = {}
  receiveType.value = {}
  for (const item of d?.items ?? []) {
    receiveQty.value[item.id] = item.qty
    receiveType.value[item.id] = 'ZP'
  }
}

async function doConfirm() {
  const state = confirmState.value
  if (!state) return

  if (state.kind === 'cancel') {
    const reason = state.reason.trim()
    if (!reason) {
      tip.value = '请填写取消原因'
      return
    }
    pendingId.value = state.id
    confirmState.value = null
    try {
      await cancelReturnInboundOrder(state.id, reason)
      await load(pagination.value.page)
      if (detail.value.open && detail.value.data?.id === state.id) await openDetail(state.id)
    } catch (e) {
      tip.value = errorText(e)
    } finally {
      pendingId.value = null
    }
    return
  }

  // 手工收货：缺省按足额正品，明细按编辑值提交
  const d = detail.value.data
  const items = d?.items ?? []
  const details = items.length
    ? items.map((item) => ({
        platform_sku_code: item.platform_sku_code,
        sku_id: item.sku_id ?? undefined,
        quantity: Number(receiveQty.value[item.id] ?? item.qty),
        inventory_type: receiveType.value[item.id] ?? 'ZP',
      }))
    : undefined

  pendingId.value = state.id
  confirmState.value = null
  try {
    await manualReceiveReturnInbound(state.id, { received_details: details, exception_reason: state.reason.trim() || undefined })
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
    const { data } = await getReturnInboundOrder(id)
    detail.value.data = data.data
    prefillReceive()
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
      <h2 class="text-lg font-semibold text-slate-800">退货入库单</h2>
    </div>

    <p v-if="tip" class="mb-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500" data-testid="tip">{{ tip }}</p>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <select v-model="statusFilter" class="h-8 rounded border border-slate-200 px-2 text-black" data-testid="filter-status">
        <option value="">全部状态</option>
        <option v-for="(label, key) in RETURN_INBOUND_STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <select v-model="warehouseId" class="h-8 rounded border border-slate-200 px-2 text-black" data-testid="filter-warehouse">
        <option value="">全部仓库</option>
        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>
      <input v-model="inboundNo" placeholder="入库单号" class="h-8 w-40 rounded border border-slate-200 px-2 text-black" data-testid="filter-inbound" />
      <input v-model="refundNo" placeholder="退款单号" class="h-8 w-40 rounded border border-slate-200 px-2 text-black" data-testid="filter-refund" />
      <button class="flex h-8 items-center gap-1 rounded bg-[#1677ff] px-3 text-white hover:bg-[#4096ff]" data-testid="search" @click="search">
        <Search class="h-3.5 w-3.5" /> 查询
      </button>
    </div>

    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">入库单号</th>
          <th class="px-3 py-1.5">退款单号</th>
          <th class="w-28 px-3 py-1.5">仓库</th>
          <th class="w-24 px-3 py-1.5">状态</th>
          <th class="px-3 py-1.5">退货原因</th>
          <th class="w-16 px-3 py-1.5">推送</th>
          <th class="w-48 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`row-${row.id}`">
          <td class="px-3 py-1.5 font-mono text-black">
            {{ row.inbound_no }}
            <span v-if="row.wms_inbound_no" class="ml-1 text-xs text-slate-400">{{ providerLabel(row.provider) }}</span>
          </td>
          <td class="px-3 py-1.5 font-mono text-black">{{ row.refund_no }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.warehouse_name || '-' }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="RETURN_INBOUND_STATUS_CLASS[row.status]">{{ RETURN_INBOUND_STATUS_LABELS[row.status] }}</span>
          </td>
          <td class="max-w-40 truncate px-3 py-1.5 text-black" :title="row.return_reason || ''">{{ row.return_reason || '-' }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.push_times }} 次</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-2">
              <button class="text-[#1677ff] hover:underline" :data-testid="`detail-${row.id}`" @click="openDetail(row.id)">详情</button>
              <template v-if="row.can_push">
                <span class="text-slate-200">|</span>
                <button
                  class="flex items-center gap-1 text-emerald-600 hover:underline disabled:opacity-40"
                  :disabled="pendingId === row.id"
                  :data-testid="`push-${row.id}`"
                  @click="doPush(row)"
                ><RefreshCw class="h-3 w-3" />{{ pendingId === row.id ? '重推中…' : '重推' }}</button>
              </template>
              <template v-if="row.can_manual_received">
                <span class="text-slate-200">|</span>
                <button class="text-purple-600 hover:underline" :data-testid="`receive-${row.id}`" @click="askReceive(row)">标记收货</button>
              </template>
              <template v-if="row.can_cancel">
                <span class="text-slate-200">|</span>
                <button class="text-red-500 hover:underline" :data-testid="`cancel-${row.id}`" @click="askCancel(row)">取消</button>
              </template>
            </div>
            <p v-if="row.last_push_error" class="mt-1 max-w-80 truncate text-xs text-red-500" :title="row.last_push_error" :data-testid="`error-${row.id}`">
              {{ row.last_push_error }}
            </p>
            <p v-else-if="row.exception_reason" class="mt-1 max-w-80 truncate text-xs text-amber-600" :title="row.exception_reason" :data-testid="`error-${row.id}`">
              {{ row.exception_reason }}
            </p>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="7"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="7" class="px-3 py-12 text-center text-slate-400" data-testid="empty">暂时无数据</td>
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
      <div class="w-full max-w-3xl rounded-lg bg-white p-5 shadow-lg" data-testid="rio-detail">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="text-base font-semibold text-slate-800">退货入库单详情</h3>
          <button class="text-slate-400 hover:text-slate-600" data-testid="detail-close" @click="detail.open = false"><X class="h-4 w-4" /></button>
        </div>

        <p v-if="detail.tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ detail.tip }}</p>
        <div v-if="detail.loading" class="py-10"><LoadingSpinner /></div>

        <template v-else-if="detail.data">
          <div class="grid gap-4 sm:grid-cols-2">
            <section class="rounded-lg border border-slate-100 p-3 text-[13px]">
              <h4 class="mb-2 text-xs font-semibold text-slate-500">基本信息</h4>
              <p class="text-black">入库单号：<span class="font-mono">{{ detail.data.inbound_no }}</span></p>
              <p class="mt-1 text-black">WMS 单号：<span class="font-mono">{{ detail.data.wms_inbound_no || '-' }}</span></p>
              <p class="mt-1 text-black">退款单号：<span class="font-mono">{{ detail.data.refund_no }}</span></p>
              <p class="mt-1 text-black">订单号：<span class="font-mono">{{ detail.data.order_no }}</span></p>
              <p class="mt-1 text-black">仓库：{{ detail.data.warehouse_name || '-' }}｜{{ providerLabel(detail.data.provider) }}</p>
              <p class="mt-1 text-black">退货原因：{{ detail.data.return_reason || '-' }}</p>
            </section>

            <section class="rounded-lg border border-slate-100 p-3 text-[13px]">
              <h4 class="mb-2 text-xs font-semibold text-slate-500">推送与收货</h4>
              <p class="text-black">
                状态：<span class="rounded px-1.5 py-0.5 text-xs" :class="RETURN_INBOUND_STATUS_CLASS[detail.data.status]">{{ RETURN_INBOUND_STATUS_LABELS[detail.data.status] }}</span>
              </p>
              <p class="mt-1 text-black">推送次数：{{ detail.data.push_times }} 次｜最后推送：{{ detail.data.last_push_at || '-' }}</p>
              <p class="mt-1 text-black">request_id：<span class="font-mono">{{ detail.data.push_request_id || '-' }}</span></p>
              <p class="mt-1 text-black">收货完成：{{ detail.data.received_at || '-' }}</p>
              <p v-if="detail.data.last_push_error" class="mt-1 text-red-500">最后错误：{{ detail.data.last_push_error }}</p>
              <p v-if="detail.data.exception_reason" class="mt-1 text-amber-600">异常原因：{{ detail.data.exception_reason }}</p>
            </section>
          </div>

          <!-- 行项目（应退 / 实收 / 残次标记） -->
          <section class="mt-4 rounded-lg border border-slate-100 p-3" data-testid="detail-items">
            <h4 class="mb-2 text-xs font-semibold text-slate-500">行项目（应退 / 实收）</h4>
            <table class="w-full text-[13px]">
              <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                  <th class="px-2 py-1.5">商品</th>
                  <th class="w-36 px-2 py-1.5">平台 SKU</th>
                  <th class="w-16 px-2 py-1.5">应退</th>
                  <th class="w-16 px-2 py-1.5">实收</th>
                  <th class="w-24 px-2 py-1.5">库存类型</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in detail.data.items ?? []" :key="item.id" class="border-b border-slate-50">
                  <td class="px-2 py-1.5 text-black">{{ item.product_name }}</td>
                  <td class="px-2 py-1.5 font-mono text-black">{{ item.platform_sku_code }}</td>
                  <td class="px-2 py-1.5 text-black">{{ item.qty }}</td>
                  <td class="px-2 py-1.5 text-black">{{ item.received_qty }}</td>
                  <td class="px-2 py-1.5">
                    <span
                      v-if="item.inventory_type"
                      class="rounded px-1.5 py-0.5 text-xs"
                      :class="item.inventory_type === 'CC' ? 'bg-amber-50 text-amber-600' : 'bg-emerald-50 text-emerald-600'"
                      :data-testid="`inv-type-${item.id}`"
                    >{{ INVENTORY_TYPE_LABELS[item.inventory_type] }}</span>
                    <span v-else class="text-slate-400">-</span>
                  </td>
                </tr>
                <tr v-if="!(detail.data.items ?? []).length">
                  <td colspan="5" class="px-2 py-6 text-center text-slate-400">无行项目</td>
                </tr>
              </tbody>
            </table>
            <p v-if="detailDiffHint" class="mt-2 text-xs" :class="detailDiffHint.startsWith('应退') ? 'text-slate-500' : 'text-amber-600'" data-testid="diff-hint">
              {{ detailDiffHint }}
            </p>
          </section>

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

    <!-- 取消 / 手工收货确认 -->
    <ConfirmDialog
      :open="!!confirmState"
      :title="confirmState?.title ?? ''"
      :message="confirmState?.message ?? ''"
      :danger="confirmState?.danger ?? false"
      @cancel="confirmState = null"
      @confirm="doConfirm"
    >
      <template v-if="confirmState?.kind === 'cancel'">
        <textarea
          v-model="confirmState!.reason"
          rows="2"
          placeholder="取消原因（必填，最多 200 字）"
          class="mt-3 w-full rounded border border-slate-200 px-2 py-1.5 text-[13px] text-black outline-none focus:border-[#1677ff]"
          data-testid="cancel-reason"
        ></textarea>
      </template>
      <template v-else-if="confirmState?.kind === 'receive'">
        <div class="mt-3 space-y-2" data-testid="receive-form">
          <div v-for="item in detail.data?.items ?? []" :key="item.id" class="flex items-center gap-2 text-[13px]">
            <span class="w-40 truncate text-slate-600" :title="item.product_name">{{ item.product_name }}</span>
            <span class="text-xs text-slate-400">应退 {{ item.qty }}</span>
            <input
              v-model.number="receiveQty[item.id]"
              type="number"
              min="0"
              class="h-7 w-16 rounded border border-slate-200 px-1.5 text-black"
            />
            <select v-model="receiveType[item.id]" class="h-7 rounded border border-slate-200 px-1.5 text-black">
              <option value="ZP">正品</option>
              <option value="CC">残次</option>
            </select>
          </div>
          <p class="text-xs text-slate-400">残次（CC）不回可售库存；数量留空按应退足额处理。</p>
          <input
            v-model="confirmState!.reason"
            placeholder="备注（可选，如差异说明）"
            class="h-8 w-full rounded border border-slate-200 px-2 text-[13px] text-black outline-none focus:border-[#1677ff]"
            data-testid="receive-remark"
          />
        </div>
      </template>
    </ConfirmDialog>
  </div>
</template>
