<script setup lang="ts">
import { ref } from 'vue'
import { MdEditor, type ExposeParam } from 'md-editor-v3'
import 'md-editor-v3/lib/style.css'
import { uploadImage } from '@/api/product'

/**
 * Markdown 编辑器（内容中心 CMS · CMS-108）
 *
 * 封装 md-editor-v3，统一四件事，消除帮助中心与公告两处的重复引入：
 * - 中文界面 + 精简工具栏（去掉 github / save）
 * - 图片上传钩子：走后台统一上传接口 `POST /api/admin/upload`
 * - 对外只暴露 `v-model` 契约与 `upload-error` 事件
 * - 透出 `insertAtCursor()`：正文里插商品卡标记等「在光标处放一段文本」的场景
 *   （底层是 md-editor-v3 的 `insert`，会尊重当前选区/光标位置）
 *
 * 正文的源是 Markdown；HTML 产物由后端 MarkdownRenderer 渲染 + HtmlSanitizer
 * 白名单净化后派生 —— 前端既不生成 HTML 也不做净化。
 */
withDefaults(
  defineProps<{
    modelValue: string
    height?: string
    placeholder?: string
    disabled?: boolean
  }>(),
  {
    height: '420px',
    placeholder: '',
    disabled: false,
  },
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  (e: 'upload-error', message: string): void
}>()

const editorRef = ref<ExposeParam>()

/**
 * 在光标处插入一段文本，并把光标移到插入内容之后
 *
 * `deviationStart/End` 是相对插入文本末尾的偏移：这里用它把光标停在插入块之后，
 * 连续插几张卡时顺序才符合直觉（否则光标停在块前，第二张会插到第一张前面）。
 */
function insertAtCursor(text: string) {
  editorRef.value?.insert(() => ({
    targetValue: text,
    deviationStart: text.length,
    deviationEnd: text.length,
  }))
}

defineExpose({ insertAtCursor })

/** md-editor-v3 图片上传钩子：上传成功后回填 markdown 图片语法 */
async function onUploadImg(
  files: File[],
  callback: (urls: Array<{ url: string; alt: string; title: string }>) => void,
) {
  try {
    const results = await Promise.all(files.map((f) => uploadImage(f)))

    callback(
      results.map(({ data }, i) => ({
        url: data.data.url,
        alt: files[i]?.name ?? '图片',
        title: files[i]?.name ?? '',
      })),
    )
  } catch (e) {
    callback([])
    emit('upload-error', e instanceof Error ? e.message : '图片上传失败')
  }
}
</script>

<template>
  <MdEditor
    ref="editorRef"
    :model-value="modelValue"
    language="zh-CN"
    :toolbars-exclude="['github', 'save']"
    :style="{ height }"
    :placeholder="placeholder"
    :disabled="disabled"
    :on-upload-img="onUploadImg"
    @update:model-value="emit('update:modelValue', $event)"
  />
</template>

<!--
  不套外层 div：`data-testid` 等透传属性必须落在编辑器根节点上（测试按根节点读 v-model 值）。
  组件根是子组件 MdEditor，Vue 会把本组件的 scope id 加到该根元素上，
  故 `.md-editor` 这条 scoped 选择器可直接命中真正的编辑器根节点。
-->
<style scoped>
/*
 * 与站内表单控件统一（体例同营销管理页的 `outline-none focus:border-[#1677ff]`）：
 * md-editor-v3 默认边框是 #e6e6e6，比相邻控件的 border-slate-300 浅。
 * 静态统一为 slate-300，光标进入编辑器（含工具栏）时描边变主色蓝。
 */
.md-editor {
  border-color: #cbd5e1;
  transition: border-color 0.2s;
}
.md-editor:focus-within {
  border-color: #1677ff;
}
</style>
