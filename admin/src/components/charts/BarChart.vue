<script setup lang="ts">
import { computed } from 'vue'

/**
 * 轻量横向柱状图（V1.1 F03 / T-021）：用于商品 TOP 排行
 */
const props = withDefaults(defineProps<{
  items: Array<{ label: string; value: number }>
  color?: string
  format?: (v: number) => string
}>(), {
  color: '#1677ff',
})

const hasData = computed(() => props.items.length > 0 && props.items.some((i) => i.value > 0))
const maxValue = computed(() => Math.max(...props.items.map((i) => i.value), 1))
const fmt = (v: number) => (props.format ? props.format(v) : String(v))
</script>

<template>
  <div data-testid="bar-chart">
    <div v-if="!hasData" class="flex items-center justify-center py-10 text-[13px] text-slate-300">暂无数据</div>
    <ul v-else class="space-y-2">
      <li v-for="(item, i) in items" :key="i" class="flex items-center gap-2 text-xs" :data-testid="`bar-item-${i}`">
        <span class="w-32 shrink-0 truncate text-slate-600" :title="item.label">{{ item.label }}</span>
        <span class="h-3 flex-1 overflow-hidden rounded-full bg-slate-100">
          <span
            class="block h-full rounded-full transition-all"
            :style="{ width: (item.value / maxValue) * 100 + '%', background: color }"
            :data-testid="`bar-fill-${i}`"
          ></span>
        </span>
        <span class="w-16 shrink-0 text-right font-medium text-slate-700">{{ fmt(item.value) }}</span>
      </li>
    </ul>
  </div>
</template>
