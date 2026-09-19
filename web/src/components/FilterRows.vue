<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ChevronDown, ChevronUp } from 'lucide-vue-next'

/**
 * 可折叠按钮行容器（分类页品牌区 / 筛选面板属性区共用）。
 *
 * - 收起：最多显示 collapsedRows 行（默认 5 行）
 * - 展开：最多显示 expandedRows 行（默认 8 行），超出部分出滚动条
 * - 行高取第一个子元素实测高度（各处按钮高度不同），内容不足收起行数时不显示切换按钮
 * - 内容自然高度用 ResizeObserver 跟踪（异步加载、窗口换行变化都能感知）
 */
const props = withDefaults(defineProps<{
  /** 收起态可见行数 */
  collapsedRows?: number
  /** 展开态最大可见行数（超出滚动） */
  expandedRows?: number
  /** 行间距 px（与原 gap-2 一致） */
  gap?: number
  /** 测试锚点前缀 */
  testId?: string
}>(), { collapsedRows: 5, expandedRows: 8, gap: 8 })

const inner = ref<HTMLElement | null>(null)
/** 行高（首子元素实测），未挂载 / jsdom 下回退默认 */
const rowHeight = ref(30)
/** 内容自然高度，jsdom 下为 0（视为不溢出） */
const contentHeight = ref(0)
const expanded = ref(false)

let observer: ResizeObserver | null = null

onMounted(() => {
  const el = inner.value
  if (!el) return
  const first = el.firstElementChild as HTMLElement | null
  if (first && first.offsetHeight > 0) rowHeight.value = first.offsetHeight
  contentHeight.value = el.scrollHeight
  if (typeof ResizeObserver !== 'undefined') {
    observer = new ResizeObserver(() => {
      contentHeight.value = el.scrollHeight
    })
    observer.observe(el)
  }
})

onBeforeUnmount(() => observer?.disconnect())

/** 收起态最大高度：N 行 + (N-1) 个行距 */
const collapsedMax = computed(
  () => props.collapsedRows * rowHeight.value + (props.collapsedRows - 1) * props.gap,
)
/** 展开态最大高度：超出即出滚动条 */
const expandedMax = computed(
  () => props.expandedRows * rowHeight.value + (props.expandedRows - 1) * props.gap,
)
/** 内容超过收起行数 → 显示「更多/收起」 */
const overflowable = computed(() => contentHeight.value > collapsedMax.value + 2)
/** 展开后内容仍超过展开行数 → 出滚动条 */
const scrollable = computed(() => expanded.value && contentHeight.value > expandedMax.value + 2)

const viewportMaxHeight = computed(() => `${(expanded.value ? expandedMax.value : collapsedMax.value)}px`)
</script>

<template>
  <div class="min-w-0 flex-1">
    <div
      class="transition-[max-height] duration-200"
      :class="scrollable ? 'overflow-y-auto pr-1' : 'overflow-hidden'"
      :style="{ maxHeight: viewportMaxHeight }"
      :data-testid="testId ? `${testId}-viewport` : undefined"
    >
      <div ref="inner" class="flex flex-wrap" :style="{ gap: `${gap}px` }">
        <slot />
      </div>
    </div>

    <button
      v-if="overflowable"
      type="button"
      class="mt-1.5 flex items-center gap-0.5 text-xs text-[#1677ff] hover:text-[#4096ff]"
      :data-testid="testId ? `${testId}-toggle` : undefined"
      @click="expanded = !expanded"
    >
      {{ expanded ? '收起' : '更多' }}
      <ChevronUp v-if="expanded" class="h-3 w-3" />
      <ChevronDown v-else class="h-3 w-3" />
    </button>
  </div>
</template>
