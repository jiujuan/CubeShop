<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RefreshCw, Search } from 'lucide-vue-next'

import {
  getWmsInventoryDiffs,
  getWmsInventorySnapshots,
  getWmsWarehouseOptions,
  resolveWmsInventoryDiff,
  syncWmsInventory,
  INVENTORY_DIFF_STATUS_CLASS,
  INVENTORY_DIFF_STATUS_LABELS,
  type InventoryDiffStatus,
  type WmsInventoryDiffRow,
  type WmsInventorySnapshotRow,
} from '@/api/wms'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 库存差异（WMS 计划 P6 / F6，权限 wms.config.manage）
 *
 * ⚠️「按 WMS 校准」会直接改写平台可售库存，是**不可逆的高危操作**，必须二次确认
 * 且默认勾选说明清楚改多少；不想改库存就点「忽略」只关单。
 * 上方附最近一次同步的快照，便于判断差异是不是刚产生的。
 */
const diffs = ref<WmsInventoryDiffRow[]>([])
const diffPagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')

const statusFilter = ref<'' | InventoryDiffStatus>('')
const warehouseId = ref<'' | number>('')
const keyword = ref('')
const warehouses = ref<{ id: number; name: string }[]>([])

const snapshots = ref<WmsInventorySnapshotRow[]>([])
const snapshotLoading = ref(false)
const syncing = ref(false)

const confirmState = ref<{ id: number; sku: string; diff: number; remark: string } | null>(null)
const pendingId = ref<number | null>(null)

async function load(page = 1) {
  loading.value = true
  tip.value = ''
  try {
    const { data } = await getWmsInventoryDiffs({
      status: statusFilter.value || undefined,
      warehouse_id: warehouseId.value === '' ? undefined : Number(warehouseId.value),
      keyword: keyword.value.trim() || undefined,
      page,
      page_size: diffPagination.value.page_size,
    })
    diffs.value = data.data.list
    diffPagination.value = data.data.pagination
  } catch (e) {
    tip.value = (e as { message?: string })?.message ?? '加载失败'
  } finally {
    loading.value = false
  }
}

