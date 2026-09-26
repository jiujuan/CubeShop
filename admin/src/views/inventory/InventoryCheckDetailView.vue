<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  cancelInventoryCheck,
  exportInventoryCheck,
  getInventoryCheck,
  importInventoryCount,
  postInventoryCheck,
  recordInventoryCount,
  CHECK_ITEM_STATUS_LABELS,
  type CheckItemStatus,
  type InventoryCheckBrief,
  type InventoryCheckRow,
} from '@/api/inventory-check'
import { Download, Upload } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'

/**
 * 库存盘点详情：统计 → 录入/导入实盘 → 过账
 *
 * 差异口径：表格「快照差异」= 实盘 - 开单时账面（供参考）；
 * 「实际调整」= 过账时按实时库存计算并写入库存的值，只有过账后才产生。
 */
const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)

const check = ref<InventoryCheckBrief | null>(null)
const items = ref<InventoryCheckRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(false)
const actionError = ref('')
const actionMessage = ref('')
const confirmAction = ref<'post' | 'cancel' | null>(null)

const itemStatus = ref<'' | CheckItemStatus>('')
const onlyDiff = ref(false)
const keyword = ref('')
const file = ref<File | null>(null)
const importInput = ref<HTMLInputElement | null>(null)

const isOpen = computed(() => check.value !== null && ['draft', 'counting'].includes(check.value.status))

async function load() {
  loading.value = true
  try {
    const { data } = await getInventoryCheck(id, {
      item_status: itemStatus.value || undefined,
      only_diff: onlyDiff.value ? 1 : undefined,
      keyword: keyword.value.trim() || undefined,
      page: pagination.value.page,
      page_size: pagination.value.page_size,
    })
    check.value = data.data.check
    items.value = data.data.items.list ?? []
    pagination.value = data.data.items.pagination ?? pagination.value
  } finally {
    loading.value = false
  }
}

function search() {
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages) return
  pagination.value.page = p
  load()
}

/** 单行实盘录入：失焦即保存 */
async function saveCount(row: InventoryCheckRow, value: string) {
  if (!isOpen.value) return
  const qty = Number(value)
  if (!Number.isInteger(qty) || qty < 0) {
    actionError.value = '实盘数量必须是 ≥0 的整数'
    return
  }
  actionError.value = ''
  await recordInventoryCount(id, [{ item_id: row.id, counted_qty: qty }])
  await load()
}

async function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const selected = input.files?.[0] ?? null
  file.value = selected && /\.(xlsx|xls)$/i.test(selected.name) ? selected : null
  input.value = ''
  if (selected && !file.value) {
    actionError.value = '仅支持 .xlsx / .xls 文件'
    return
  }
  if (file.value) {
    await doImport()
  }
}

async function doImport() {
  if (!file.value) {
    actionError.value = '请先选择实盘文件'
    return
  }
  actionError.value = ''
  try {
    const { data } = await importInventoryCount(id, file.value)
    actionMessage.value = `已导入 ${data.data.updated} 行实盘数量`
    await load()
  } catch (err) {
    actionError.value = (err as Error).message || '导入失败'
  }
}

async function doExport() {
  try {
    await exportInventoryCheck(id)
  } catch {
    // 全局 toast 已提示
  }
}

async function runConfirmed() {
  const action = confirmAction.value
  confirmAction.value = null
  if (!action) return

  actionError.value = ''
  try {
    if (action === 'post') {
      await postInventoryCheck(id)
      actionMessage.value = '过账完成'
    } else {
      await cancelInventoryCheck(id)
      actionMessage.value = '盘点单已作废'
    }
    await load()
  } catch (err) {
    actionError.value = (err as Error).message || '操作失败'
  }
}

