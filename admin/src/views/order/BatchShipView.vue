<script setup lang="ts">
import { ref } from 'vue'
import { batchShipImport, downloadBatchShipTemplate, type BatchShipResult } from '@/api/order'
import { Download, FileSpreadsheet, Upload } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'

/**
 * 批量发货（V1.1 T-044，E03）：模板下载 → 上传 xlsx → 结果展示
 *
 * 后端策略：预校验 → 全部行通过才执行（单事务）；失败时 success=0 且返回失败明细。
 */
const fileInput = ref<HTMLInputElement | null>(null)
const file = ref<File | null>(null)
const uploading = ref(false)
const downloading = ref(false)
const result = ref<BatchShipResult | null>(null)
const resultError = ref('')

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
    await downloadBatchShipTemplate()
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
    const { data } = await batchShipImport(file.value)
    result.value = data.data
  } catch (err) {
    // 40000 预校验失败：展示失败明细
    const data = (err as { data?: { success?: number; total?: number; failed?: BatchShipResult['failed'] } }).data
    if (data && Array.isArray(data.failed)) {
      result.value = { success: data.success ?? 0, total: data.total ?? data.failed.length, failed: data.failed }
    } else {
      resultError.value = (err as Error).message || '上传失败'
    }
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-slate-800">批量发货</h2>
    <p class="mt-1 text-[13px] text-slate-500">
      按模板整理待发货订单，一次性导入发货。校验全部通过后单事务执行；任一行失败则全部不执行。
    </p>

    <!-- 步骤说明 -->
    <div class="mt-4 grid gap-3 text-[13px] md:grid-cols-3">
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="font-medium text-slate-700">① 下载模板</p>
        <p class="mt-1 text-slate-500">表头固定三列：订单号、快递公司编码、快递单号（附快递公司编码对照 sheet）。</p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="font-medium text-slate-700">② 填写数据</p>
        <p class="mt-1 text-slate-500">单次最多 500 行；订单需为「待发货」状态，快递编码取自字典。</p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="font-medium text-slate-700">③ 上传发货</p>
        <p class="mt-1 text-slate-500">上传后自动校验并执行，用户端会收到发货通知。</p>
      </div>
    </div>

    <!-- 操作区 -->
    <div class="mt-4 flex flex-wrap items-center gap-3">
      <Button variant="outline" :disabled="downloading" data-testid="batch-download-template" @click="doDownloadTemplate">
        <Download class="mr-1 h-4 w-4" /> {{ downloading ? '下载中…' : '下载模板' }}
      </Button>

      <input ref="fileInput" type="file" accept=".xlsx,.xls" class="hidden" data-testid="batch-file-input" @change="onFileChange" />
      <Button variant="outline" data-testid="batch-pick-file" @click="pickFile">
        <FileSpreadsheet class="mr-1 h-4 w-4" /> {{ file ? file.name : '选择文件' }}
      </Button>

      <Button class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="!file || uploading" data-testid="batch-upload" @click="doUpload">
        <Upload class="mr-1 h-4 w-4" /> {{ uploading ? '上传中…' : '上传并发货' }}
      </Button>
    </div>
    <p v-if="resultError" class="mt-2 text-[13px] text-red-500">{{ resultError }}</p>

    <!-- 结果：成功 -->
    <div v-if="result && !result.failed.length" data-testid="batch-success" class="mt-4 rounded-lg border border-green-200 bg-green-50 p-4 text-[13px]">
      <p class="font-medium text-green-600">批量发货完成：成功 {{ result.success }} / 共 {{ result.total }} 单</p>
    </div>

    <!-- 结果：失败明细 -->
    <div v-if="result && result.failed.length" data-testid="batch-failed" class="mt-4">
      <p class="mb-2 rounded-lg border border-red-200 bg-red-50 p-3 text-[13px] font-medium text-red-600">
        校验未全部通过，未执行任何发货（失败 {{ result.failed.length }} / 共 {{ result.total }} 行）
      </p>
      <table class="w-full text-[13px]">
        <thead>
          <tr class="border-b border-slate-200 text-left text-slate-500">
            <th class="w-16 px-3 py-1.5">行号</th>
            <th class="px-3 py-1.5">订单号</th>
            <th class="px-3 py-1.5">失败原因</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, idx) in result.failed" :key="idx" class="border-b border-slate-100">
            <td class="px-3 py-1.5 text-slate-500">{{ row.row }}</td>
            <td class="px-3 py-1.5 font-mono text-black">{{ row.order_no || '—' }}</td>
            <td class="px-3 py-1.5 text-red-500">{{ row.message }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
