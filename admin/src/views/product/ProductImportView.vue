<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  downloadProductImportTemplate,
  importProducts,
  type ProductImportFailedRow,
  type ProductImportMode,
  type ProductImportResult,
} from '@/api/product'
import { Download, FileSpreadsheet, Upload } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'

/**
 * 商品批量导入（xlsx）
 *
 * 与「批量发货」同一套心智：下载模板 → 填写 → 上传 → 预校验全部通过才执行 → 返回逐行失败明细。
 * 两种模式共用一份模板：create 新建商品（含 SKU 与库存），update 按 SKU 编码调价/调库存。
 */
const mode = ref<ProductImportMode>('create')
const fileInput = ref<HTMLInputElement | null>(null)
const file = ref<File | null>(null)
const uploading = ref(false)
const downloading = ref(false)
const result = ref<ProductImportResult | null>(null)
const resultError = ref('')

const isCreate = computed(() => mode.value === 'create')

/** 行数上限提示：硬约束必须在上传前就让人看见，不能等传到一半才报错 */
const limitNote = computed(() =>
  isCreate.value
    ? '单次最多 300 行。同一「商品编码」的多行会合并为一个商品，每行生成一个 SKU；编码留空时按商品标题合并。'
    : '单次最多 1000 行。只认「SKU编码 / 销售价 / 库存 / SKU状态」四列，其余列忽略；未填写的列保持原值。',
)

function switchMode(next: ProductImportMode) {
  mode.value = next
  result.value = null
  resultError.value = ''
  file.value = null
}

function pickFile() {
  fileInput.value?.click()
}

function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const selected = input.files?.[0] ?? null
  file.value = selected && /\.(xlsx|xls)$/i.test(selected.name) ? selected : null
  result.value = null
  resultError.value = ''
  if (selected && !file.value) {
    resultError.value = '仅支持 .xlsx / .xls 文件'
  }
  // 允许重复选择同一文件
  input.value = ''
}

async function doDownloadTemplate() {
  downloading.value = true
  try {
    await downloadProductImportTemplate(mode.value)
  } catch {
    // 全局 toast 已提示
  } finally {
    downloading.value = false
  }
}

async function doUpload() {
  if (!file.value || uploading.value) return
  uploading.value = true
  result.value = null
  resultError.value = ''
  try {
    const { data } = await importProducts(file.value, mode.value)
    result.value = data.data
  } catch (err) {
    // 预校验失败（40000）：后端带 failed 明细，直接展示
    const payload = (err as { data?: { data?: ProductImportResult } }).data
    const failed = payload?.data?.failed
    if (Array.isArray(failed)) {
      result.value = {
        mode: mode.value,
        total: payload?.data?.total ?? failed.length,
        success: 0,
        products: 0,
        failed,
      }
    } else {
      resultError.value = (err as Error).message || '上传失败'
    }
  } finally {
    uploading.value = false
  }
}

