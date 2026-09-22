<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Check, CloudUpload, Search, X } from 'lucide-vue-next'
import { getMediaList, uploadMedia, type MediaFile } from '@/api/media'
import { uploadImage } from '@/api/product'
import { useAuthStore } from '@/stores/auth'

/**
 * 统一选图器（图片资产治理 P2）
 *
 * 取代各页面散落的 `<input type="file">`：既能**直接上传**，也能**从媒体库复用已有图片**。
 * 后者正是治理的意义所在 —— 同一张 banner 换季复用、多商品共用素材，不必重复占空间。
 *
 * 权限降级：未持有 `media.view` 的账号（如只做商品录入的老账号）只显示「上传」Tab，
 * 且走既有 `/admin/upload` 接口，行为与改造前完全一致 —— 不因媒体库上线而卡住任何人。
 *
 * 用法：
 *   <ImagePicker v-model:open="pickerOpen" module="products" @select="(urls) => (main = urls[0])" />
 *   <ImagePicker v-model:open="pickerOpen" multiple :limit="10" @select="(urls) => images.push(...urls)" />
 */
const props = withDefaults(
  defineProps<{
    open: boolean
    /** 上传存放模块（决定 uploads/{module}/{Ymd} 目录） */
    module?: string
    /** 多选（详情图等）；单选时选中即返回 */
    multiple?: boolean
    /** 多选上限 */
    limit?: number
  }>(),
  { module: 'products', multiple: false, limit: 10 },
)

const emit = defineEmits<{
  'update:open': [boolean]
  select: [urls: string[]]
}>()

const auth = useAuthStore()
/** 无媒体库浏览权限时退化为纯上传（见文件头注释） */
const canBrowse = computed(() => auth.hasPermission('media.view'))

type Tab = 'upload' | 'library'
const tab = ref<Tab>('upload')

// ---------------- 已选 ----------------
const selected = ref<string[]>([])
const uploading = ref(false)
const tip = ref('')
const tipOk = ref(true)

function notify(text: string, ok = true) {
  tip.value = text
  tipOk.value = ok
  setTimeout(() => (tip.value = ''), 2600)
}

watch(
  () => props.open,
  (open) => {
    if (!open) return
    selected.value = []
    tip.value = ''
    tab.value = canBrowse.value ? 'library' : 'upload'
    if (canBrowse.value) loadLibrary(1)
  },
)

function close() {
  emit('update:open', false)
}

function toggle(url: string) {
  if (props.multiple) {
    const at = selected.value.indexOf(url)
    if (at >= 0) {
      selected.value.splice(at, 1)
    } else if (selected.value.length >= props.limit) {
      notify(`最多选择 ${props.limit} 张`, false)
    } else {
      selected.value.push(url)
    }

    return
  }

  // 单选：选中即确认，少一步操作
  emit('select', [url])
  close()
}

function confirm() {
  if (selected.value.length === 0) {
    notify('请先选择图片', false)

    return
  }
  emit('select', [...selected.value])
  close()
}

/** 上传 Tab 中移除已上传的预览（多选场景；单选会自动关闭不会走到这里） */
function removeSelected(url: string) {
  const at = selected.value.indexOf(url)
  if (at >= 0) selected.value.splice(at, 1)
}

// ---------------- 上传 ----------------
const fileRef = ref<HTMLInputElement>()

async function upload(files: FileList | File[] | null | undefined) {
  const list = Array.from(files ?? [])
  if (list.length === 0) return

  if (props.multiple && selected.value.length + list.length > props.limit) {
    notify(`最多选择 ${props.limit} 张`, false)

    return
  }

  uploading.value = true
  try {
    for (const file of list) {
      const url = canBrowse.value
        ? (await uploadMedia(file, props.module)).data.data.url
        : (await uploadImage(file)).data.data.url

      if (!props.multiple) {
        emit('select', [url])
        close()

        return
      }
      selected.value.push(url)
    }
    notify(list.length > 1 ? `已上传 ${list.length} 张` : '已上传')
  } catch (e) {
    notify(e instanceof Error ? e.message : '上传失败', false)
  } finally {
    uploading.value = false
    if (fileRef.value) fileRef.value.value = ''
  }
}

