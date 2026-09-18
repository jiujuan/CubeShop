<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { MdEditor } from 'md-editor-v3'
import 'md-editor-v3/lib/style.css'
import {
  createAnnouncement, deleteAnnouncement, getAnnouncements, offlineAnnouncement,
  previewAnnouncement, publishAnnouncement, updateAnnouncement,
  type AnnouncementDetail, type AnnouncementRow,
} from '@/api/announcement'
import { uploadImage } from '@/api/product'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 公告管理（P-Announcement，权限 announcement.manage）
 *
 * 列表（筛选 + 分页）+ 新建/编辑弹窗（md-editor-v3 编辑 Markdown 正文，图片走后台统一上传）
 * + 发布/下架/删除。无 announcement.manage 时页面只读。
 */
const auth = useAuthStore()
const canManage = computed(() => auth.hasPermission('announcement.manage'))

interface AnnouncementForm {
  title: string
  content_md: string
  is_top: boolean
  status: 'draft' | 'published' | 'offline'
}

const STATUS_LABELS: Record<string, string> = { draft: '草稿', published: '已发布', offline: '已下架' }
const STATUS_CLASS: Record<string, string> = {
  draft: 'bg-slate-100 text-slate-500',
  published: 'bg-green-50 text-green-600',
  offline: 'bg-red-50 text-red-500',
}

// ---------------- 列表 ----------------
const loading = ref(false)
const rows = ref<AnnouncementRow[]>([])
const pagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
const keyword = ref('')
const statusFilter = ref('')

async function load() {
  loading.value = true
  try {
    const { data } = await getAnnouncements({
      keyword: keyword.value || undefined,
      status: statusFilter.value || undefined,
      page: pagination.value.page,
      per_page: pagination.value.page_size,
    })
    rows.value = data.data.list
    pagination.value = data.data.pagination
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '加载失败')
  } finally {
    loading.value = false
  }
}

function onPageChange(page: number) {
  pagination.value.page = page
  load()
}

function resetAndLoad() {
  pagination.value.page = 1
  load()
}

// ---------------- 提示 ----------------
const tip = ref('')
const tipType = ref<'ok' | 'err'>('ok')
let tipTimer: ReturnType<typeof setTimeout> | null = null
function notify(type: 'ok' | 'err', text: string) {
  tipType.value = type
  tip.value = text
  if (tipTimer) clearTimeout(tipTimer)
  tipTimer = setTimeout(() => (tip.value = ''), 2600)
}

// ---------------- 编辑弹窗 ----------------
const showEditor = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const form = ref<AnnouncementForm>({ title: '', content_md: '', is_top: false, status: 'draft' })

function openCreate() {
  editingId.value = null
  form.value = { title: '', content_md: '', is_top: false, status: 'draft' }
  showEditor.value = true
}

async function openEdit(row: AnnouncementRow) {
  try {
    const { data } = await previewAnnouncement(row.id)
    const d: AnnouncementDetail = data.data
    editingId.value = d.id
    form.value = {
      title: d.title,
      content_md: d.content_md ?? '',
      is_top: d.is_top,
      status: d.status,
    }
    showEditor.value = true
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '加载详情失败')
  }
}

function closeEditor() {
  showEditor.value = false
}

async function saveEditor() {
  if (!form.value.title.trim()) {
    notify('err', '请填写标题')
    return
  }
  if (!form.value.content_md.trim()) {
    notify('err', '请填写正文')
    return
  }
  saving.value = true
  try {
    if (editingId.value == null) {
      await createAnnouncement({ ...form.value })
      notify('ok', '已创建')
    } else {
      await updateAnnouncement(editingId.value, { ...form.value })
      notify('ok', '已更新')
    }
    showEditor.value = false
    resetAndLoad()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '保存失败')
  } finally {
    saving.value = false
  }
}

