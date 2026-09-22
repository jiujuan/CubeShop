<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { CloudUpload, RefreshCw, Search, Trash2 } from 'lucide-vue-next'
import {
  deleteMedia, getMediaList, replaceMedia, updateMedia, uploadMedia,
  type MediaFile, type MediaSort,
} from '@/api/media'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 媒体库（图片资产治理 P2，权限 media.view / media.upload / media.manage）
 *
 * 与 P0 的「扫描 + 回收」闭环配套：这里负责**看得见**的那部分 ——
 * 浏览全部已登记图片、按模块/体积/是否未使用筛选、复用、替换、软删。
 *
 * ⚠️ 两个刻意的设计：
 * 1. **替换（replace）是一等公民**：换文件但保留 path，业务表里的相对路径一字不改，
 *    所有引用方自动生效 —— 这是全方案最实用的一环（换 banner 不用改任何数据）。
 * 2. **删除只软删**，且 `usage_count > 0` 时后端直接拒绝；物理删除由 `media:prune --force`
 *    人工执行。管理页不会、也不该有能力一键删掉正在展示的图。
 */
const auth = useAuthStore()
const canUpload = computed(() => auth.hasPermission('media.upload'))
const canManage = computed(() => auth.hasPermission('media.manage'))

// ---------------- 列表 ----------------
const loading = ref(false)
const items = ref<MediaFile[]>([])
const modules = ref<string[]>([])
const pagination = ref({ page: 1, page_size: 24, total: 0, total_pages: 1 })
const keyword = ref('')
const moduleFilter = ref('')
const unusedOnly = ref(false)
const sort = ref<MediaSort>('latest')

const sorts: Array<{ key: MediaSort; label: string }> = [
  { key: 'latest', label: '最新上传' },
  { key: 'oldest', label: '最早上传' },
  { key: 'largest', label: '体积从大到小' },
  { key: 'name', label: '按文件名' },
]

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getMediaList({
      keyword: keyword.value || undefined,
      module: moduleFilter.value || undefined,
      unused: unusedOnly.value || undefined,
      sort: sort.value,
      page,
      per_page: pagination.value.page_size,
    })
    items.value = data.data.list
    modules.value = data.data.modules
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(() => load(1))

function search() {
  load(1)
}

function reset() {
  keyword.value = ''
  moduleFilter.value = ''
  unusedOnly.value = false
  sort.value = 'latest'
  load(1)
}

// ---------------- 提示 ----------------
const tip = ref('')
const tipOk = ref(true)
function notify(text: string, ok = true) {
  tip.value = text
  tipOk.value = ok
  setTimeout(() => (tip.value = ''), 2800)
}

// ---------------- 上传 ----------------
const fileRef = ref<HTMLInputElement>()
const uploading = ref(false)

async function onUpload(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (!files || files.length === 0) return

  uploading.value = true
  try {
    let reused = 0
    for (const file of Array.from(files)) {
      const res = await uploadMedia(file, moduleFilter.value || undefined)
      if (res.data.data.reused) reused += 1
    }
    notify(reused > 0 ? `上传完成（${reused} 张内容与已有图片重复，已直接复用）` : '上传完成')
    load(pagination.value.page)
  } catch (err) {
    notify(err instanceof Error ? err.message : '上传失败', false)
  } finally {
    uploading.value = false
    if (fileRef.value) fileRef.value.value = ''
  }
}

// ---------------- 替换 ----------------
const replaceRef = ref<HTMLInputElement>()
const replacingId = ref<number | null>(null)

function pickReplace(id: number) {
  replacingId.value = id
  replaceRef.value?.click()
}

async function onReplace(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  const id = replacingId.value
  if (!file || id === null) return

  try {
    const { data } = await replaceMedia(id, file)
    const extra = data.data.reused_paths.length > 0
      ? `（另有 ${data.data.reused_paths.length} 条记录与该图内容相同，也一并生效）`
      : ''
    notify(`已替换：路径未变，所有引用自动生效${extra}`)
    load(pagination.value.page)
  } catch (err) {
    notify(err instanceof Error ? err.message : '替换失败', false)
  } finally {
    replacingId.value = null
    input.value = ''
  }
}

