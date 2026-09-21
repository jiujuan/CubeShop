<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Search, ChevronDown } from 'lucide-vue-next'
import { getAuthLogs, getAuthLog, type AuthLog } from '@/api/admin'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 认证日志页（权限 log.auth.view）
 * 覆盖登录 / 注册 / 登出的全生命周期留痕，含失败明细（验证码错误 / 账号锁定 / 密码错 / 注销等）。
 * 支持按事件 / 身份 / 成功失败 / 标识 / 时间区间筛选；行内可展开查看 UA、设备、扩展明细等详情。
 */
const logs = ref<AuthLog[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)

const eventFilter = ref('')
const actorFilter = ref('')
const successFilter = ref('')
const identifier = ref('')
const startDate = ref('')
const endDate = ref('')

const eventOptions = [
  { value: '', label: '全部事件' },
  { value: 'login', label: '登录' },
  { value: 'register', label: '注册' },
  { value: 'logout', label: '登出' },
]

const actorOptions = [
  { value: '', label: '全部身份' },
  { value: 'admin', label: '管理员' },
  { value: 'customer', label: '买家' },
]

const successOptions = [
  { value: '', label: '全部结果' },
  { value: '1', label: '成功' },
  { value: '0', label: '失败' },
]

const queryParams = computed(() => ({
  event: eventFilter.value || undefined,
  actor_type: actorFilter.value || undefined,
  success: successFilter.value === '' ? undefined : successFilter.value === '1',
  identifier: identifier.value.trim() || undefined,
  created_from: startDate.value || undefined,
  created_to: endDate.value || undefined,
}))

async function load(page = 1) {
  loading.value = true
  try {
    const res = await getAuthLogs({ ...queryParams.value, page, page_size: pagination.value.page_size })
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
  eventFilter.value = ''
  actorFilter.value = ''
  successFilter.value = ''
  identifier.value = ''
  startDate.value = ''
  endDate.value = ''
  load(1)
}

// ============ 行内详情展开 ============
const expandedId = ref<number | null>(null)
const detailMap = ref<Record<number, AuthLog>>({})
const detailLoading = ref(false)

async function toggleDetail(row: AuthLog) {
  if (expandedId.value === row.id) {
    expandedId.value = null
    return
  }
  expandedId.value = row.id
  if (!detailMap.value[row.id]) {
    detailLoading.value = true
    try {
      const res = await getAuthLog(row.id)
      detailMap.value[row.id] = res.data.data
    } finally {
      detailLoading.value = false
    }
  }
}

function detailText(id: number): string {
  const d = detailMap.value[id]?.detail
  if (!d) return '-'
  return JSON.stringify(d, null, 2)
}

onMounted(() => {
  load()
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
      <h2 class="text-lg font-semibold text-slate-800">认证日志</h2>
      <span class="text-xs text-slate-400">共 {{ pagination.total }} 条记录</span>
    </div>

    <!-- 筛选区 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <select v-model="eventFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="auth-log-filter-event">
        <option v-for="opt in eventOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
      <select v-model="actorFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="auth-log-filter-actor">
        <option v-for="opt in actorOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
      <select v-model="successFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="auth-log-filter-success">
        <option v-for="opt in successOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
      <input
        v-model="identifier" type="text" placeholder="标识（用户名/手机号）"
        class="w-48 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="auth-log-filter-identifier"
        @keyup.enter="search"
      />
      <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1.5">
        <input v-model="startDate" type="date" class="outline-none" data-testid="auth-log-filter-start" />
        <span class="text-slate-300">–</span>
        <input v-model="endDate" type="date" class="outline-none" data-testid="auth-log-filter-end" />
      </div>
      <button class="flex items-center gap-1 rounded-md bg-[#1677ff] px-4 py-1.5 text-white hover:bg-[#4096ff]" data-testid="auth-log-search" @click="search">
        <Search class="h-3.5 w-3.5" /> 搜索
      </button>
      <button class="rounded-md border border-slate-300 px-3 py-1.5 text-slate-500 hover:bg-slate-50" data-testid="auth-log-reset" @click="reset">重置</button>
    </div>

    <table class="w-full text-[13px]" data-testid="auth-log-table">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-10 px-3 py-1.5"></th>
          <th class="w-40 px-3 py-1.5">时间</th>
          <th class="w-20 px-3 py-1.5">事件</th>
          <th class="w-20 px-3 py-1.5">身份</th>
          <th class="w-36 px-3 py-1.5">标识</th>
          <th class="w-20 px-3 py-1.5">结果</th>
          <th class="px-3 py-1.5">失败原因</th>
          <th class="w-32 px-3 py-1.5">IP</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="log in logs" :key="log.id">
          <tr class="border-b border-slate-100 hover:bg-slate-50">
            <td class="px-3 py-1.5">
              <button class="text-slate-400 hover:text-[#1677ff]" :data-testid="`auth-log-expand-${log.id}`" @click="toggleDetail(log)">
                <ChevronDown class="h-4 w-4 transition-transform" :class="expandedId === log.id ? 'rotate-180' : ''" />
              </button>
            </td>
            <td class="px-3 py-1.5 text-black">{{ log.created_at }}</td>
            <td class="px-3 py-1.5 text-black">{{ log.event_label }}</td>
            <td class="px-3 py-1.5 text-black">{{ log.actor_label }}</td>
            <td class="px-3 py-1.5 font-mono text-black">{{ log.identifier || '-' }}</td>
            <td class="px-3 py-1.5">
              <span
                class="rounded px-1.5 py-0.5 text-xs"
                :class="log.success ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'"
              >{{ log.success_label }}</span>
            </td>
            <td class="px-3 py-1.5 font-mono text-black">{{ log.fail_reason || '-' }}</td>
            <td class="px-3 py-1.5 font-mono text-black">{{ log.ip || '-' }}</td>
          </tr>
          <tr v-if="expandedId === log.id" class="border-b border-slate-100 bg-slate-50">
            <td colspan="8" class="px-6 py-3 text-[12px] text-slate-500">
              <LoadingSpinner v-if="detailLoading" />
              <div v-else class="grid grid-cols-2 gap-x-8 gap-y-1">
                <div><span class="text-slate-400">用户ID：</span>{{ detailMap[log.id]?.user_id ?? '-' }}</div>
                <div><span class="text-slate-400">设备ID：</span>{{ detailMap[log.id]?.device_id ?? '-' }}</div>
                <div><span class="text-slate-400">Token ID：</span>{{ detailMap[log.id]?.token_id ?? '-' }}</div>
                <div class="col-span-2">
                  <span class="text-slate-400">User-Agent：</span>
                  <span class="break-all font-mono">{{ detailMap[log.id]?.user_agent || '-' }}</span>
                </div>
                <div class="col-span-2">
                  <span class="text-slate-400">扩展明细：</span>
                  <pre class="mt-1 max-h-40 overflow-auto rounded bg-white p-2 font-mono text-slate-600">{{ detailText(log.id) }}</pre>
                </div>
              </div>
            </td>
          </tr>
        </template>
        <tr v-if="loading">
          <td colspan="8"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!logs.length && !loading">
          <td colspan="8" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <TablePagination :pagination="pagination" @change="goPage" />
  </div>
</template>
