<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Copy, Search, X } from 'lucide-vue-next'

import { getWmsLog, getWmsLogs, type WmsApiLogDetail, type WmsApiLogRow } from '@/api/wms'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * WMS 调用日志（WMS 计划 P6 / F5，权限 wms.config.manage）
 *
 * 排障入口：按方向/接口/成功失败/时间/关键词筛选，详情弹窗展示**脱敏后**报文。
 * 列表与详情都支持复制 `request_id`——后端日志与菜鸟工单都靠它对账。
 */
const list = ref<WmsApiLogRow[]>([])
const pagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')

const direction = ref<'' | 'outbound' | 'inbound'>('')
const apiName = ref('')
const success = ref<'' | '0' | '1'>('')
const keyword = ref('')
const createdFrom = ref('')
const createdTo = ref('')

const detail = ref<{ open: boolean; loading: boolean; tip: string; data: WmsApiLogDetail | null }>({
  open: false, loading: false, tip: '', data: null,
})

async function load(page = 1) {
  loading.value = true
  tip.value = ''
  try {
    const { data } = await getWmsLogs({
      direction: direction.value || undefined,
      api_name: apiName.value.trim() || undefined,
      success: success.value === '' ? undefined : success.value === '1',
      keyword: keyword.value.trim() || undefined,
      created_from: createdFrom.value || undefined,
      created_to: createdTo.value || undefined,
      page,
      page_size: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } catch (e) {
    tip.value = (e as { message?: string })?.message ?? '加载失败'
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

async function openDetail(id: number) {
  detail.value = { open: true, loading: true, tip: '', data: null }
  try {
    const { data } = await getWmsLog(id)
    detail.value.data = data.data
  } catch (e) {
    detail.value.tip = (e as { message?: string })?.message ?? '加载失败'
  } finally {
    detail.value.loading = false
  }
}

async function copyText(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    tip.value = `已复制：${text}`
  } catch {
    tip.value = '复制失败，请手动选中'
  }
}

/** 报文统一以可读 JSON 呈现（后端已脱敏，这里只做格式化） */
function pretty(value: unknown): string {
  if (value === null || value === undefined) return '-'
  if (typeof value === 'string') return value
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">WMS 调用日志</h2>
    </div>

    <p v-if="tip" class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-600" data-testid="tip">{{ tip }}</p>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <select v-model="direction" class="h-8 rounded border border-slate-200 px-2 text-black" data-testid="filter-direction">
        <option value="">全部方向</option>
        <option value="outbound">出站</option>
        <option value="inbound">入站</option>
      </select>
      <select v-model="success" class="h-8 rounded border border-slate-200 px-2 text-black" data-testid="filter-success">
        <option value="">全部结果</option>
        <option value="1">成功</option>
        <option value="0">失败</option>
      </select>
      <input v-model="apiName" placeholder="接口名" class="h-8 w-44 rounded border border-slate-200 px-2 text-black" data-testid="filter-api" />
      <input v-model="keyword" placeholder="request_id / 单号 / 错误" class="h-8 w-52 rounded border border-slate-200 px-2 text-black" data-testid="filter-keyword" />
      <input v-model="createdFrom" type="date" class="h-8 rounded border border-slate-200 px-2 text-black" data-testid="filter-from" />
      <span class="text-slate-400">至</span>
      <input v-model="createdTo" type="date" class="h-8 rounded border border-slate-200 px-2 text-black" data-testid="filter-to" />
      <button class="flex h-8 items-center gap-1 rounded bg-[#1677ff] px-3 text-white hover:bg-[#4096ff]" data-testid="search" @click="search">
        <Search class="h-3.5 w-3.5" /> 查询
      </button>
    </div>

    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-16 px-3 py-1.5">方向</th>
          <th class="w-44 px-3 py-1.5">接口</th>
          <th class="px-3 py-1.5">request_id</th>
          <th class="w-32 px-3 py-1.5">单号</th>
          <th class="w-20 px-3 py-1.5">HTTP</th>
          <th class="w-16 px-3 py-1.5">结果</th>
          <th class="w-40 px-3 py-1.5">时间</th>
          <th class="w-24 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`row-${row.id}`">
          <td class="px-3 py-1.5 text-black">{{ row.direction_label }}</td>
          <td class="px-3 py-1.5 font-mono text-black">{{ row.api_name }}</td>
          <td class="px-3 py-1.5 font-mono text-black">
            {{ row.request_id || '-' }}
            <button
              v-if="row.request_id"
              class="ml-1 text-[#1677ff] hover:underline"
              :data-testid="`copy-${row.id}`"
              title="复制 request_id"
              @click="copyText(row.request_id)"
            ><Copy class="inline h-3 w-3" /></button>
          </td>
          <td class="px-3 py-1.5 font-mono text-black">{{ row.biz_no || '-' }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.http_status ?? '-' }}</td>
          <td class="px-3 py-1.5">
            <span :class="row.success ? 'text-emerald-600' : 'text-red-500'">{{ row.success ? '成功' : '失败' }}</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ row.created_at }}</td>
          <td class="px-3 py-1.5">
            <button class="text-[#1677ff] hover:underline" :data-testid="`detail-${row.id}`" @click="openDetail(row.id)">详情</button>
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

    <!-- 报文详情 -->
    <div
      v-if="detail.open"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-8"
      @click.self="detail.open = false"
    >
      <div class="w-full max-w-4xl rounded-lg bg-white p-5 shadow-lg" data-testid="log-detail">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="text-base font-semibold text-slate-800">调用详情</h3>
          <button class="text-slate-400 hover:text-slate-600" data-testid="detail-close" @click="detail.open = false"><X class="h-4 w-4" /></button>
        </div>

        <p v-if="detail.tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ detail.tip }}</p>
        <div v-if="detail.loading" class="py-10"><LoadingSpinner /></div>

        <template v-else-if="detail.data">
          <div class="mb-3 flex flex-wrap items-center gap-3 text-[13px] text-black">
            <span>{{ detail.data.direction_label }}｜{{ detail.data.api_name }}</span>
            <span class="font-mono">{{ detail.data.request_id || '-' }}</span>
            <span :class="detail.data.success ? 'text-emerald-600' : 'text-red-500'">{{ detail.data.success ? '成功' : '失败' }}</span>
            <button
              v-if="detail.data.request_id"
              class="text-[#1677ff] hover:underline"
              data-testid="detail-copy"
              @click="copyText(detail.data.request_id!)"
            >复制 request_id</button>
            <span v-if="detail.data.error_msg" class="text-red-500">{{ detail.data.error_msg }}</span>
          </div>

          <div class="grid gap-3 md:grid-cols-2">
            <section>
              <h4 class="mb-1.5 text-xs font-semibold text-slate-500">请求报文（已脱敏）</h4>
              <pre class="max-h-80 overflow-auto rounded border border-slate-100 bg-slate-50 p-3 text-[12px] leading-5 text-black" data-testid="request-body">{{ pretty(detail.data.request_body) }}</pre>
            </section>
            <section>
              <h4 class="mb-1.5 text-xs font-semibold text-slate-500">响应报文（已脱敏）</h4>
              <pre class="max-h-80 overflow-auto rounded border border-slate-100 bg-slate-50 p-3 text-[12px] leading-5 text-black" data-testid="response-body">{{ pretty(detail.data.response_body) }}</pre>
            </section>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
