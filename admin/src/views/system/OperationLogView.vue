<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Search } from 'lucide-vue-next'
import { getOperationLogs, type OperationLog } from '@/api/admin'
import { getAccounts, type AccountRow } from '@/api/account'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 操作日志页（权限 log.view / V1.1 T-023 筛选增强）
 * 支持：操作人下拉、模块下拉、动作、时间区间。
 */
const logs = ref<OperationLog[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)

const operators = ref<AccountRow[]>([])
const operatorId = ref<number | ''>('')
const moduleFilter = ref('')
const searchKeyword = ref('')
const startDate = ref('')
const endDate = ref('')

const moduleOptions = [
  { value: '', label: '全部模块' },
  { value: 'product', label: '商品' },
  { value: 'category', label: '分类' },
  { value: 'order', label: '订单' },
  { value: 'refund', label: '退款' },
  { value: 'review', label: '评价' },
  { value: 'account', label: '账号' },
  { value: 'role', label: '角色' },
  { value: 'inventory', label: '库存' },
  { value: 'address', label: '地址' },
  { value: 'config', label: '系统配置' },
]

const queryParams = computed(() => ({
  operator_id: operatorId.value === '' ? undefined : operatorId.value,
  module: moduleFilter.value || undefined,
  action: searchKeyword.value.trim() || undefined,
  start: startDate.value || undefined,
  end: endDate.value || undefined,
}))

async function load(page = 1) {
  loading.value = true
  try {
    const res = await getOperationLogs({ ...queryParams.value, page, page_size: pagination.value.page_size })
    logs.value = res.data.data.list
    pagination.value = res.data.data.pagination
  } finally {
    loading.value = false
  }
}

function search() {
  load(1)
}

function reset() {
  operatorId.value = ''
  moduleFilter.value = ''
  searchKeyword.value = ''
  startDate.value = ''
  endDate.value = ''
  load(1)
}

/** 操作人下拉（无 account.manage 权限时静默降级为空） */
async function loadOperators() {
  try {
    const { data } = await getAccounts({ page_size: 100 })
    operators.value = data.data.list
  } catch {
    operators.value = []
  }
}

onMounted(() => {
  load()
  loadOperators()
})

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load(p)
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">操作日志</h2>
      <span class="text-xs text-slate-400">共 {{ pagination.total }} 条记录</span>
    </div>

    <!-- 筛选区 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <select v-model="operatorId" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="op-filter-operator">
        <option value="">全部操作人</option>
        <option v-for="op in operators" :key="op.id" :value="op.id">{{ op.nickname || op.username }}</option>
      </select>
      <select v-model="moduleFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="op-filter-module">
        <option v-for="opt in moduleOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
      <input
        v-model="searchKeyword" type="text" placeholder="动作（如 create / disable）"
        class="w-48 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="op-filter-action"
        @keyup.enter="search"
      />
      <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1.5">
        <input v-model="startDate" type="date" class="outline-none" data-testid="op-filter-start" />
        <span class="text-slate-300">–</span>
        <input v-model="endDate" type="date" class="outline-none" data-testid="op-filter-end" />
      </div>
      <button class="flex items-center gap-1 rounded-md bg-[#1677ff] px-4 py-1.5 text-white hover:bg-[#4096ff]" @click="search">
        <Search class="h-3.5 w-3.5" /> 搜索
      </button>
      <button class="rounded-md border border-slate-300 px-3 py-1.5 text-slate-500 hover:bg-slate-50" @click="reset">重置</button>
    </div>

    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-44 px-3 py-1.5">时间</th>
          <th class="w-28 px-3 py-1.5">操作人</th>
          <th class="w-28 px-3 py-1.5">模块</th>
          <th class="w-36 px-3 py-1.5">动作</th>
          <th class="px-3 py-1.5">目标</th>
          <th class="w-32 px-3 py-1.5">IP</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="log in logs" :key="log.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 text-black">{{ log.created_at }}</td>
          <td class="px-3 py-1.5 text-black">{{ log.user?.nickname || log.user?.username || '-' }}</td>
          <td class="px-3 py-1.5 font-mono text-black">{{ log.module }}</td>
          <td class="px-3 py-1.5 font-mono text-black">{{ log.action }}</td>
          <td class="px-3 py-1.5 text-black">
            <template v-if="log.target_type">{{ log.target_type }}#{{ log.target_id }}</template>
            <template v-else>-</template>
          </td>
          <td class="px-3 py-1.5 font-mono text-black">{{ log.ip }}</td>
        </tr>
        <tr v-if="loading">
          <td colspan="6"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!logs.length && !loading">
          <td colspan="6" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <TablePagination :pagination="pagination" @change="goPage" />
  </div>
</template>
