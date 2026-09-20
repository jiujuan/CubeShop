<script setup lang="ts">
import { computed, defineAsyncComponent, type Component } from 'vue'
import type { CmsPageBlock } from '@/api/cms'

/**
 * 单页模板 · 自由区块（CMS-203）
 *
 * `template=blocks` 的单页由后台拖拽出的区块数组驱动，本组件按区块 `type`
 * 分发到 `views/blocks/Block{Key}.vue`。装配方式与 PageView 的模板注册表同构：
 * 目录 glob 自动收集 —— 后端 `CmsBlock` 真源新增区块并补上组件后即自动接通，
 * 守卫测试（CmsBlockTest）保证两侧一一对应，漏写组件会直接测试失败。
 *
 * 未知区块（后端真源已删但库里还留着旧数据）在这里被静默跳过，前台不会渲染出空壳。
 *
 * 接收的 props 与其他单页模板组件完全一致（含未用到的 fields/html），
 * 这样 PageView 可以用一份统一的契约分发任意模板。
 *
 * ⚠️ 每条区块外面套一层 div 只为了挂 `page-block-{i}` 便于定位：**不要把
 * data-testid 直接传给区块组件**，那会覆盖组件根元素自带的 `block-*` testid
 * （透传属性在元素创建后应用，优先级更高）。
 */
const props = defineProps<{
  name: string
  fields: Record<string, unknown>
  html: Record<string, string>
  blocks?: CmsPageBlock[]
}>()

/** BlockTextImage → text_image（与后端 CmsBlock::componentName 互逆） */
function toBlockKey(componentBaseName: string): string {
  return componentBaseName.replace(/([a-z0-9])([A-Z])/g, '$1_$2').toLowerCase()
}

const blockModules = import.meta.glob('../blocks/Block*.vue')
const blockRegistry: Record<string, Component> = {}
for (const path in blockModules) {
  const matched = /Block([A-Za-z0-9]+)\.vue$/.exec(path)
  if (matched) {
    blockRegistry[toBlockKey(matched[1])] = defineAsyncComponent(
      blockModules[path] as () => Promise<{ default: Component }>,
    )
  }
}

/** 只保留有对应组件的区块，顺序即后台编排顺序 */
const renderable = computed(() =>
  (props.blocks ?? [])
    .map((block) => ({ block, component: blockRegistry[block.type] ?? null }))
    .filter((entry): entry is { block: CmsPageBlock; component: Component } => entry.component !== null),
)
</script>

<template>
  <div class="space-y-8 py-8" data-testid="page-blocks">
    <div v-for="(entry, index) in renderable" :key="index" :data-testid="`page-block-${index}`">
      <component :is="entry.component" :block="entry.block" />
    </div>
  </div>
</template>
