<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ChevronLeft, ChevronRight, Search } from 'lucide-vue-next'
import {
  getOrderLogs,
  OPERATOR_TYPE_LABELS,
  ORDER_STATUS_LABELS,
  type OrderLogRow,
  type OrderStatus,
} from '@/api/order'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 订单状态流水（API 文档 8.13，权限 order.log，只读）
 * 记录谁在何时把订单从什么状态改到什么状态；唯一写入点是下单/支付/发货等状态机。
 * 支持从订单详情跳转过来：/order-logs?order_no=xxx 自动预填并查询。
 */
const route = useRoute()
const loading = ref(true)
const list = ref<OrderLogRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })

const orderNo = ref(typeof route.query.order_no === 'string' ? route.query.order_no : '')
const statusFilter = ref<'' | OrderStatus>('')
const operatorFilter = ref('')
const startDate = ref('')
const endDate = ref('')

const operatorOptions = Object.entries(OPERATOR_TYPE_LABELS).map(([value, label]) => ({ value, label }))

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getOrderLogs({
      order_no: orderNo.value.trim() || undefined,
      to_status: statusFilter.value || undefined,
      operator_type: operatorFilter.value || undefined,
      start_time: startDate.value ? `${startDate.value} 00:00:00` : undefined,
      end_time: endDate.value ? `${endDate.value} 23:59:59` : undefined,
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

function reset() {
  orderNo.value = ''
  statusFilter.value = ''
  operatorFilter.value = ''
  startDate.value = ''
  endDate.value = ''
  load(1)
}

function operatorText(row: OrderLogRow): string {
  if (row.operator_type === 'system') return '系统'
  if (!row.operator_id) return row.operator_type_label
  return `${row.operator_type_label}#${row.operator_id}${row.operator_name ? `（${row.operator_name}）` : ''}`
}

function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

onMounted(() => load())
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">订单流水</h2>
        <p class="mt-0.5 text-xs text-slate-400">订单状态变更的完整轨迹，由系统自动记录，只读</p>
      </div>
      <span class="text-xs text-slate-400">共 {{ pagination.total }} 条记录</span>
    </div>

    <!-- 筛选区 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="orderNo" type="text" placeholder="订单号"
        class="w-56 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="filter-order-no" @keyup.enter="search"
      />
      <select v-model="statusFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="filter-status">
        <option value="">全部目标状态</option>
        <option v-for="(label, value) in ORDER_STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
      </select>
      <select v-model="operatorFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="filter-operator">
        <option value="">全部操作人</option>
        <option v-for="opt in operatorOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
      <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1.5">
        <input v-model="startDate" type="date" class="outline-none" data-testid="filter-start" />
        <span class="text-slate-300">–</span>
        <input v-model="endDate" type="date" class="outline-none" data-testid="filter-end" />
      </div>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" data-testid="filter-search" @click="search">
        <Search class="mr-1 h-4 w-4" /> 搜索
      </Button>
      <Button variant="outline" @click="reset">重置</Button>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-16 px-3 py-1.5">ID</th>
          <th class="px-3 py-1.5">订单号</th>
          <th class="w-56 px-3 py-1.5">状态流转</th>
          <th class="w-40 px-3 py-1.5">操作人</th>
          <th class="px-3 py-1.5">说明</th>
          <th class="w-40 px-3 py-1.5">时间</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 font-mono text-black">{{ row.id }}</td>
          <td class="px-3 py-1.5 font-mono text-black">{{ row.order_no || '-' }}</td>
          <td class="px-3 py-1.5 text-black" data-testid="flow">
            <span class="text-slate-400">{{ row.from_status_label || '创建' }}</span>
            <span class="mx-1 text-slate-300">→</span>
            <span class="font-medium">{{ row.to_status_label }}</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ operatorText(row) }}</td>
          <td class="max-w-64 truncate px-3 py-1.5 text-black" :title="row.remark || ''">{{ row.remark || '-' }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.created_at || '-' }}</td>
        </tr>
        <tr v-if="loading">
          <td colspan="6"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="6" class="px-3 py-12 text-center text-slate-400" data-testid="empty-state">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <div class="mt-4 flex items-center justify-between text-[13px] text-slate-500">
      <span>共 {{ pagination.total }} 条记录 / 每页 {{ pagination.page_size }} 条</span>
      <div class="flex items-center gap-1">
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)"
        ><ChevronLeft class="h-4 w-4" /></button>
        <button
          v-for="page in pagination.total_pages" :key="page"
          class="h-7 min-w-7 rounded border px-1.5"
          :class="page === pagination.page ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
          @click="goPage(page)"
        >{{ page }}</button>
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)"
        ><ChevronRight class="h-4 w-4" /></button>
      </div>
    </div>
  </div>
</template>
