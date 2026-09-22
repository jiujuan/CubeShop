<script setup lang="ts">
import { ref } from 'vue'
import { ArrowDown, ArrowUp, ImagePlus, Plus, Trash2, X } from 'lucide-vue-next'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import ImagePicker from '@/components/ImagePicker.vue'
import { uploadCmsImage, type CmsPageField, type CsChannelOption } from '@/api/cs'

/**
 * 单页字段表单（内容中心 CMS · CMS-110）
 *
 * 完全由后端 `CmsField` 真源的 schema 驱动渲染，覆盖八种字段类型：
 * text / textarea / markdown / image / image_list / repeater / select / channels
 * （后两种为 CMS-203 引入区块后新增）。
 *
 * 只往外 emit 一份完整的字段值对象；未知字段天然不渲染（schema 外的东西进不来）。
 * 空值回落到 '' 或 []，由父组件负责与后端返回的 values 合并。
 *
 * `channels` 类型的候选项由父组件传入（父组件本就持有栏目树），
 * 表单保持纯函数式、不自己发请求。
 */
const props = withDefaults(defineProps<{
  schema: CmsPageField[]
  modelValue: Record<string, unknown>
  disabled?: boolean
  /** 仅 channels 类型需要：可选栏目（只含 type=channel，由父组件过滤好） */
  channels?: CsChannelOption[]
}>(), { channels: () => [] })

const emit = defineEmits<{
  (e: 'update:modelValue', value: Record<string, unknown>): void
  (e: 'upload-error', message: string): void
}>()

/** 正在上传中的字段 key（用于按钮态） */
const uploading = ref('')

/** 单值字段取字符串 */
function val(key: string): string {
  const v = props.modelValue[key]
  return v === null || v === undefined ? '' : String(v)
}

/** image_list 取字符串数组 */
function imageList(key: string): string[] {
  const v = props.modelValue[key]
  return Array.isArray(v) ? (v as string[]) : []
}

/** repeater 取行数组 */
function rows(key: string): Array<Record<string, string>> {
  const v = props.modelValue[key]
  return Array.isArray(v) ? (v as Array<Record<string, string>>) : []
}

function setValue(key: string, value: unknown) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}

/** 层级缩进（channels 下拉里靠前缀空白表达父子，避免引入组件依赖） */
function indent(level: number): string {
  return '\u00a0\u00a0'.repeat(Math.max(0, level - 1))
}

// ---------- 图片 ----------

async function onImageChange(event: Event, key: string) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  uploading.value = key
  try {
    const { data } = await uploadCmsImage(file)
    setValue(key, data.data.url)
  } catch (e) {
    emit('upload-error', e instanceof Error ? e.message : '图片上传失败')
  } finally {
    uploading.value = ''
    input.value = ''
  }
}

async function onImageListChange(event: Event, key: string) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  if (!files.length) return

  uploading.value = key
  try {
    const results = await Promise.all(files.map((f) => uploadCmsImage(f)))
    setValue(key, [...imageList(key), ...results.map((r) => r.data.data.url)])
  } catch (e) {
    emit('upload-error', e instanceof Error ? e.message : '图片上传失败')
  } finally {
    uploading.value = ''
    input.value = ''
  }
}

function removeImage(key: string, index: number) {
  const list = [...imageList(key)]
  list.splice(index, 1)
  setValue(key, list)
}

// 媒体库选图：原 uploadCmsImage 上传入口保留，另提供「从媒体库选择」复用已有图片
const pickerOpen = ref(false)
const pickerKey = ref('')
const pickerMultiple = ref(false)
function openLibrary(key: string, multiple: boolean) {
  pickerKey.value = key
  pickerMultiple.value = multiple
  pickerOpen.value = true
}
function onPicked(urls: string[]) {
  if (!pickerKey.value) return
  if (pickerMultiple.value) {
    setValue(pickerKey.value, [...imageList(pickerKey.value), ...urls])
  } else {
    setValue(pickerKey.value, urls[0] ?? '')
  }
  pickerKey.value = ''
}

// ---------- repeater ----------

function addRow(field: CmsPageField) {
  const row: Record<string, string> = {}
  for (const sub of field.item ?? []) row[sub.key] = ''

  setValue(field.key, [...rows(field.key), row])
}

function removeRow(field: CmsPageField, index: number) {
  const list = [...rows(field.key)]
  list.splice(index, 1)
  setValue(field.key, list)
}

/** 上下移动一行（原地交换，不做拖拽） */
function moveRow(field: CmsPageField, index: number, delta: number) {
  const list = [...rows(field.key)]
  const target = index + delta

  if (target < 0 || target >= list.length) return

  const tmp = list[index]
  list[index] = list[target]
  list[target] = tmp
  setValue(field.key, list)
}

