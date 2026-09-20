<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronRight } from 'lucide-vue-next'
import { useFaqStore } from '@/stores/faq'

/**
 * 帮助中心面包屑（CMS-201）
 *
 * 链路：服务中心 > 帮助中心 > …栏目层级… > 末级
 *
 * 三个 FAQ 页面（分类/列表/详情）都要这一段，抽成组件避免三处各写一遍链路逻辑 ——
 * 层级数据来自 `useFaqStore`（树的单一真源），组件本身不发请求。
 *
 * `leaf` 省略时末级不渲染（例如分类页停在「帮助中心」）。
 */
const props = withDefaults(
  defineProps<{
    /** 当前栏目 id；缺省表示不在某个具体栏目下（搜索结果页等） */
    categoryId?: number | null
    /** 末级文案（如「文章列表」或文章标题）；省略则不渲染末级 */
    leaf?: string
  }>(),
  { categoryId: null, leaf: '' },
)

const router = useRouter()
const faq = useFaqStore()

/** 根 → 当前栏目的链路（已去掉栏目自身，自己作为末级渲染） */
const trail = computed(() => {
  const chain = faq.breadcrumbOf(props.categoryId)
  return props.leaf ? chain : chain.slice(0, -1)
})

/** 末级文案：显式传入优先，否则用当前栏目名 */
const leafText = computed(() => props.leaf || faq.findById(props.categoryId)?.name || '')

onMounted(() => faq.loadTree())
</script>

<template>
  <nav class="mb-4 flex flex-wrap items-center gap-1 text-xs text-slate-400" data-testid="faq-breadcrumb">
    <button class="hover:text-[#1677ff]" data-testid="breadcrumb-service-center" @click="router.push('/service-center')">
      服务中心
    </button>
    <ChevronRight class="h-3 w-3 shrink-0" />
    <button class="hover:text-[#1677ff]" data-testid="breadcrumb-faq" @click="router.push('/service-center/faq')">
      帮助中心
    </button>

    <template v-for="node in trail" :key="node.id">
      <ChevronRight class="h-3 w-3 shrink-0" />
      <button
        class="hover:text-[#1677ff]"
        :data-testid="`breadcrumb-category-${node.id}`"
        @click="router.push({ path: '/service-center/faq/list', query: { category_id: node.id } })"
      >
        {{ node.name }}
      </button>
    </template>

    <template v-if="leafText">
      <ChevronRight class="h-3 w-3 shrink-0" />
      <span class="max-w-[16rem] truncate text-slate-600" data-testid="breadcrumb-leaf">{{ leafText }}</span>
    </template>
  </nav>
</template>
