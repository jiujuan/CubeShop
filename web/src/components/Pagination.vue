<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'

/**
 * 前端统一分页条（与后台 TablePagination 视觉/交互一致）
 *
 * - 页码折叠：总页数 ≤ 7 全部展示；否则固定显示首尾与当前页左右各 1 页，其余以 … 省略
 *   （例：1 … 14 15 16 … 29 / 1 2 3 4 5 … 29）
 * - 页码跳转：输入框输入数字回车直达，越界自动钳制到 [1, total_pages]，非法输入忽略
 * - 兼容 SEC-04：公开列表（如商品分类）不返回精确 total_pages，只给 has_more，
 *   此时退化为「上一页 / 下一页 + 摘要」，不渲染页码与跳页框
 */
interface PaginationData {
  page: number
  page_size: number
  total: number | null
  total_pages: number | null
  has_more?: boolean
}

const props = defineProps<{ pagination: PaginationData }>()
const emit = defineEmits<{ change: [page: number] }>()

const totalPages = computed<number | null>(() =>
  props.pagination.total_pages == null ? null : Math.max(1, props.pagination.total_pages),
)
const current = computed(() => Math.max(1, props.pagination.page))

/** 折叠后的页码序列，'…' 表示省略（无精确总页数时不渲染页码） */
const pages = computed<(number | '…')[]>(() => {
  const tp = totalPages.value
  if (tp == null || tp <= 7) {
    if (tp == null) return []
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

const leftText = computed(() => {
  const p = props.pagination
  if (p.total != null) return `共 ${p.total} 条记录 / 每页 ${p.page_size} 条`
  if (totalPages.value != null) return `共 ${totalPages.value} 页 / 每页 ${p.page_size} 条`
  return `每页 ${p.page_size} 条`
})

const canPrev = computed(() => current.value > 1)
const canNext = computed(() =>
  totalPages.value != null ? current.value < totalPages.value : props.pagination.has_more === true,
)

const jumpInput = ref('')

function go(page: number) {
  const tp = totalPages.value
  const target = tp != null ? Math.min(Math.max(page, 1), tp) : Math.max(page, 1)
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
  <!-- SEC-04 退化模式：无精确总页数（如商品分类），仅上一页/下一页 + 摘要，居中、文字按钮；摘要在下一页右侧 -->
  <div
    v-if="totalPages == null"
    class="mt-4 flex items-center justify-center gap-3 text-[13px] text-slate-500"
    data-testid="pager-fallback"
  >
    <button
      class="rounded border border-slate-200 px-3 py-1 transition-colors hover:border-[#1677ff] hover:text-[#1677ff] disabled:cursor-not-allowed disabled:opacity-40"
      :disabled="!canPrev"
      data-testid="pager-prev"
      @click="go(current - 1)"
    >上一页</button>

    <button
      class="rounded border border-slate-200 px-3 py-1 transition-colors hover:border-[#1677ff] hover:text-[#1677ff] disabled:cursor-not-allowed disabled:opacity-40"
      :disabled="!canNext"
      data-testid="pager-next"
      @click="go(current + 1)"
    >下一页</button>

    <span data-testid="pager-summary">{{ leftText }}</span>
  </div>

  <!-- 完整模式：折叠页码 + 跳页 + 摘要（与后台一致，图标按钮） -->
  <div v-else class="mt-4 flex flex-wrap items-center justify-between gap-y-2 text-[13px] text-slate-500">
    <span data-testid="pager-summary">{{ leftText }}</span>

    <div class="flex flex-wrap items-center gap-1">
      <button
        class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
        :disabled="!canPrev"
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
        :disabled="!canNext"
        title="下一页"
        data-testid="pager-next"
        @click="go(current + 1)"
      ><ChevronRight class="h-4 w-4" /></button>

      <div v-if="totalPages != null" class="ml-1.5 flex items-center gap-1">
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
