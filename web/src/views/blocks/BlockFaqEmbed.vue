<script setup lang="ts">
import { computed } from 'vue'
import { ChevronRight, HelpCircle } from 'lucide-vue-next'
import type { CmsPageBlock } from '@/api/cms'

/**
 * 区块 · 帮助中心嵌入（CMS-203）
 *
 * 字段 schema 真源在后端 `App\Support\CmsBlock::BLOCKS['faq_embed']`：
 * title / category_id(channels) / limit(select)。
 *
 * 文章列表由后端随响应带出（`block.items`），前台**零请求**。
 * 降级策略：栏目未选、栏目被删或该栏目下没有已发布文章 → 整块不渲染。
 */
const props = defineProps<{ block: CmsPageBlock }>()

const title = computed(() => {
  const v = props.block.data.title
  return v === null || v === undefined ? '' : String(v)
})

const items = computed(() => props.block.items ?? [])
</script>

<template>
  <section v-if="items.length" class="mx-auto w-full max-w-4xl px-4 sm:px-6" data-testid="block-faq-embed">
    <div class="rounded-xl bg-white p-6 shadow-sm sm:p-8">
      <h2 class="mb-4 flex items-center gap-2 text-base font-semibold text-slate-800">
        <HelpCircle class="h-4 w-4 text-[#1677ff]" /> {{ title || '常见问题' }}
      </h2>

      <ul class="divide-y divide-slate-100">
        <li v-for="(item, index) in items" :key="item.id" :data-testid="`block-faq-embed-item-${index}`">
          <RouterLink
            :to="`/service-center/faq/${item.id}`"
            class="flex items-start gap-2 py-3 text-sm text-slate-700 hover:text-[#1677ff]"
          >
            <ChevronRight class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-300" />
            <span class="min-w-0">
              <span class="block font-medium">{{ item.title }}</span>
              <span v-if="item.summary" class="mt-0.5 block text-xs text-slate-400">{{ item.summary }}</span>
            </span>
          </RouterLink>
        </li>
      </ul>
    </div>
  </section>
</template>