// ---------------- 发布 / 下架 / 删除 ----------------
async function doPublish(row: AnnouncementRow) {
  try {
    await publishAnnouncement(row.id)
    notify('ok', '已发布')
    resetAndLoad()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '操作失败')
  }
}

async function doOffline(row: AnnouncementRow) {
  try {
    await offlineAnnouncement(row.id)
    notify('ok', '已下架')
    resetAndLoad()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '操作失败')
  }
}

const deleteTarget = ref<AnnouncementRow | null>(null)
function askDelete(row: AnnouncementRow) {
  deleteTarget.value = row
}
async function confirmDelete() {
  if (!deleteTarget.value) return
  try {
    await deleteAnnouncement(deleteTarget.value.id)
    notify('ok', '已删除')
    resetAndLoad()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '删除失败')
  } finally {
    deleteTarget.value = null
  }
}

// ---------------- md-editor-v3 图片上传 ----------------
async function onUploadImg(
  files: File[],
  callback: (urls: Array<{ url: string; alt: string; title: string }>) => void,
) {
  try {
    const results = await Promise.all(files.map((f) => uploadImage(f)))
    callback(results.map(({ data }) => ({ url: data.data.url, alt: '', title: '' })))
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '图片上传失败')
  }
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <h2 class="text-base font-semibold text-slate-800">公告管理</h2>

    <!-- 筛选行 -->
    <div class="mt-4 flex flex-wrap items-center gap-2">
      <input
        v-model="keyword"
        type="text"
        placeholder="搜索标题 / 正文"
        class="h-9 w-56 rounded-md border border-slate-300 px-3 text-sm outline-none focus:border-[#1677ff]"
        data-testid="announcement-keyword"
        @keyup.enter="resetAndLoad"
      />
      <select
        v-model="statusFilter"
        class="h-9 rounded-md border border-slate-300 px-2 text-sm outline-none focus:border-[#1677ff]"
        data-testid="announcement-status-filter"
      >
        <option value="">全部状态</option>
        <option value="draft">草稿</option>
        <option value="published">已发布</option>
        <option value="offline">已下架</option>
      </select>
      <button
        class="h-9 rounded-md bg-[#1677ff] px-4 text-sm text-white transition-colors hover:bg-[#4096ff]"
        data-testid="announcement-search"
        @click="resetAndLoad"
      >查询</button>

      <div class="flex-1" />
      <button
        v-if="canManage"
        class="h-9 rounded-md border border-[#1677ff] px-4 text-sm text-[#1677ff] transition-colors hover:bg-[#eaf4ff]"
        data-testid="announcement-create"
        @click="openCreate"
      >新建公告</button>
    </div>

    <!-- 列表 -->
    <div class="mt-4">
      <LoadingSpinner v-if="loading" />
      <table v-else class="w-full text-left text-sm" data-testid="announcement-table">
        <thead>
          <tr class="border-b border-slate-100 text-xs text-slate-400">
            <th class="py-2.5 font-medium">标题</th>
            <th class="w-16 py-2.5 font-medium">置顶</th>
            <th class="w-20 py-2.5 font-medium">状态</th>
            <th class="w-40 py-2.5 font-medium">发布时间</th>
            <th v-if="canManage" class="w-44 py-2.5 text-right font-medium">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id" class="border-b border-slate-50 hover:bg-slate-50/60">
            <td class="max-w-[420px] py-2.5">
              <div class="truncate font-medium text-slate-700" :title="row.title">{{ row.title }}</div>
              <div v-if="row.summary" class="mt-0.5 truncate text-xs text-slate-400">{{ row.summary }}</div>
            </td>
            <td class="py-2.5">
              <span v-if="row.is_top" class="rounded bg-[#eaf4ff] px-1.5 py-0.5 text-xs text-[#1677ff]">置顶</span>
              <span v-else class="text-slate-300">—</span>
            </td>
            <td class="py-2.5">
              <span class="rounded px-1.5 py-0.5 text-xs" :class="STATUS_CLASS[row.status]">{{ STATUS_LABELS[row.status] }}</span>
            </td>
            <td class="py-2.5 text-xs text-slate-500">{{ row.published_at ?? '—' }}</td>
            <td v-if="canManage" class="py-2.5 text-right">
              <button class="mr-2 text-xs text-[#1677ff] hover:underline" data-testid="announcement-edit" @click="openEdit(row)">编辑</button>
              <button
                v-if="row.status !== 'published'"
                class="mr-2 text-xs text-green-600 hover:underline"
                data-testid="announcement-publish"
                @click="doPublish(row)"
              >发布</button>
              <button
                v-else
                class="mr-2 text-xs text-orange-500 hover:underline"
                data-testid="announcement-offline"
                @click="doOffline(row)"
              >下架</button>
              <button class="text-xs text-red-500 hover:underline" data-testid="announcement-delete" @click="askDelete(row)">删除</button>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="5" class="py-10 text-center text-sm text-slate-400">暂无公告</td>
          </tr>
        </tbody>
      </table>
    </div>

    <TablePagination v-if="rows.length" :pagination="pagination" @change="onPageChange" />

    <!-- 编辑弹窗 -->
    <div v-if="showEditor" class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4" @click.self="closeEditor">
      <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-lg bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
          <span class="text-sm font-semibold text-slate-800">{{ editingId == null ? '新建公告' : '编辑公告' }}</span>
          <button class="text-slate-400 hover:text-slate-600" @click="closeEditor">✕</button>
        </div>

        <div class="flex-1 space-y-3 overflow-y-auto px-5 py-4">
          <label class="block text-[13px]">
            <span class="mb-1 block text-slate-500">标题</span>
            <input
              v-model="form.title"
              type="text"
              maxlength="128"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
              data-testid="announcement-form-title"
            />
          </label>

          <div class="flex items-center gap-4">
            <label class="flex items-center gap-1.5 text-[13px] text-slate-600">
              <input v-model="form.is_top" type="checkbox" /> 置顶
            </label>
            <label class="flex items-center gap-1.5 text-[13px] text-slate-600">
              <span>状态</span>
              <select v-model="form.status" class="rounded-md border border-slate-300 px-2 py-1 text-sm outline-none focus:border-[#1677ff]">
                <option value="draft">草稿</option>
                <option value="published">已发布</option>
                <option value="offline">已下架</option>
              </select>
            </label>
          </div>

          <div class="text-[13px]">
            <span class="mb-1 block text-slate-500">正文（Markdown）</span>
            <MdEditor
              v-model="form.content_md"
              language="zh-CN"
              :toolbars-exclude="['github', 'save']"
              :style="{ height: '420px' }"
              :on-upload-img="onUploadImg"
              data-testid="announcement-form-content"
            />
          </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
          <button class="h-9 rounded-md border border-slate-300 px-4 text-sm text-slate-600 hover:bg-slate-50" @click="closeEditor">取消</button>
          <button
            class="h-9 rounded-md bg-[#1677ff] px-4 text-sm text-white transition-colors hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="saving"
            data-testid="announcement-form-save"
            @click="saveEditor"
          >{{ saving ? '保存中…' : '保存' }}</button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :open="!!deleteTarget"
      title="删除公告"
      :message="`确认删除「${deleteTarget?.title ?? ''}」？此操作不可恢复。`"
      confirm-text="删除"
      danger
      @confirm="confirmDelete"
      @cancel="deleteTarget = null"
    />

    <!-- 提示 -->
    <transition name="fade">
      <div
        v-if="tip"
        class="fixed left-1/2 top-6 z-40 -translate-x-1/2 rounded-md px-4 py-2 text-sm text-white shadow-lg"
        :class="tipType === 'ok' ? 'bg-green-500' : 'bg-red-500'"
      >{{ tip }}</div>
    </transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
