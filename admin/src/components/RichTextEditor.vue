<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import {
  AlignCenter, AlignLeft, Bold, Image as ImageIcon, Indent, Italic, Link2, Link2Off,
  List, ListOrdered, Outdent, Strikethrough, Underline,
} from 'lucide-vue-next'

/**
 * 轻量富文本编辑器（零依赖）：contenteditable + execCommand
 * 工具栏：加粗/斜体/下划线/删除线 | 无序/有序列表 | 减/增缩进 | 图片/链接/去链接 | 居左/居中
 * v-model 输出 HTML 片段（与后端 description 存 HTML、前端 v-html 渲染的约定一致）
 */
const props = withDefaults(defineProps<{ modelValue: string; placeholder?: string }>(), {
  placeholder: '请输入商品详情...',
})
const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>()

const editorRef = ref<HTMLDivElement>()

onMounted(() => {
  if (editorRef.value) editorRef.value.innerHTML = props.modelValue || ''
})

watch(() => props.modelValue, (v) => {
  if (editorRef.value && document.activeElement !== editorRef.value && editorRef.value.innerHTML !== v) {
    editorRef.value.innerHTML = v || ''
  }
})

function onInput() {
  emit('update:modelValue', editorRef.value?.innerHTML ?? '')
}

function exec(command: string, value?: string) {
  editorRef.value?.focus()
  document.execCommand(command, false, value)
  onInput()
}

function insertImage() {
  const url = window.prompt('请输入图片地址（URL）：')
  if (url) exec('insertImage', url)
}

function insertLink() {
  const url = window.prompt('请输入链接地址（URL）：')
  if (url) exec('createLink', url)
}

const divider = 'mx-1 h-4 w-px bg-slate-200'
</script>

<template>
  <div class="overflow-hidden rounded-md border border-slate-300 focus-within:border-[#1677ff]">
    <!-- 工具栏 -->
    <div class="flex flex-wrap items-center gap-0.5 border-b border-slate-200 bg-slate-50 px-2 py-1.5">
      <button type="button" title="加粗" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('bold')"><Bold class="h-4 w-4" /></button>
      <button type="button" title="斜体" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('italic')"><Italic class="h-4 w-4" /></button>
      <button type="button" title="下划线" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('underline')"><Underline class="h-4 w-4" /></button>
      <button type="button" title="删除线" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('strikeThrough')"><Strikethrough class="h-4 w-4" /></button>

      <span :class="divider"></span>

      <button type="button" title="无序列表" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('insertUnorderedList')"><List class="h-4 w-4" /></button>
      <button type="button" title="有序列表" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('insertOrderedList')"><ListOrdered class="h-4 w-4" /></button>
      <button type="button" title="减少缩进" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('outdent')"><Outdent class="h-4 w-4" /></button>
      <button type="button" title="增加缩进" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('indent')"><Indent class="h-4 w-4" /></button>

      <span :class="divider"></span>

      <button type="button" title="插入图片" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="insertImage"><ImageIcon class="h-4 w-4" /></button>
      <button type="button" title="插入链接" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="insertLink"><Link2 class="h-4 w-4" /></button>
      <button type="button" title="移除链接" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('unlink')"><Link2Off class="h-4 w-4" /></button>

      <span :class="divider"></span>

      <button type="button" title="左对齐" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('justifyLeft')"><AlignLeft class="h-4 w-4" /></button>
      <button type="button" title="居中对齐" class="flex h-7 w-7 items-center justify-center rounded text-slate-600 hover:bg-slate-200" @click.prevent="exec('justifyCenter')"><AlignCenter class="h-4 w-4" /></button>
    </div>

    <!-- 编辑区 -->
    <div
      ref="editorRef"
      contenteditable="true"
      class="rich-editor min-h-32 w-full px-3 py-2.5 text-[13px] leading-6 text-slate-700 outline-none"
      :data-placeholder="props.placeholder"
      @input="onInput"
    ></div>
  </div>
</template>

<style scoped>
.rich-editor:empty::before {
  content: attr(data-placeholder);
  color: #94a3b8;
  pointer-events: none;
}
.rich-editor :deep(img) {
  max-width: 100%;
  border-radius: 4px;
}
.rich-editor :deep(a) {
  color: #1677ff;
}
.rich-editor :deep(ul),
.rich-editor :deep(ol) {
  padding-left: 1.5em;
  list-style: revert;
}
</style>