// ---------------- 改名 ----------------
const editingId = ref<number | null>(null)
const editingName = ref('')

function startRename(m: MediaFile) {
  editingId.value = m.id
  editingName.value = m.original_name ?? ''
}

async function submitRename(m: MediaFile) {
  const name = editingName.value.trim()
  editingId.value = null
  if (name === '' || name === (m.original_name ?? '')) return

  try {
    await updateMedia(m.id, { original_name: name })
    m.original_name = name
    notify('已更新')
  } catch (err) {
    notify(err instanceof Error ? err.message : '更新失败', false)
  }
}

// ---------------- 删除 ----------------
const confirmId = ref<number | null>(null)
const confirmTarget = computed(() => items.value.find((m) => m.id === confirmId.value) ?? null)

async function doDelete() {
  const id = confirmId.value
  if (id === null) return
  confirmId.value = null

  try {
    await deleteMedia(id)
    notify('已删除（30 天回收窗口内可恢复，物理文件暂留）')
    load(pagination.value.page)
  } catch (err) {
    notify(err instanceof Error ? err.message : '删除失败', false)
  }
}

// ---------------- 查看大图 ----------------
const previewOpen = ref(false)
const previewUrl = ref('')
const previewName = ref('')

function openPreview(m: MediaFile) {
  previewUrl.value = m.url
  previewName.value = m.original_name ?? m.path
  previewOpen.value = true
}

function closePreview() {
  previewOpen.value = false
}
</script>