function onDrop(e: DragEvent) {
  e.preventDefault()
  upload(e.dataTransfer?.files)
}

// ---------------- 媒体库 ----------------
const loading = ref(false)
const items = ref<MediaFile[]>([])
const modules = ref<string[]>([])
const pagination = ref({ page: 1, page_size: 24, total: 0, total_pages: 1 })
const keyword = ref('')
const moduleFilter = ref('')
const unusedOnly = ref(false)

async function loadLibrary(page = 1) {
  if (!canBrowse.value) return

  loading.value = true
  try {
    const { data } = await getMediaList({
      keyword: keyword.value || undefined,
      module: moduleFilter.value || undefined,
      unused: unusedOnly.value || undefined,
      sort: 'latest',
      page,
      per_page: pagination.value.page_size,
    })
    items.value = data.data.list
    modules.value = data.data.modules
    pagination.value = data.data.pagination
  } catch (e) {
    notify(e instanceof Error ? e.message : '加载失败', false)
  } finally {
    loading.value = false
  }
}

function search() {
  loadLibrary(1)
}

function isSelected(url: string) {
  return selected.value.includes(url)
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40" @click="close"></div>

      <div class="relative flex h-[560px] w-[860px] max-w-[95vw] flex-col rounded-lg bg-white shadow-xl">
        <!-- 头部 -->
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
          <div class="flex items-center gap-3">
            <h3 class="text-sm font-semibold text-slate-800">
              {{ multiple ? `选择图片（最多 ${limit} 张）` : '选择图片' }}
            </h3>
            <div v-if="canBrowse" class="flex rounded-md bg-slate-100 p-0.5 text-[13px]">
              <button
                class="rounded px-3 py-1 transition-colors"
                :class="tab === 'library' ? 'bg-white text-[#1677ff] shadow-sm' : 'text-slate-500'"
                data-testid="picker-tab-library"
                @click="tab = 'library'"
              >媒体库</button>
              <button
                class="rounded px-3 py-1 transition-colors"
                :class="tab === 'upload' ? 'bg-white text-[#1677ff] shadow-sm' : 'text-slate-500'"
                data-testid="picker-tab-upload"
                @click="tab = 'upload'"
              >上传</button>
            </div>
          </div>
          <button class="text-slate-400 hover:text-slate-600" data-testid="picker-close" @click="close">
            <X class="h-4 w-4" />
          </button>
        </div>

        <!-- 上传 Tab -->
        <div
          v-if="tab === 'upload'"
          class="flex flex-1 flex-col items-center gap-3 overflow-y-auto p-8"
          @dragover.prevent
          @drop="onDrop"
        >
          <button
            class="flex h-40 w-full max-w-md flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-slate-300 text-slate-400 transition-colors hover:border-[#1677ff] hover:text-[#1677ff]"
            data-testid="picker-upload"
            @click="fileRef?.click()"
          >
            <CloudUpload class="h-8 w-8" />
            <span class="text-sm">{{ uploading ? '上传中…' : '点击选择图片，或拖拽到此处' }}</span>
            <span class="text-xs text-slate-400">支持 jpg / png / webp，单张 ≤ 5MB</span>
          </button>
          <input
            ref="fileRef"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="hidden"
            :multiple="multiple"
            @change="upload(($event.target as HTMLInputElement).files)"
          />

          <!-- 上传预览：上传后在此看到小图，可移除（多选场景） -->
          <div v-if="selected.length > 0" class="w-full max-w-md">
            <p class="mb-2 text-[12px] text-slate-400">{{ multiple ? `已上传 ${selected.length} 张，可点 ✕ 移除` : '已上传' }}</p>
            <div class="grid grid-cols-5 gap-2">
              <div
                v-for="url in selected"
                :key="url"
                class="group relative overflow-hidden rounded-md border border-slate-200 bg-slate-50"
              >
                <img :src="url" alt="" class="h-16 w-full object-cover" loading="lazy" />
                <button
                  v-if="multiple"
                  class="absolute right-0.5 top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-black/55 text-white opacity-0 transition-opacity group-hover:opacity-100"
                  data-testid="picker-upload-thumb-remove"
                  @click="removeSelected(url)"
                >
                  <X class="h-2.5 w-2.5" />
                </button>
              </div>
            </div>
          </div>

          <p v-if="!canBrowse" class="text-xs text-slate-400">当前账号无媒体库浏览权限，仅可上传新图。</p>
        </div>

        <!-- 媒体库 Tab -->
        <div v-else class="flex flex-1 flex-col overflow-hidden">
          <div class="flex flex-wrap items-center gap-2 px-5 py-3">
            <div class="relative">
              <Search class="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
              <input
                v-model="keyword"
                class="w-52 rounded-md border border-slate-300 py-1.5 pl-8 pr-2 text-[13px] outline-none focus:border-[#1677ff]"
                placeholder="文件名 / 路径"
                data-testid="picker-keyword"
                @keyup.enter="search"
              />
            </div>
            <select
              v-model="moduleFilter"
              class="rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
              data-testid="picker-module"
              @change="search"
            >
              <option value="">全部模块</option>
              <option v-for="m in modules" :key="m" :value="m">{{ m }}</option>
            </select>
            <label class="flex items-center gap-1.5 text-[13px] text-slate-600">
              <input v-model="unusedOnly" type="checkbox" data-testid="picker-unused" @change="search" />
              只看未使用
            </label>
            <button
              class="rounded-md border border-slate-300 px-3 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50"
              data-testid="picker-search"
              @click="search"
            >搜索</button>
            <span class="ml-auto text-xs text-slate-400">共 {{ pagination.total }} 张</span>
          </div>

          <div class="flex-1 overflow-y-auto px-5 pb-3">
            <p v-if="loading" class="py-10 text-center text-[13px] text-slate-400">加载中…</p>
            <p v-else-if="items.length === 0" class="py-10 text-center text-[13px] text-slate-400">
              没有匹配的图片，可切换到「上传」新增
            </p>
            <div v-else class="grid grid-cols-4 gap-3">
              <button
                v-for="m in items"
                :key="m.id"
                class="group relative overflow-hidden rounded-lg border bg-slate-50 transition-colors"
                :class="isSelected(m.url) ? 'border-[#1677ff] ring-1 ring-[#1677ff]' : 'border-slate-200 hover:border-[#1677ff]'"
                data-testid="picker-item"
                @click="toggle(m.url)"
              >
                <img :src="m.url" :alt="m.original_name ?? ''" class="h-24 w-full object-cover" loading="lazy" />
                <span
                  v-if="isSelected(m.url)"
                  class="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-[#1677ff] text-white"
                >
                  <Check class="h-3 w-3" />
                </span>
                <span class="block truncate px-1.5 py-1 text-left text-[11px] text-slate-500">
                  {{ m.original_name || m.path }}
                </span>
              </button>
            </div>
          </div>

          <div class="flex items-center justify-center gap-2 border-t border-slate-100 py-2 text-[13px]">
            <button
              class="rounded px-2 py-1 text-slate-500 disabled:opacity-40"
              :disabled="pagination.page <= 1"
              @click="loadLibrary(pagination.page - 1)"
            >上一页</button>
            <span class="text-slate-500">{{ pagination.page }} / {{ pagination.total_pages }}</span>
            <button
              class="rounded px-2 py-1 text-slate-500 disabled:opacity-40"
              :disabled="pagination.page >= pagination.total_pages"
              @click="loadLibrary(pagination.page + 1)"
            >下一页</button>
          </div>
        </div>

        <!-- 底部 -->
        <div class="flex items-center justify-between border-t border-slate-100 px-5 py-3">
          <p v-if="tip" class="text-[13px]" :class="tipOk ? 'text-green-600' : 'text-red-500'">{{ tip }}</p>
          <span v-else class="text-xs text-slate-400">
            {{ multiple ? `已选 ${selected.length} 张` : '点击图片即可选中' }}
          </span>
          <div class="flex gap-2">
            <button
              class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50"
              @click="close"
            >取消</button>
            <button
              v-if="multiple"
              class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
              data-testid="picker-confirm"
              :disabled="selected.length === 0"
              @click="confirm"
            >使用已选（{{ selected.length }}）</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
