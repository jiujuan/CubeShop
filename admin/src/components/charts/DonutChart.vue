<script setup lang="ts">
import { computed } from 'vue'

/**
 * 轻量环形占比图（V1.1 F03 / T-021）：分类销售额占比
 *
 * 无第三方依赖（SVG stroke-dasharray 实现），空态与图例齐备。
 */
const props = withDefaults(defineProps<{
  items: Array<{ label: string; value: number }>
  format?: (v: number) => string
}>(), {})

const COLORS = ['#1677ff', '#52c41a', '#faad14', '#ff4d4f', '#722ed1', '#13c2c2', '#eb2f96', '#a0d911']

const total = computed(() => props.items.reduce((s, i) => s + (Number(i.value) || 0), 0))
const hasData = computed(() => props.items.length > 0 && total.value > 0)

const R = 54
const C = 2 * Math.PI * R

/** 每段弧线：累计百分比计算 dasharray */
const segments = computed(() => {
  let acc = 0
  return props.items.map((item, i) => {
    const ratio = total.value > 0 ? (Number(item.value) || 0) / total.value : 0
    const seg = {
      label: item.label,
      value: Number(item.value) || 0,
      percent: ratio,
      color: COLORS[i % COLORS.length],
      // 弧长
      len: ratio * C,
      offset: -acc * C,
    }
    acc += ratio
    return seg
  })
})

const fmt = (v: number) => (props.format ? props.format(v) : String(v))
</script>

<template>
  <div data-testid="donut-chart">
    <div v-if="!hasData" class="flex items-center justify-center py-10 text-[13px] text-slate-300">暂无数据</div>
    <div v-else class="flex flex-col items-center gap-4 sm:flex-row sm:justify-center">
      <svg viewBox="0 0 140 140" class="h-40 w-40 shrink-0" data-testid="donut-svg">
        <circle cx="70" cy="70" :r="R" fill="none" stroke="#f1f5f9" stroke-width="18" />
        <circle
          v-for="(s, i) in segments" :key="i"
          cx="70" cy="70" :r="R" fill="none"
          :stroke="s.color" stroke-width="18"
          :stroke-dasharray="`${s.len} ${C - s.len}`"
          :stroke-dashoffset="s.offset"
          transform="rotate(-90 70 70)"
          :data-testid="`donut-seg-${i}`"
        >
          <title>{{ s.label }}：{{ fmt(s.value) }}（{{ Math.round(s.percent * 100) }}%）</title>
        </circle>
        <text x="70" y="66" text-anchor="middle" font-size="11" fill="#94a3b8">合计</text>
        <text x="70" y="82" text-anchor="middle" font-size="13" font-weight="600" fill="#334155">{{ fmt(total) }}</text>
      </svg>

      <ul class="w-full max-w-xs space-y-1.5" data-testid="donut-legend">
        <li
          v-for="(s, i) in segments" :key="i"
          class="flex items-center gap-2 text-xs" :data-testid="`donut-legend-${i}`"
        >
          <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ background: s.color }"></span>
          <span class="min-w-0 flex-1 truncate text-slate-600" :title="s.label">{{ s.label }}</span>
          <span class="shrink-0 font-medium text-slate-700">{{ fmt(s.value) }}</span>
          <span class="w-10 shrink-0 text-right text-slate-400">{{ Math.round(s.percent * 100) }}%</span>
        </li>
      </ul>
    </div>
  </div>
</template>