/** 失败明细导出为 CSV（UTF-8 BOM，Excel 直接打开不乱码） */
function downloadFailed(rows: ProductImportFailedRow[]) {
  const header = ['行号', '商品编码', 'SKU编码', '失败原因']
  const body = rows.map((r) => [r.row, r.code || '', r.sku_code || '', r.reason])
  const csv = [header, ...body]
    .map((cols) => cols.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(','))
    .join('\r\n')

  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = '商品导入失败明细.csv'
  link.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-slate-800">商品批量导入</h2>
    <p class="mt-1 text-[13px] text-slate-500">
      按模板整理商品数据，一次性导入。校验全部通过后单事务执行；任一行失败则全部不执行。
    </p>

    <!-- 模式切换 -->
    <div class="mt-4 flex flex-wrap items-center gap-2 text-[13px]">
      <button
        class="rounded-md px-4 py-1.5 transition"
        data-testid="import-mode-create"
        :class="isCreate ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
        @click="switchMode('create')"
      >
        新建商品
      </button>
      <button
        class="rounded-md px-4 py-1.5 transition"
        data-testid="import-mode-update"
        :class="!isCreate ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
        @click="switchMode('update')"
      >
        更新价格 / 库存
      </button>
    </div>

    <!-- 行数上限提示（醒目） -->
    <div
      class="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800"
      data-testid="import-limit-note"
    >
      <span class="mt-0.5 font-semibold">注意</span>
      <div>
        <p class="font-medium">单次导入行数上限：新建商品 300 行、更新价格 / 库存 1000 行，超出请拆分文件后分次导入。</p>
        <p class="mt-1 text-amber-700">{{ limitNote }}</p>
      </div>
    </div>

    <!-- 步骤说明 -->
    <div class="mt-4 grid gap-3 text-[13px] md:grid-cols-3">
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="font-medium text-slate-700">① 下载模板</p>
        <p class="mt-1 text-slate-500">表头固定 15 列，禁止修改或调整列序；附填写说明、分类对照、品牌对照三个 sheet。</p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="font-medium text-slate-700">② 填写数据</p>
        <p class="mt-1 text-slate-500">
          {{ isCreate ? '规格写法：颜色:白色|尺码:M；主图填已上传的相对路径（暂不支持外链图片）。' : '至少填写销售价 / 库存 / SKU状态 中的一项，SKU 编码必须已存在。' }}
        </p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="font-medium text-slate-700">③ 上传导入</p>
        <p class="mt-1 text-slate-500">上传后自动校验并执行；库存变更会写入库存流水，可对账。</p>
      </div>
    </div>

    <!-- 操作区 -->
    <div class="mt-4 flex flex-wrap items-center gap-3">
      <Button variant="outline" :disabled="downloading" data-testid="import-download-template" @click="doDownloadTemplate">
        <Download class="mr-1 h-4 w-4" /> {{ downloading ? '下载中…' : '下载模板' }}
      </Button>

      <input
        ref="fileInput"
        type="file"
        accept=".xlsx,.xls"
        class="hidden"
        data-testid="import-file-input"
        @change="onFileChange"
      />
      <Button variant="outline" data-testid="import-pick-file" @click="pickFile">
        <FileSpreadsheet class="mr-1 h-4 w-4" /> {{ file ? file.name : '选择文件' }}
      </Button>

      <Button
        class="bg-[#1677ff] hover:bg-[#4096ff]"
        :disabled="!file || uploading"
        data-testid="import-upload"
        @click="doUpload"
      >
        <Upload class="mr-1 h-4 w-4" /> {{ uploading ? '导入中…' : '上传并导入' }}
      </Button>
    </div>
    <p v-if="resultError" class="mt-2 text-[13px] text-red-500" data-testid="import-error">{{ resultError }}</p>

    <!-- 结果：成功 -->
    <div
      v-if="result && !result.failed.length"
      data-testid="import-success"
      class="mt-4 rounded-lg border border-green-200 bg-green-50 p-4 text-[13px]"
    >
      <p class="font-medium text-green-600">
        {{ isCreate ? `导入完成：新建 ${result.products} 个商品、共 ${result.success} 个 SKU` : `导入完成：更新 ${result.success} 个 SKU` }}
      </p>
    </div>

    <!-- 结果：失败明细 -->
    <div v-if="result && result.failed.length" data-testid="import-failed" class="mt-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="rounded-lg border border-red-200 bg-red-50 p-3 text-[13px] font-medium text-red-600">
          校验未全部通过，未导入任何数据（失败 {{ result.failed.length }} / 共 {{ result.total }} 行）
        </p>
        <Button variant="outline" data-testid="import-download-failed" @click="downloadFailed(result.failed)">
          <Download class="mr-1 h-4 w-4" /> 下载失败明细
        </Button>
      </div>
      <table class="mt-2 w-full text-[13px]">
        <thead>
          <tr class="border-b border-slate-200 text-left text-slate-500">
            <th class="w-16 px-3 py-1.5">行号</th>
            <th class="w-40 px-3 py-1.5">商品编码</th>
            <th class="w-40 px-3 py-1.5">SKU编码</th>
            <th class="px-3 py-1.5">失败原因</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, idx) in result.failed" :key="idx" class="border-b border-slate-100">
            <td class="px-3 py-1.5 text-slate-500">{{ row.row }}</td>
            <td class="px-3 py-1.5 font-mono text-black">{{ row.code || '—' }}</td>
            <td class="px-3 py-1.5 font-mono text-slate-600">{{ row.sku_code || '—' }}</td>
            <td class="px-3 py-1.5 text-red-500">{{ row.reason }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
