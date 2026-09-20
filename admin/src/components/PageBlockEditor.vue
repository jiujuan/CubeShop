<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowDown, ArrowUp, GripVertical, Plus, Trash2 } from 'lucide-vue-next'
import PageFieldForm from '@/components/PageFieldForm.vue'
import type { CmsPageBlockOption, CmsPageBlockPayload, CmsPageField, CsChannelOption } from '@/api/cs'

/**
 * 单页区块编辑器（内容中心 CMS · CMS-203）
 *
 * 「自由区块」模板的单页内容是一串**有序**区块，本组件负责编排与逐块编辑：
 * - 类型下拉来自后端 `CmsBlock::options()`（区块库），每块的字段表单复用 `PageFieldForm`，
 *   于是后台表单仍然只有一份 schema 驱动实现；
 * - 排序用**原生 HTML5 drag & drop**（拖 handle 或点上下箭头），不引第三方拖拽库；
 * - 每块的数据初值取该块 schema 的 default（后端下发的真源），前端不硬编码默认值。
 *
 * 数量上限（MAX）与后端 `CmsBlock::MAX_BLOCKS` 对齐，到顶即禁用「添加」按钮，
 * 让用户在这里就被拦住，而不是提交后收到 422。
 */
const MAX_BLOCKS = 30

const props = withDefaults(defineProps<{
  modelValue: CmsPageBlockPayload[]
  /** 区块库（GET /admin/cs/faq/page-blocks），决定可选类型与每块的字段 schema */
  options: CmsPageBlockOption[]
  channels?: CsChannelOption[]
  disabled?: boolean
}>(), { channels: () => [] })

const emit = defineEmits<{
  (e: 'update:modelValue', value: CmsPageBlockPayload[]): void
  (e: 'upload-error', message: string): void
}>()

const reachedLimit = computed(() => props.modelValue.length >= MAX_BLOCKS)

/** 待添加的类型：用户没选过就默认第一个（下拉显示与提交值保持一致） */
const pickedType = ref('')
const addType = computed({
  get: () => pickedType.value || props.options[0]?.key || '',
  set: (value: string) => { pickedType.value = value },
})

function optionOf(type: string): CmsPageBlockOption | null {
  return props.options.find((o) => o.key === type) ?? null
}

function schemaOf(type: string): CmsPageField[] {
  return optionOf(type)?.fields ?? []
}

function labelOf(type: string): string {
  return optionOf(type)?.label ?? type
}

function update(blocks: CmsPageBlockPayload[]) {
  emit('update:modelValue', blocks)
}

/** 新区块的初值：按该类型 schema 的 default/空值生成（与后端 filterPayload 同口径） */
function defaultsOf(type: string): Record<string, unknown> {
  const data: Record<string, unknown> = {}
  for (const field of schemaOf(type)) {
    const empty = field.type === 'image_list' || field.type === 'repeater' ? [] : ''
    data[field.key] = field.default ?? empty
  }

  return data
}

function addBlock() {
  const type = addType.value
  if (!type || reachedLimit.value) return

  update([...props.modelValue, { type, data: defaultsOf(type) }])
}

function removeBlock(index: number) {
  const list = [...props.modelValue]
  list.splice(index, 1)
  update(list)
}

/** 上下移动（与拖拽等价的可访问替代路径，也是测试里最稳的操作方式） */
function moveBlock(index: number, delta: number) {
  const target = index + delta
  if (target < 0 || target >= props.modelValue.length) return

  const list = [...props.modelValue]
  const tmp = list[index]
  list[index] = list[target]
  list[target] = tmp
  update(list)
}

function setBlockData(index: number, data: Record<string, unknown>) {
  update(props.modelValue.map((b, i) => (i === index ? { ...b, data } : b)))
}

// ---------- 原生 HTML5 拖拽排序 ----------

/** 拖拽中的源下标；-1 表示当前没有拖拽进行中 */
const dragIndex = ref(-1)
/** 悬停到的目标下标（用于高亮落点） */
const overIndex = ref(-1)

function onDragStart(index: number, event: Event) {
  dragIndex.value = index

  // Firefox 只有 setData 之后才会触发 drop；jsdom 无 dataTransfer，故一律判空
  const dt = (event as DragEvent).dataTransfer
  if (dt) {
    dt.setData('text/plain', String(index))
    dt.effectAllowed = 'move'
  }
}

