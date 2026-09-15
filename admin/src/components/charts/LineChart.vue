<script setup lang="ts">
import { computed } from 'vue'

/**
 * 轻量折线图（V1.1 F03 / T-021）
 *
 * 无第三方依赖（SVG 实现），支持多序列、自适应网格与空态。
 * 说明：报表图表以「数据可读 + 可测试」为先，避免引入 ECharts 带来的包体与 jsdom 兼容成本。
 */
const props = withDefaults(defineProps<{
  labels: string[]
  series: Array<{ name: string; data: number[]; color?: string }>
  height?: number
  /** 数值格式化（Y 轴与提示） */
  format?: (v: number) => string
}>(), {
  height: 220,
})

const W = 640
const PAD_L = 56
const PAD_R = 16
const PAD_T = 16
const PAD_B = 28

const innerW = W - PAD_L - PAD_R
const innerH = computed(() => props.height - PAD_T - PAD_B)

const hasData = computed(() => props.labels.length > 0 && props.series.some((s) => s.data.length > 0))

const maxValue = computed(() => {
  let max = 0
  for (const s of props.series) for (const v of s.data) max = Math.max(max, Number(v) || 0)
  return max === 0 ? 1 : max
})

const fmt = (v: number) => (props.format ? props.format(v) : String(v))

/** 网格线（4 段） */
const gridLines = computed(() =>
  [0, 0.25, 0.5, 0.75, 1].map((r) => ({
    y: PAD_T + innerH.value * r,
    value: maxValue.value * (1 - r),
  })),
)

function x(i: number): number {
  const n = props.labels.length
  if (n <= 1) return PAD_L + innerW / 2
  return PAD_L + (i * innerW) / (n - 1)
}

function y(v: number): number {
  return PAD_T + innerH.value - ((Number(v) || 0) / maxValue.value) * innerH.value
}

const polylines = computed(() =>
  props.series.map((s, idx) => ({
    name: s.name,
    color: s.color ?? (idx === 0 ? '#1677ff' : '#ff6a00'),
    points: s.data.map((v, i) => `${x(i)},${y(v)}`).join(' '),
    dots: s.data.map((v, i) => ({ cx: x(i), cy: y(v), v, label: props.labels[i] })),
  })),
)

/** X 轴标签抽稀：最多显示 8 个 */
const xTicks = computed(() => {
  const n = props.labels.length
  if (n === 0) return []
  const step = Math.max(1, Math.ceil(n / 8))
  const ticks: Array<{ x: number; label: string }> = []
  for (let i = 0; i < n; i += step) ticks.push({ x: x(i), label: props.labels[i] })
  return ticks
})
</script>

<template>
  <div data-testid="line-chart">
    <div v-if="!hasData" class="flex items-center justify-center text-[13px] text-slate-300" :style="{ height: height + 'px' }">
      暂无数据
    </div>
    <svg v-else :viewBox="`0 0 ${W} ${height}`" class="h-auto w-full">
      <!-- 网格 -->
      <g>
        <line
          v-for="(g, i) in gridLines" :key="i"
          :x1="PAD_L" :x2="W - PAD_R" :y1="g.y" :y2="g.y"
          stroke="#eef2f7" stroke-width="1"
        />
        <text
          v-for="(g, i) in gridLines" :key="'t' + i"
          :x="PAD_L - 8" :y="g.y + 4" text-anchor="end"
          font-size="10" fill="#94a3b8"
        >{{ fmt(g.value) }}</text>
      </g>

      <!-- X 轴标签 -->
      <text
        v-for="(t, i) in xTicks" :key="'x' + i"
        :x="t.x" :y="height - 8" text-anchor="middle" font-size="10" fill="#94a3b8"
      >{{ t.label }}</text>

      <!-- 折线 -->
      <g v-for="s in polylines" :key="s.name">
        <polyline :points="s.points" fill="none" :stroke="s.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        <circle v-for="(d, i) in s.dots" :key="i" :cx="d.cx" :cy="d.cy" r="2.5" :fill="s.color">
          <title>{{ d.label }} {{ s.name }}：{{ fmt(d.v) }}</title>
        </circle>
      </g>
    </svg>

    <!-- 图例 -->
    <div v-if="hasData" class="mt-1 flex items-center justify-center gap-4 text-xs text-slate-500" data-testid="line-chart-legend">
      <span v-for="s in polylines" :key="s.name" class="flex items-center gap-1.5">
        <span class="inline-block h-2 w-2 rounded-full" :style="{ background: s.color }"></span>{{ s.name }}
      </span>
    </div>
  </div>
</template>