<template>
  <div class="p-6">
    <div class="mb-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">媒体库</h2>
        <p class="mt-0.5 text-[13px] text-slate-500">
          全站图片资产（商品图 / Banner / CMS 等）。替换文件会保留路径，引用方自动生效；删除只进 30 天回收窗口。
        </p>
      </div>
      <div class="flex items-center gap-2">
        <Button variant="outline" @click="load(pagination.page)">
          <RefreshCw class="mr-1 h-4 w-4" /> 刷新
        </Button>
        <Button v-if="canUpload" class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="uploading" @click="fileRef?.click()">
          <CloudUpload class="mr-1 h-4 w-4" /> {{ uploading ? '上传中…' : '上传图片' }}
        </Button>
        <input
          ref="fileRef"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          class="hidden"
          multiple
          data-testid="media-upload-input"
          @change="onUpload"
        />
        <!-- 替换用的隐藏 input（与上传分开：一次只换一张） -->
        <input
          ref="replaceRef"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          class="hidden"
          @change="onReplace"
        />
      </div>
    </div>

    <!-- 工具行 -->
    <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-white p-3 shadow-sm">
      <div class="relative">
        <Search class="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
        <input
          v-model="keyword"
          class="w-56 rounded-md border border-slate-300 py-1.5 pl-8 pr-2 text-[13px] outline-none focus:border-[#1677ff]"
          placeholder="文件名 / 路径"
          data-testid="media-keyword"
          @keyup.enter="search"
        />
      </div>
      <select
        v-model="moduleFilter"
        class="rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
        data-testid="media-module"
        @change="search"
      >
        <option value="">全部模块</option>
        <option v-for="m in modules" :key="m" :value="m">{{ m }}</option>
      </select>
      <select
        v-model="sort"
        class="rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
        data-testid="media-sort"
        @change="search"
      >
        <option v-for="s in sorts" :key="s.key" :value="s.key">{{ s.label }}</option>
      </select>
      <label class="flex items-center gap-1.5 text-[13px] text-slate-600">
        <input v-model="unusedOnly" type="checkbox" data-testid="media-unused" @change="search" />
        只看未使用
      </label>
      <button
        class="rounded-md bg-[#1677ff] px-3 py-1.5 text-[13px] text-white hover:bg-[#4096ff]"
        data-testid="media-search"
        @click="search"
      >搜索</button>
      <button class="rounded-md border border-slate-300 px-3 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="reset">
        重置
      </button>
    </div>

    <p v-if="tip" class="mb-3 rounded-md px-3 py-2 text-[13px]" :class="tipOk ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-500'">
      {{ tip }}
    </p>

    <!-- 网格 -->
    <div v-if="loading" class="py-20"><LoadingSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-lg bg-white py-20 text-center text-[13px] text-slate-400 shadow-sm">
      没有匹配的图片
    </p>
    <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
      <div
        v-for="m in items"
        :key="m.id"
        class="group overflow-hidden rounded-lg border bg-white shadow-sm"
        :class="m.usage_count > 0 ? 'border-slate-200' : 'border-amber-200'"
        data-testid="media-card"
      >
        <div class="relative">
          <img
            :src="m.url"
            :alt="m.original_name ?? ''"
            class="h-32 w-full cursor-pointer bg-slate-50 object-cover"
            loading="lazy"
            data-testid="media-thumb"
            @click="openPreview(m)"
          />
          <span
            v-if="m.usage_count === 0"
            class="absolute left-1 top-1 rounded bg-amber-500 px-1.5 py-0.5 text-[10px] text-white"
          >未使用</span>
          <span
            v-if="!m.exists_on_disk"
            class="absolute right-1 top-1 rounded bg-red-500 px-1.5 py-0.5 text-[10px] text-white"
          >文件缺失</span>
        </div>

        <div class="p-2">
          <input
            v-if="editingId === m.id"
            v-model="editingName"
            class="w-full rounded border border-[#1677ff] px-1 py-0.5 text-[12px] outline-none"
            autofocus
            @blur="submitRename(m)"
            @keyup.enter="submitRename(m)"
          />
          <button
            v-else
            class="block w-full truncate text-left text-[12px] text-slate-700 hover:text-[#1677ff]"
            :title="m.path"
            :disabled="!canManage"
            @click="startRename(m)"
          >{{ m.original_name || m.path }}</button>

          <p class="mt-1 text-[11px] text-slate-400">
            {{ m.module }} · {{ m.size_human }}<template v-if="m.width"> · {{ m.width }}×{{ m.height }}</template>
          </p>
          <p class="text-[11px]" :class="m.usage_count > 0 ? 'text-slate-400' : 'text-amber-600'">
            引用 {{ m.usage_count }} 处
          </p>

          <div v-if="canManage" class="mt-2 flex gap-1">
            <button
              class="flex-1 rounded border border-slate-300 px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50"
              data-testid="media-replace"
              @click="pickReplace(m.id)"
            >替换</button>
            <button
              class="rounded border border-slate-300 px-2 py-1 text-[11px] text-slate-600 hover:bg-red-50 hover:text-red-500"
              data-testid="media-delete"
              @click="confirmId = m.id"
            >
              <Trash2 class="h-3 w-3" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- 分页（与后台「商品列表」等列表页一致） -->
    <TablePagination :pagination="pagination" @change="load" />

    <ConfirmDialog
      :open="confirmId !== null"
      danger
      title="删除图片"
      :message="confirmTarget ? `确定删除「${confirmTarget.original_name || confirmTarget.path}」？删除后进入 30 天回收窗口，期间可被引用自愈恢复；物理文件由 media:prune 统一处理。` : ''"
      @cancel="confirmId = null"
      @confirm="doDelete"
    />

    <!-- 查看大图 -->
    <Teleport to="body">
      <div
        v-if="previewOpen"
        class="fixed inset-0 z-50 flex flex-col items-center justify-center bg-black/70 p-4"
        data-testid="media-preview"
        @click.self="closePreview"
      >
        <button
          class="absolute right-4 top-4 text-white/80 hover:text-white"
          data-testid="media-preview-close"
          @click="closePreview"
        >✕</button>
        <img :src="previewUrl" :alt="previewName" class="max-h-[82vh] max-w-[92vw] rounded-lg object-contain shadow-xl" />
        <p class="mt-3 max-w-[92vw] truncate text-[13px] text-white/80">{{ previewName }}</p>
      </div>
    </Teleport>
  </div>
</template>