function onDragOver(index: number) {
  if (dragIndex.value !== -1) overIndex.value = index
}

function resetDrag() {
  dragIndex.value = -1
  overIndex.value = -1
}

/** 把源区块插到目标下标处（目标之后的元素顺延） */
function onDrop(index: number) {
  const from = dragIndex.value
  resetDrag()
  if (from === -1 || from === index) return

  const list = [...props.modelValue]
  const [moved] = list.splice(from, 1)
  list.splice(index, 0, moved)
  update(list)
}
</script>

<template>
  <div data-testid="page-block-editor">
    <p v-if="!modelValue.length" class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-[13px] text-slate-400" data-testid="page-block-empty">
      还没有区块，从下方选择类型后添加
    </p>

    <div v-else class="space-y-3" data-testid="page-block-list">
      <div
        v-for="(block, index) in modelValue"
        :key="index"
        class="overflow-hidden rounded-lg border bg-white transition-colors"
        :class="overIndex === index && dragIndex !== -1 ? 'border-[#1677ff]' : 'border-slate-200'"
        :data-testid="`page-block-${index}`"
        @dragover.prevent="onDragOver(index)"
        @drop.prevent="onDrop(index)"
      >
        <div class="flex items-center gap-2 border-b border-slate-100 bg-slate-50/70 px-3 py-2">
          <span
            class="cursor-grab text-slate-400 hover:text-[#1677ff]"
            title="拖拽调整顺序"
            draggable="true"
            :data-testid="`page-block-drag-${index}`"
            @dragstart="onDragStart(index, $event)"
            @dragend="resetDrag"
          >
            <GripVertical class="h-4 w-4" />
          </span>

          <span class="text-[13px] font-medium text-slate-700" :data-testid="`page-block-label-${index}`">
            {{ labelOf(block.type) }}
          </span>
          <span class="font-mono text-[11px] text-slate-400">{{ block.type }}</span>

          <div class="ml-auto flex items-center gap-1">
            <button
              class="rounded p-1 text-slate-400 hover:text-[#1677ff] disabled:opacity-30"
              :disabled="disabled || index === 0"
              title="上移"
              :data-testid="`page-block-up-${index}`"
              @click="moveBlock(index, -1)"
            >
              <ArrowUp class="h-3.5 w-3.5" />
            </button>
            <button
              class="rounded p-1 text-slate-400 hover:text-[#1677ff] disabled:opacity-30"
              :disabled="disabled || index === modelValue.length - 1"
              title="下移"
              :data-testid="`page-block-down-${index}`"
              @click="moveBlock(index, 1)"
            >
              <ArrowDown class="h-3.5 w-3.5" />
            </button>
            <button
              class="rounded p-1 text-slate-400 hover:text-red-500"
              :disabled="disabled"
              title="删除区块"
              :data-testid="`page-block-remove-${index}`"
              @click="removeBlock(index)"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>

        <div class="p-4">
          <PageFieldForm
            :schema="schemaOf(block.type)"
            :model-value="block.data"
            :disabled="disabled"
            :channels="channels"
            @update:model-value="(value: Record<string, unknown>) => setBlockData(index, value)"
            @upload-error="(message: string) => emit('upload-error', message)"
          />
        </div>
      </div>
    </div>

    <div class="mt-4 flex items-center gap-2">
      <select
        v-model="addType"
        :disabled="disabled || reachedLimit"
        class="h-9 rounded border border-slate-300 px-2 text-[13px] outline-none focus:border-[#1677ff] disabled:bg-slate-50"
        data-testid="page-block-add-type"
      >
        <option v-for="opt in options" :key="opt.key" :value="opt.key">{{ opt.label }}</option>
      </select>
      <button
        class="flex h-9 items-center gap-1.5 rounded border border-dashed border-slate-300 px-3 text-[13px] text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff] disabled:opacity-40"
        :disabled="disabled || reachedLimit || !options.length"
        data-testid="page-block-add"
        @click="addBlock"
      >
        <Plus class="h-3.5 w-3.5" /> 添加区块
      </button>
      <span v-if="reachedLimit" class="text-[12px] text-amber-500" data-testid="page-block-limit-tip">
        已达上限 {{ MAX_BLOCKS }} 块
      </span>
    </div>
  </div>
</template>
