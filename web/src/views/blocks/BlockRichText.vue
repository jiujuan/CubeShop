<script setup lang="ts">
import { computed } from 'vue'
import type { CmsPageBlock } from '@/api/cms'

/**
 * 区块 · 富文本（CMS-203）
 *
 * 字段 schema 真源在后端 `App\Support\CmsBlock::BLOCKS['rich_text']`：
 * title / body(markdown, required)。
 *
 * 降级策略：正文为空整块不渲染。
 */
const props = defineProps<{ block: CmsPageBlock }>()

const title = computed(() => {
  const v = props.block.data.title
  return v === null || v === undefined ? '' : String(v)
})

const bodyHtml = computed(() => props.block.html.body ?? '')
</script>

<template>
  <section v-if="bodyHtml" class="mx-auto w-full max-w-4xl px-4 sm:px-6" data-testid="block-rich-text">
    <div class="rounded-xl bg-white p-6 shadow-sm sm:p-8">
      <h2 v-if="title" class="mb-4 text-base font-semibold text-slate-800" data-testid="block-rich-text-title">
        {{ title }}
      </h2>
      <div class="cms-prose break-words text-sm leading-7 text-slate-600" data-testid="block-rich-text-body" v-html="bodyHtml" />
    </div>
  </section>
</template>
