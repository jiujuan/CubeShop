<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { FileClock } from 'lucide-vue-next'
import { getOperationLogs, type OperationLog } from '@/api/admin'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 操作日志页（权限 log.view）
 */
const logs = ref<OperationLog[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    const res = await getOperationLogs({ page: pagination.value.page, page_size: pagination.value.page_size })
    logs.value = res.data.data.list
    pagination.value = res.data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(load)

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load()
}
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-lg font-semibold">操作日志</h1>

    <section class="rounded-lg border border-slate-200 bg-white">
      <h2 class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 text-sm font-medium">
        <FileClock class="h-4 w-4 text-[#1677ff]" />
        关键操作记录（共 {{ pagination.total }} 条）
      </h2>

      <div v-if="loading" class="px-4 py-6"><LoadingSpinner /></div>

      <table v-else class="w-full text-[13px]">
        <thead>
          <tr class="border-b border-slate-100 text-left text-slate-400">
            <th class="px-4 py-2 font-normal">时间</th>
            <th class="px-4 py-2 font-normal">操作人</th>
            <th class="px-4 py-2 font-normal">模块</th>
            <th class="px-4 py-2 font-normal">动作</th>
            <th class="px-4 py-2 font-normal">目标</th>
            <th class="px-4 py-2 font-normal">IP</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="log in logs" :key="log.id" class="border-b border-slate-50 hover:bg-slate-50/50">
            <td class="px-4 py-2 text-slate-500">{{ log.created_at }}</td>
            <td class="px-4 py-2">{{ log.user?.nickname || log.user?.username || '-' }}</td>
            <td class="px-4 py-2 font-mono text-slate-600">{{ log.module }}</td>
            <td class="px-4 py-2 font-mono text-slate-600">{{ log.action }}</td>
            <td class="px-4 py-2 text-slate-500">
              <template v-if="log.target_type">{{ log.target_type }}#{{ log.target_id }}</template>
              <template v-else>-</template>
            </td>
            <td class="px-4 py-2 font-mono text-slate-400">{{ log.ip }}</td>
          </tr>
          <tr v-if="!logs.length">
            <td colspan="6" class="px-4 py-8 text-center text-slate-400">暂时无数据</td>
          </tr>
        </tbody>
      </table>

      <div class="flex items-center justify-between border-t border-slate-100 px-4 py-2 text-[13px] text-slate-500">
        <span>共 {{ pagination.total }} 条记录 / 每页 {{ pagination.page_size }} 条</span>
        <div class="flex items-center gap-1.5">
          <button class="rounded border border-slate-200 px-2 py-1 disabled:opacity-40" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">上一页</button>
          <button
            v-for="p in pagination.total_pages"
            :key="p"
            class="min-w-8 rounded border px-2 py-1"
            :class="p === pagination.page ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
            @click="goPage(p)"
          >{{ p }}</button>
          <button class="rounded border border-slate-200 px-2 py-1 disabled:opacity-40" :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)">下一页</button>
        </div>
      </div>
    </section>
  </div>
</template>