async function loadSnapshots() {
  snapshotLoading.value = true
  try {
    const { data } = await getWmsInventorySnapshots({ page_size: 8 })
    snapshots.value = data.data.list
  } catch {
    snapshots.value = []
  } finally {
    snapshotLoading.value = false
  }
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

/** 手工同步：默认只写快照，不改平台库存 */
async function doSync() {
  syncing.value = true
  try {
    const { data } = await syncWmsInventory({
      warehouse_id: warehouseId.value === '' ? undefined : Number(warehouseId.value),
      apply: false,
    })
    const total = data.data.summary.reduce((sum, s) => sum + s.synced, 0)
    tip.value = data.data.errors.length
      ? `同步完成 ${total} 条，部分仓库失败：${data.data.errors.join('；')}`
      : `同步完成 ${total} 条（只写快照，未改平台库存）`
    await Promise.all([loadSnapshots(), load(diffPagination.value.page)])
  } catch (e) {
    tip.value = (e as { message?: string })?.message ?? '同步失败'
  } finally {
    syncing.value = false
  }
}

function askResolve(row: WmsInventoryDiffRow) {
  confirmState.value = { id: row.id, sku: row.sku_code || row.wms_sku_code, diff: row.diff, remark: '' }
}

async function doResolve() {
  const state = confirmState.value
  if (!state) return
  pendingId.value = state.id
  confirmState.value = null
  try {
    await resolveWmsInventoryDiff(state.id, { action: 'resolve', apply: true, remark: state.remark.trim() || undefined })
    tip.value = `已按 WMS 校准：${state.sku}（${state.diff > 0 ? '+' : ''}${state.diff}）`
    await load(diffPagination.value.page)
  } catch (e) {
    tip.value = (e as { message?: string })?.message ?? '校准失败'
  } finally {
    pendingId.value = null
  }
}

async function doIgnore(row: WmsInventoryDiffRow) {
  pendingId.value = row.id
  try {
    await resolveWmsInventoryDiff(row.id, { action: 'ignore' })
    tip.value = `已忽略：${row.sku_code || row.wms_sku_code}`
    await load(diffPagination.value.page)
  } catch (e) {
    tip.value = (e as { message?: string })?.message ?? '操作失败'
  } finally {
    pendingId.value = null
  }
}

function diffClass(diff: number): string {
  if (diff > 0) return 'text-emerald-600'
  if (diff < 0) return 'text-red-500'
  return 'text-slate-400'
}

onMounted(async () => {
  await loadWarehouses()
  await Promise.all([load(), loadSnapshots()])
})
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">库存差异</h2>
      <button
        class="flex items-center gap-1 rounded border border-slate-300 px-3 py-1.5 text-[13px] text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-40"
        :disabled="syncing"
        data-testid="sync"
        @click="doSync"
      ><RefreshCw class="h-3.5 w-3.5" />{{ syncing ? '同步中…' : '同步快照（不改库存）' }}</button>
    </div>

    <p v-if="tip" class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-600" data-testid="tip">{{ tip }}</p>

    <!-- 最近快照：判断差异是否刚产生 -->
    <section class="mb-5 rounded-lg border border-slate-100 p-3" data-testid="snapshots">
      <h3 class="mb-2 text-xs font-semibold text-slate-500">最近同步快照</h3>
      <div v-if="snapshotLoading" class="py-6"><LoadingSpinner /></div>
      <p v-else-if="!snapshots.length" class="py-4 text-center text-[13px] text-slate-400">暂无快照，点右上角同步一次</p>
      <table v-else class="w-full text-[13px]">
        <thead>
          <tr class="border-b border-slate-200 text-left text-slate-500">
            <th class="px-2 py-1.5">SKU</th>
            <th class="w-28 px-2 py-1.5">仓库</th>
            <th class="w-20 px-2 py-1.5">可用</th>
            <th class="w-20 px-2 py-1.5">锁定</th>
            <th class="w-40 px-2 py-1.5">同步时间</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in snapshots" :key="s.id" class="border-b border-slate-50">
            <td class="px-2 py-1.5 font-mono text-black">{{ s.sku_code || s.wms_sku_code }}</td>
            <td class="px-2 py-1.5 text-black">{{ s.warehouse_name || '-' }}</td>
            <td class="px-2 py-1.5 text-black">{{ s.available_qty }}</td>
            <td class="px-2 py-1.5 text-black">{{ s.locked_qty }}</td>
            <td class="px-2 py-1.5 text-black">{{ s.synced_at }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <select v-model="statusFilter" class="h-8 rounded border border-slate-200 px-2 text-black" data-testid="filter-status">
        <option value="">全部状态</option>
        <option v-for="(label, key) in INVENTORY_DIFF_STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <select v-model="warehouseId" class="h-8 rounded border border-slate-200 px-2 text-black" data-testid="filter-warehouse">
        <option value="">全部仓库</option>
        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>
      <input v-model="keyword" placeholder="SKU 编码" class="h-8 w-44 rounded border border-slate-200 px-2 text-black" data-testid="filter-keyword" />
      <button class="flex h-8 items-center gap-1 rounded bg-[#1677ff] px-3 text-white hover:bg-[#4096ff]" data-testid="search" @click="search">
        <Search class="h-3.5 w-3.5" /> 查询
      </button>
    </div>

    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">SKU</th>
          <th class="w-28 px-3 py-1.5">仓库</th>
          <th class="w-20 px-3 py-1.5">平台</th>
          <th class="w-20 px-3 py-1.5">WMS</th>
          <th class="w-20 px-3 py-1.5">差异</th>
          <th class="w-24 px-3 py-1.5">状态</th>
          <th class="w-40 px-3 py-1.5">时间</th>
          <th class="w-44 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in diffs" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`row-${row.id}`">
          <td class="px-3 py-1.5 font-mono text-black">{{ row.sku_code || row.wms_sku_code }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.warehouse_name || '-' }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.platform_qty }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.wms_qty }}</td>
          <td class="px-3 py-1.5 font-medium" :class="diffClass(row.diff)">{{ row.diff > 0 ? '+' : '' }}{{ row.diff }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="INVENTORY_DIFF_STATUS_CLASS[row.status]">{{ INVENTORY_DIFF_STATUS_LABELS[row.status] }}</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ row.created_at }}</td>
          <td class="px-3 py-1.5">
            <template v-if="row.status === 'pending'">
              <div class="flex items-center gap-2">
                <button
                  class="text-[#1677ff] hover:underline disabled:opacity-40"
                  :disabled="pendingId === row.id"
                  :data-testid="`resolve-${row.id}`"
                  @click="askResolve(row)"
                >按 WMS 校准</button>
                <span class="text-slate-200">|</span>
                <button
                  class="text-slate-500 hover:underline disabled:opacity-40"
                  :disabled="pendingId === row.id"
                  :data-testid="`ignore-${row.id}`"
                  @click="doIgnore(row)"
                >忽略</button>
              </div>
            </template>
            <span v-else class="text-xs text-slate-400">{{ row.handled_at || '已处置' }}</span>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="8"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!diffs.length && !loading">
          <td colspan="8" class="px-3 py-12 text-center text-slate-400" data-testid="empty">暂无差异，库存一致</td>
        </tr>
      </tbody>
    </table>

    <TablePagination :pagination="diffPagination" @change="goPage" />

    <ConfirmDialog
      :open="!!confirmState"
      title="按 WMS 校准库存"
      :message="confirmState
        ? `将把 ${confirmState.sku} 的平台可售库存调整为 WMS 数量（${confirmState.diff > 0 ? '增加' : '减少'} ${Math.abs(confirmState.diff)} 件）。此操作直接改写库存且不可撤销，确认继续？`
        : ''"
      danger
      confirm-text="确认校准"
      @cancel="confirmState = null"
      @confirm="doResolve"
    >
      <input
        v-if="confirmState"
        v-model="confirmState.remark"
        placeholder="处置备注（可选，最多 200 字）"
        class="mt-3 h-8 w-full rounded border border-slate-200 px-2 text-[13px] text-black outline-none focus:border-[#1677ff]"
        data-testid="resolve-remark"
      />
    </ConfirmDialog>
  </div>
</template>