function itemStatusClass(status: CheckItemStatus): string {
  return {
    pending: 'text-slate-400',
    counted: 'text-slate-600',
    posted: 'text-green-600',
    skipped: 'text-red-500',
  }[status]
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <!-- 头部 -->
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">
          库存盘点 <span class="font-mono text-slate-500" data-testid="check-detail-no">{{ check?.check_no ?? '—' }}</span>
        </h2>
        <p class="mt-1 text-[13px] text-slate-500">
          {{ check?.title || '未命名盘点单' }} · {{ check?.scope_label }}<span v-if="check?.scope_value">（{{ check.scope_value }}）</span>
          · 状态：{{ check?.status_label }}
        </p>
      </div>
      <Button variant="outline" @click="router.push('/inventory-checks')">返回列表</Button>
    </div>

    <!-- 统计 -->
    <div v-if="check" class="mt-4 grid gap-3 text-[13px] md:grid-cols-4">
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="text-slate-500">明细行数</p>
        <p class="mt-1 text-lg font-semibold text-slate-800" data-testid="check-stat-items">{{ check.item_count }}</p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="text-slate-500">已盘点</p>
        <p class="mt-1 text-lg font-semibold text-slate-800" data-testid="check-stat-counted">{{ check.counted_count }}</p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="text-slate-500">有差异</p>
        <p class="mt-1 text-lg font-semibold" :class="check.diff_count ? 'text-red-500' : 'text-slate-800'" data-testid="check-stat-diff">
          {{ check.diff_count }}
        </p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="text-slate-500">合计差异数量</p>
        <p class="mt-1 text-lg font-semibold" :class="check.total_diff_qty ? 'text-red-500' : 'text-slate-800'" data-testid="check-stat-qty">
          {{ check.total_diff_qty }}
        </p>
      </div>
    </div>

    <!-- 工具条 -->
    <div class="mt-4 flex flex-wrap items-center gap-2 text-[13px]">
      <Button variant="outline" :disabled="!isOpen" data-testid="check-export" @click="doExport">
        <Download class="mr-1 h-4 w-4" /> 导出清单
      </Button>

      <input
        ref="importInput"
        type="file"
        accept=".xlsx,.xls"
        class="hidden"
        data-testid="check-import-input"
        @change="onFileChange"
      />
      <Button
        variant="outline"
        :disabled="!isOpen"
        data-testid="check-import"
        @click="importInput?.click()"
      >
        <Upload class="mr-1 h-4 w-4" /> 导入实盘
      </Button>

      <Button
        class="bg-[#1677ff] hover:bg-[#4096ff]"
        :disabled="!isOpen"
        data-testid="check-post"
        @click="confirmAction = 'post'"
      >
        过账
      </Button>
      <Button variant="outline" :disabled="!isOpen" data-testid="check-cancel" @click="confirmAction = 'cancel'">
        作废
      </Button>
    </div>

    <p v-if="actionMessage" class="mt-2 text-[13px] text-green-600" data-testid="check-action-ok">{{ actionMessage }}</p>
    <p v-if="actionError" class="mt-2 text-[13px] text-red-500" data-testid="check-action-error">{{ actionError }}</p>

    <!-- 明细筛选 -->
    <div class="mt-4 flex flex-wrap items-center gap-2 text-[13px]">
      <select
        v-model="itemStatus"
        class="w-36 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="check-item-status"
      >
        <option value="">全部行</option>
        <option v-for="(label, key) in CHECK_ITEM_STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <label class="flex items-center gap-1 text-slate-600">
        <input v-model="onlyDiff" type="checkbox" data-testid="check-only-diff" /> 只看有差异
      </label>
      <input
        v-model="keyword"
        type="text"
        placeholder="SKU编码 / 商品名"
        class="w-48 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="check-keyword"
        @keyup.enter="search"
      />
      <Button class="bg-[#1677ff] hover:bg-[#4096ff]" data-testid="check-item-search" @click="search">筛选</Button>
    </div>

    <!-- 明细表 -->
    <table class="mt-3 w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-100 text-left text-slate-500">
          <th class="px-3 py-2">SKU编码</th>
          <th class="px-3 py-2">商品 / 规格</th>
          <th class="px-3 py-2">开单账面</th>
          <th class="px-3 py-2">实盘数量</th>
          <th class="px-3 py-2">快照差异</th>
          <th class="px-3 py-2">实际调整</th>
          <th class="px-3 py-2">状态</th>
          <th class="px-3 py-2">备注</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="!items.length">
          <td colspan="8" class="px-3 py-6 text-center text-slate-400">暂无明细</td>
        </tr>
        <tr v-for="row in items" :key="row.id" class="border-b border-slate-50" :data-testid="`item-row-${row.id}`">
          <td class="px-3 py-2 font-mono text-slate-700">{{ row.sku_code }}</td>
          <td class="px-3 py-2 text-slate-700">
            {{ row.product_title || '—' }}
            <span v-if="row.specs_text" class="text-slate-400">（{{ row.specs_text }}）</span>
          </td>
          <td class="px-3 py-2 text-slate-600">{{ row.system_qty }}</td>
          <td class="px-3 py-2">
            <input
              v-if="isOpen"
              type="number"
              min="0"
              class="w-24 rounded-md border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]"
              :value="row.counted_qty ?? ''"
              :data-testid="`count-input-${row.id}`"
              @change="saveCount(row, ($event.target as HTMLInputElement).value)"
            />
            <span v-else class="text-slate-700">{{ row.counted_qty ?? '—' }}</span>
          </td>
          <td class="px-3 py-2" :class="row.counted_qty !== null && row.counted_qty !== row.system_qty ? 'text-amber-600' : 'text-slate-500'">
            {{ row.counted_qty === null ? '—' : row.counted_qty - row.system_qty }}
          </td>
          <td class="px-3 py-2" :class="row.diff_qty ? 'text-red-500' : 'text-slate-500'">{{ row.diff_qty ?? '—' }}</td>
          <td class="px-3 py-2" :class="itemStatusClass(row.status)">{{ row.status_label }}</td>
          <td class="px-3 py-2 text-slate-500">{{ row.remark || '—' }}</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <div v-if="pagination.total_pages > 1" class="mt-4 flex items-center justify-end gap-2 text-[13px]">
      <Button variant="outline" size="sm" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">上一页</Button>
      <span class="text-slate-500">{{ pagination.page }} / {{ pagination.total_pages }}</span>
      <Button variant="outline" size="sm" :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)">
        下一页
      </Button>
    </div>

    <!-- 二次确认 -->
    <div v-if="confirmAction" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" data-testid="check-confirm">
      <div class="w-[420px] rounded-lg bg-white p-5 shadow-lg">
        <h3 class="text-base font-semibold text-slate-800">
          {{ confirmAction === 'post' ? '确认过账？' : '确认作废？' }}
        </h3>
        <p class="mt-2 text-[13px] text-slate-600">
          <template v-if="confirmAction === 'post'">
            将按「实盘 - 过账时刻库存」的差异调整库存并写入库存流水，过账后盘点单不可再修改。
            可用库存不足（被未支付订单锁定）的行会被跳过并标红。
          </template>
          <template v-else>作废后该盘点单不可再录入或过账，不会产生任何库存变动。</template>
        </p>
        <div class="mt-4 flex justify-end gap-2">
          <Button variant="outline" data-testid="check-confirm-no" @click="confirmAction = null">取消</Button>
          <Button
            :class="confirmAction === 'post' ? 'bg-[#1677ff] hover:bg-[#4096ff]' : 'bg-red-500 hover:bg-red-600 text-white'"
            data-testid="check-confirm-yes"
            @click="runConfirmed"
          >
            {{ confirmAction === 'post' ? '确认过账' : '确认作废' }}
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>