function setRowValue(field: CmsPageField, index: number, subKey: string, value: string) {
  const list = rows(field.key).map((r) => ({ ...r }))
  list[index][subKey] = value
  setValue(field.key, list)
}
</script>

<template>
  <div class="space-y-5">
    <div v-for="field in schema" :key="field.key" :data-testid="`page-field-${field.key}`">
      <div class="text-[13px]">
        <span class="font-medium text-slate-700">{{ field.label }}</span>
        <span v-if="field.required" class="ml-1 text-red-500">*</span>
        <span class="ml-2 font-mono text-xs text-slate-400">{{ field.key }}</span>
      </div>

      <!-- 单行文本 -->
      <input
        v-if="field.type === 'text'"
        type="text"
        :value="val(field.key)"
        :disabled="disabled"
        maxlength="500"
        class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff] disabled:bg-slate-50"
        :data-testid="`page-field-input-${field.key}`"
        @input="setValue(field.key, ($event.target as HTMLInputElement).value)"
      />

      <!-- 多行文本 -->
      <textarea
        v-else-if="field.type === 'textarea'"
        :value="val(field.key)"
        :disabled="disabled"
        rows="3"
        class="mt-1 w-full rounded border border-slate-200 px-2 py-1.5 text-sm outline-none focus:border-[#1677ff] disabled:bg-slate-50"
        :data-testid="`page-field-textarea-${field.key}`"
        @input="setValue(field.key, ($event.target as HTMLTextAreaElement).value)"
      />

      <!-- 下拉选择（可选值来自后端 schema 的 options） -->
      <select
        v-else-if="field.type === 'select'"
        :value="val(field.key)"
        :disabled="disabled"
        class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff] disabled:bg-slate-50"
        :data-testid="`page-field-select-${field.key}`"
        @change="setValue(field.key, ($event.target as HTMLSelectElement).value)"
      >
        <option v-for="opt in field.options ?? []" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>

      <!-- 栏目选择（只列「栏目」类型，不含单页） -->
      <select
        v-else-if="field.type === 'channels'"
        :value="val(field.key)"
        :disabled="disabled"
        class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff] disabled:bg-slate-50"
        :data-testid="`page-field-channels-${field.key}`"
        @change="setValue(field.key, ($event.target as HTMLSelectElement).value)"
      >
        <option value="">不选择</option>
        <option v-for="ch in channels" :key="ch.id" :value="String(ch.id)">{{ indent(ch.level) }}{{ ch.name }}</option>
      </select>

      <!-- Markdown 正文 -->
      <div v-else-if="field.type === 'markdown'" class="mt-1">
        <MarkdownEditor
          :model-value="val(field.key)"
          height="320px"
          :disabled="disabled"
          :data-testid="`page-field-markdown-${field.key}`"
          @update:model-value="(v: string) => setValue(field.key, v)"
          @upload-error="(msg: string) => emit('upload-error', msg)"
        />
      </div>

      <!-- 单图 -->
      <div v-else-if="field.type === 'image'" class="mt-1 flex items-center gap-3">
        <div
          class="flex h-20 w-40 items-center justify-center overflow-hidden rounded border border-dashed border-slate-300 bg-slate-50"
        >
          <img
            v-if="val(field.key)"
            :src="val(field.key)"
            alt="图片预览"
            class="h-20 w-40 object-contain"
            :data-testid="`page-field-preview-${field.key}`"
          />
          <ImagePlus v-else class="h-5 w-5 text-slate-300" />
        </div>
        <div class="flex flex-col gap-2">
          <label
            class="flex h-8 cursor-pointer items-center gap-1.5 rounded border border-slate-200 px-3 text-[13px] text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]"
          >
            <ImagePlus class="h-3.5 w-3.5" />
            {{ uploading === field.key ? '上传中…' : '选择图片' }}
            <input
              type="file"
              accept="image/*"
              class="hidden"
              :disabled="disabled || uploading === field.key"
              :data-testid="`page-field-upload-${field.key}`"
              @change="onImageChange($event, field.key)"
            />
          </label>
          <button
            type="button"
            v-permission="'media.view'"
            class="flex h-8 items-center gap-1.5 self-start rounded border border-slate-300 px-3 text-[13px] text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]"
            :data-testid="`page-field-library-${field.key}`"
            @click="openLibrary(field.key, false)"
          >从媒体库选择</button>
          <button
            v-if="val(field.key)"
            class="flex h-8 items-center gap-1.5 self-start rounded px-3 text-[13px] text-slate-500 hover:text-red-500"
            :data-testid="`page-field-clear-${field.key}`"
            @click="setValue(field.key, '')"
          >
            <X class="h-3.5 w-3.5" /> 移除
          </button>
        </div>
      </div>

      <!-- 多图 -->
      <div v-else-if="field.type === 'image_list'" class="mt-1">
        <div class="flex flex-wrap items-center gap-2">
          <div
            v-for="(url, index) in imageList(field.key)"
            :key="`${field.key}-${index}`"
            class="relative h-20 w-20 overflow-hidden rounded border border-slate-200"
          >
            <img :src="url" alt="图片" class="h-20 w-20 object-cover" />
            <button
              class="absolute right-0 top-0 flex h-5 w-5 items-center justify-center rounded-bl bg-black/50 text-white"
              :data-testid="`page-field-img-remove-${field.key}-${index}`"
              @click="removeImage(field.key, index)"
            >
              <X class="h-3 w-3" />
            </button>
          </div>
          <label
            class="flex h-20 w-20 cursor-pointer items-center justify-center rounded border border-dashed border-slate-300 bg-slate-50 text-slate-400 hover:border-[#1677ff] hover:text-[#1677ff]"
          >
            <Plus class="h-5 w-5" />
            <input
              type="file"
              accept="image/*"
              multiple
              class="hidden"
              :disabled="disabled || uploading === field.key"
              :data-testid="`page-field-imglist-${field.key}`"
              @change="onImageListChange($event, field.key)"
            />
          </label>
          <button
            type="button"
            v-permission="'media.view'"
            class="flex h-20 w-20 items-center justify-center rounded border border-dashed border-slate-300 bg-slate-50 text-slate-400 hover:border-[#1677ff] hover:text-[#1677ff]"
            :data-testid="`page-field-library-${field.key}`"
            @click="openLibrary(field.key, true)"
          >媒体库</button>
        </div>
      </div>

      <!-- 重复行 -->
      <div v-else-if="field.type === 'repeater'" class="mt-1 space-y-2">
        <div
          v-for="(row, index) in rows(field.key)"
          :key="`${field.key}-row-${index}`"
          class="flex items-start gap-2 rounded border border-slate-200 p-2"
          :data-testid="`page-field-${field.key}-row-${index}`"
        >
          <div class="flex-1 space-y-2">
            <label
              v-for="sub in field.item ?? []"
              :key="sub.key"
              class="block text-[13px]"
            >
              <span class="text-slate-500">{{ sub.label }}</span>
              <textarea
                v-if="sub.type === 'textarea'"
                :value="row[sub.key] ?? ''"
                :disabled="disabled"
                rows="2"
                class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-sm outline-none focus:border-[#1677ff]"
                :data-testid="`page-field-${field.key}-${index}-${sub.key}`"
                @input="setRowValue(field, index, sub.key, ($event.target as HTMLTextAreaElement).value)"
              />
              <input
                v-else
                type="text"
                :value="row[sub.key] ?? ''"
                :disabled="disabled"
                class="mt-0.5 h-8 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff]"
                :data-testid="`page-field-${field.key}-${index}-${sub.key}`"
                @input="setRowValue(field, index, sub.key, ($event.target as HTMLInputElement).value)"
              />
            </label>
          </div>
          <div class="flex flex-col gap-1 pt-1">
            <button
              class="rounded p-1 text-slate-400 hover:text-[#1677ff] disabled:opacity-30"
              :disabled="index === 0"
              :data-testid="`page-field-${field.key}-up-${index}`"
              @click="moveRow(field, index, -1)"
            >
              <ArrowUp class="h-3.5 w-3.5" />
            </button>
            <button
              class="rounded p-1 text-slate-400 hover:text-[#1677ff] disabled:opacity-30"
              :disabled="index === rows(field.key).length - 1"
              :data-testid="`page-field-${field.key}-down-${index}`"
              @click="moveRow(field, index, 1)"
            >
              <ArrowDown class="h-3.5 w-3.5" />
            </button>
            <button
              class="rounded p-1 text-slate-400 hover:text-red-500"
              :data-testid="`page-field-${field.key}-remove-${index}`"
              @click="removeRow(field, index)"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
        <button
          class="flex h-8 items-center gap-1.5 rounded border border-dashed border-slate-300 px-3 text-[13px] text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff]"
          :disabled="disabled"
          :data-testid="`page-field-${field.key}-add`"
          @click="addRow(field)"
        >
          <Plus class="h-3.5 w-3.5" /> 添加一行
        </button>
      </div>

      <p v-if="field.hint" class="mt-1 text-[12px] text-slate-400">{{ field.hint }}</p>
    </div>

    <ImagePicker v-model:open="pickerOpen" module="cms" :multiple="pickerMultiple" :limit="20" @select="onPicked" />
  </div>
</template>
