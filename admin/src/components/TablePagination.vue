<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'

/**
 * 后台统一分页条
 *
 * 替代各页面手写的「全量页码」实现，解决总页数多时页码铺满整行的问题（如用户管理 29 页）。
 *
 * - 页码折叠：总页数 ≤ 7 全部展示；否则固定显示首尾各若干页与当前页左右各 1 页，
 *   其余以 … 省略（例：1 … 14 15 16 … 29 / 1 2 3 4 5 … 29）
 * - 页码跳转：输入框输入数字回车直达，越界自动钳制到 [1, total_pages]，非法输入忽略
 * - 左侧统计文案可用 total-text 覆写，默认「共 N 条记录 / 每页 M 条」
 */
const props = defineProps<{
  pagination: { page: number; page_size: number; total: number; total_pages: number }
  totalText?: string
}>()

const emit = defineEmits<{ change: [page: number] }>()

const totalPages = computed(() => Math.max(1, props.pagination.total_pages))
const current = computed(() => Math.min(Math.max(1, props.pagination.page), totalPages.value))

/** 折叠后的页码序列，'…' 表示省略 */
const pages = computed<(number | '…')[]>(() => {
  const tp = totalPages.value
  if (tp <= 7) {
    return Array.from({ length: tp }, (_, i) => i + 1)
  }
  const cur = current.value

  // 靠近首/尾时顺势多带几页，避免「1 2 … 29」这种信息量过少的折叠
  if (cur <= 4) {
    return [1, 2, 3, 4, 5, '…', tp]
  }
  if (cur >= tp - 3) {
    return [1, '…', tp - 4, tp - 3, tp - 2, tp - 1, tp]
  }

  return [1, '…', cur - 1, cur, cur + 1, '…', tp]
})

const leftText = computed(
  () => props.totalText ?? `共 ${props.pagination.total} 条记录 / 每页 ${props.pagination.page_size} 条`,
)

const jumpInput = ref('')

function go(page: number) {
  const target = Math.min(Math.max(page, 1), totalPages.value)
  if (target === current.value) return
  emit('change', target)
}

function jump() {
  const raw = jumpInput.value.trim()
  jumpInput.value = ''
  if (!/^\d+$/.test(raw)) return
  go(Number(raw))
}
</script>

<template>
  <div class="mt-4 flex flex-wrap items-center justify-between gap-y-2 text-[13px] text-slate-500">
    <span data-testid="pager-summary">{{ leftText }}</span>

    <div class="flex flex-wrap items-center gap-1">
      <button
        class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
        :disabled="current <= 1"
        title="上一页"
        data-testid="pager-prev"
        @click="go(current - 1)"
      ><ChevronLeft class="h-4 w-4" /></button>

      <template v-for="(p, i) in pages" :key="`${p}-${i}`">
        <span v-if="p === '…'" class="px-1 text-slate-400">…</span>
        <button
          v-else
          class="h-7 min-w-7 rounded border px-1.5"
          :class="p === current ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
          :data-testid="`pager-page-${p}`"
          @click="go(Number(p))"
        >{{ p }}</button>
      </template>

      <button
        class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
        :disabled="current >= totalPages"
        title="下一页"
        data-testid="pager-next"
        @click="go(current + 1)"
      ><ChevronRight class="h-4 w-4" /></button>

      <div class="ml-1.5 flex items-center gap-1">
        <span>跳至</span>
        <input
          v-model="jumpInput"
          type="text"
          inputmode="numeric"
          placeholder="页码"
          class="h-7 w-14 rounded border border-slate-200 px-1.5 text-center outline-none focus:border-[#1677ff]"
          data-testid="pager-jump"
          @keyup.enter="jump"
        />
        <span>页</span>
      </div>
    </div>
  </div>
</template>
