<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, FileUp, Plus, Search, Trash2, Upload } from 'lucide-vue-next'

import {
  deleteWmsSkuMapping,
  getWmsSkuMappings,
  importWmsSkuMappings,
  type WmsImportResult,
  type WmsImportRow,
  type WmsSkuMappingRow,
} from '@/api/wms'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * WMS SKU 映射（WMS 计划 P0 / F6，权限 wms.config.manage）
 *
 * `sku_mapping_mode=manual` 时，推送前必须在此命中映射，否则后端拒绝推送（40009）。
 * 批量导入逐行校验 sku_code 存在性并回传行号结果，**不整批回滚**——运营按提示改完重传即可。
 */
const route = useRoute()
const router = useRouter()
const warehouseId = Number(route.params.id)

const loading = ref(true)
const list = ref<WmsSkuMappingRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const keyword = ref('')

// 手工新增
const showAdd = ref(false)
const adding = ref(false)
const addForm = reactive({ sku_code: '', wms_sku_code: '', barcode: '' })

// 批量导入
const fileName = ref('')
const importing = ref(false)
const importResult = ref<WmsImportResult | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

const removing = ref<WmsSkuMappingRow | null>(null)

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getWmsSkuMappings(warehouseId, {
      keyword: keyword.value || undefined,
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

function goPage(page: number) {
  load(page)
}

function specsText(specs: Record<string, string> | null): string {
  if (!specs) return '—'
  return Object.entries(specs).map(([k, v]) => `${k}：${v}`).join(' / ') || '—'
}

async function doAdd() {
  if (adding.value) return
  if (!addForm.sku_code.trim() || !addForm.wms_sku_code.trim()) return
  adding.value = true
  try {
    await importWmsSkuMappings(warehouseId, [{
      sku_code: addForm.sku_code.trim(),
      wms_sku_code: addForm.wms_sku_code.trim(),
      barcode: addForm.barcode.trim() || undefined,
    }])
    showAdd.value = false
    Object.assign(addForm, { sku_code: '', wms_sku_code: '', barcode: '' })
    await load(pagination.value.page)
  } catch {
    // 拦截器已提示
  } finally {
    adding.value = false
  }
}

/**
 * 解析 CSV（列：sku_code,wms_sku_code,barcode）
 *
 * 刻意写得保守：跳过表头、跳过空行、忽略无法解析的行——
 * 真正的合法性由后端逐行判定并回传行号，前端不重复造规则。
 */
function parseCsv(text: string): WmsImportRow[] {
  const lines = text.replace(/^\uFEFF/, '').split(/\r?\n/)

  const rows: WmsImportRow[] = []
  lines.forEach((raw) => {
    const line = raw.trim()
    if (!line) return

    const cols = line.split(',').map((c) => c.trim().replace(/^"(.*)"$/, '$1'))
    const first = (cols[0] ?? '').toLowerCase()
    if (first === 'sku_code' || first === '平台sku编码') return // 表头

    const skuCode = cols[0] ?? ''
    const wmsSkuCode = cols[1] ?? ''
    if (!skuCode || !wmsSkuCode) return

    rows.push({ sku_code: skuCode, wms_sku_code: wmsSkuCode, barcode: cols[2] || undefined })
  })

  return rows
}

async function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  fileName.value = file.name
  importResult.value = null

  const text = await file.text()
  const rows = parseCsv(text)

  if (!rows.length) {
    importResult.value = { total: 0, success_count: 0, failed_count: 0, results: [{ line: 0, sku_code: '—', success: false, message: '文件中没有可解析的数据行（需为 sku_code,wms_sku_code[,barcode]）' }] }
    input.value = ''
    return
  }

  importing.value = true
  try {
    const { data } = await importWmsSkuMappings(warehouseId, rows)
    importResult.value = data.data
    await load(1)
  } catch {
    // 拦截器已提示
  } finally {
    importing.value = false
    input.value = ''
  }
}

async function doRemove() {
  if (!removing.value?.sku_id) return
  try {
    await deleteWmsSkuMapping(warehouseId, removing.value.sku_id)
    removing.value = null
    await load(pagination.value.page)
  } catch {
    // 拦截器已提示，保留弹窗让操作者可重试
  }
}

onMounted(() => load())
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <button class="flex items-center gap-1 text-[13px] text-slate-500 hover:text-[#1677ff]" data-testid="sku-mapping-back" @click="router.push('/wms/warehouses')">
          <ArrowLeft class="h-4 w-4" /> 返回仓库列表
        </button>
        <h2 class="text-lg font-semibold text-slate-800">SKU 映射</h2>
      </div>
      <div class="flex items-center gap-2">
        <input ref="fileInput" type="file" accept=".csv,.txt" class="hidden" data-testid="sku-mapping-file" @change="onFileChange" />
        <Button
          class="border border-slate-300 bg-white text-slate-600 hover:bg-slate-50"
          :disabled="importing"
          data-testid="sku-mapping-import"
          @click="fileInput?.click()"
        >
          <FileUp class="mr-1 h-4 w-4" /> {{ importing ? '导入中…' : '批量导入 CSV' }}
        </Button>
        <Button class="bg-[#1677ff] hover:bg-[#4096ff]" data-testid="sku-mapping-add" @click="showAdd = true">
          <Plus class="mr-1 h-4 w-4" /> 新增映射
        </Button>
      </div>
    </div>

    <!-- 导入结果（逐行） -->
    <div v-if="importResult" class="mb-4 rounded-lg border border-slate-100 p-3" data-testid="sku-mapping-import-result">
      <div class="mb-2 flex items-center gap-3 text-[13px]">
        <span class="font-medium text-slate-700">导入结果</span>
        <span class="text-green-600">成功 {{ importResult.success_count }}</span>
        <span :class="importResult.failed_count ? 'text-red-500' : 'text-slate-400'">失败 {{ importResult.failed_count }}</span>
        <span v-if="fileName" class="text-xs text-slate-400">文件：{{ fileName }}</span>
        <button class="ml-auto text-xs text-slate-400 hover:text-slate-600" @click="importResult = null">关闭</button>
      </div>
      <table class="w-full text-xs">
        <thead>
          <tr class="border-b border-slate-100 text-left text-slate-400">
            <th class="w-16 px-2 py-1">行号</th>
            <th class="px-2 py-1">SKU 编码</th>
            <th class="px-2 py-1">结果</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(r, i) in importResult.results" :key="i" class="border-b border-slate-50">
            <td class="px-2 py-1 text-slate-400">{{ r.line || '—' }}</td>
            <td class="px-2 py-1 font-mono text-slate-600">{{ r.sku_code }}</td>
            <td class="px-2 py-1" :class="r.success ? 'text-green-600' : 'text-red-500'">{{ r.message }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 筛选 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="keyword"
        type="text"
        placeholder="平台编码 / WMS 编码 / 条码"
        class="w-56 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="search"
      />
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" @click="search">
        <Search class="mr-1 h-4 w-4" /> 搜索
      </Button>
      <span class="ml-2 text-xs text-slate-400">CSV 列格式：sku_code,wms_sku_code,barcode（首行可为表头）</span>
    </div>

    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">平台 SKU 编码</th>
          <th class="px-3 py-1.5">商品 / 规格</th>
          <th class="px-3 py-1.5">WMS 货品编码</th>
          <th class="px-3 py-1.5">条码</th>
          <th class="w-20 px-3 py-1.5">状态</th>
          <th class="w-24 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`mapping-row-${row.platform_sku_code}`">
          <td class="px-3 py-1.5 font-mono text-black">{{ row.platform_sku_code }}</td>
          <td class="px-3 py-1.5 text-slate-500">
            {{ row.product_title || '—' }}
            <span class="text-slate-400">（{{ specsText(row.sku_specs) }}）</span>
          </td>
          <td class="px-3 py-1.5 font-mono text-black">{{ row.wms_sku_code }}</td>
          <td class="px-3 py-1.5 font-mono text-slate-500">{{ row.barcode || '—' }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="row.status === 1 ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'">
              {{ row.status === 1 ? '启用' : '停用' }}
            </span>
          </td>
          <td class="px-3 py-1.5">
            <button
              class="flex items-center gap-0.5 text-red-500 hover:underline"
              :data-testid="`mapping-delete-${row.platform_sku_code}`"
              @click="removing = row"
            >
              <Trash2 class="h-3 w-3" /> 删除
            </button>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="6"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="6" class="px-3 py-12 text-center text-slate-400">暂无映射</td>
        </tr>
      </tbody>
    </table>

    <TablePagination :pagination="pagination" @change="goPage" />

    <!-- 新增映射弹窗 -->
    <div v-if="showAdd" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="showAdd = false">
      <div class="w-full max-w-sm rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">新增 SKU 映射</h3>

        <label class="mt-4 block text-xs text-slate-500">平台 SKU 编码 <span class="text-red-500">*</span></label>
        <input
          v-model="addForm.sku_code"
          type="text"
          placeholder="product_skus.sku_code"
          data-testid="mapping-form-sku-code"
          class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]"
        />

        <label class="mt-3 block text-xs text-slate-500">WMS 货品编码 <span class="text-red-500">*</span></label>
        <input
          v-model="addForm.wms_sku_code"
          type="text"
          placeholder="菜鸟 itemCode / 京东 skuId"
          data-testid="mapping-form-wms-code"
          class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]"
        />

        <label class="mt-3 block text-xs text-slate-500">条码（可选）</label>
        <input v-model="addForm.barcode" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]" />

        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="showAdd = false">取消</button>
          <button
            class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="!addForm.sku_code.trim() || !addForm.wms_sku_code.trim() || adding"
            data-testid="mapping-form-save"
            @click="doAdd"
          >
            <Upload class="mr-1 inline h-3 w-3" />{{ adding ? '保存中…' : '保存' }}
          </button>
        </div>
      </div>
    </div>

    <!-- 删除确认 -->
    <div v-if="removing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="removing = null">
      <div class="w-full max-w-xs rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">删除映射</h3>
        <p class="mt-2 text-[13px] text-slate-500">
          确认删除 <span class="font-mono text-black">{{ removing.platform_sku_code }}</span> 的映射？
          若仓库为「手工映射」模式，删除后该 SKU 将无法推送。
        </p>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="removing = null">取消</button>
          <button class="rounded-md bg-red-500 px-4 py-1.5 text-[13px] text-white hover:bg-red-600" data-testid="mapping-delete-confirm" @click="doRemove">删除</button>
        </div>
      </div>
    </div>
  </div>
</template>
