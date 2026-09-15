<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { getHealth, type HealthData } from '@/api/health'
import { Database, Server } from 'lucide-vue-next'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 工作台占位页：P0 用于验证前后端连通（健康检查）
 */
const health = ref<HealthData | null>(null)
const loadError = ref('')

onMounted(async () => {
  try {
    const res = await getHealth()
    health.value = res.data.data
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : '健康检查失败'
  }
})
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-lg font-semibold">工作台</h1>

    <div class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-3 flex items-center gap-2 text-sm font-medium">
        <Server class="h-4 w-4 text-[#1677ff]" />
        服务状态（GET /api/health）
      </h2>

      <div v-if="loadError" class="text-[13px] text-red-500">连接失败：{{ loadError }}</div>

      <div v-else-if="health" class="grid grid-cols-2 gap-3 text-[13px]">
        <div class="flex items-center gap-2">
          <span class="text-slate-400">应用状态</span>
          <span class="font-medium" :class="health.status === 'ok' ? 'text-green-600' : 'text-amber-600'">
            {{ health.status }}
          </span>
        </div>
        <div class="flex items-center gap-2">
          <Database class="h-4 w-4" :class="health.database.ok ? 'text-green-600' : 'text-red-500'" />
          <span class="text-slate-400">数据库</span>
          <span class="font-medium" :class="health.database.ok ? 'text-green-600' : 'text-red-500'">
            {{ health.database.ok ? '连接正常' : '连接异常' }}
          </span>
        </div>
        <div><span class="text-slate-400">环境：</span>{{ health.env }}</div>
        <div><span class="text-slate-400">服务器时间：</span>{{ health.time }}</div>
      </div>

      <div v-else><LoadingSpinner /></div>
    </div>
  </div>
</template>
