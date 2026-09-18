<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  createHomeBanner, deleteHomeBanner, getHomeBanners, toggleHomeBanner, updateHomeBanner,
  type BannerPosition, type HomeBannerRow,
} from '@/api/homeBanner'
import { uploadImage } from '@/api/product'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 首页广告位管理（P-HomeBanner，权限 home.manage）
 *
 * 统一维护首页三块运营位：主轮播图（banner，多张）、中部广告宫格（promo，建议 4 张）、
 * 底部广告图（bottom，建议 2 张）。每条记录：图片 + 大标题 + 小标题 + 跳转链接 + 排序 + 启用。
 * 无 home.manage 时页面只读。
 */
const auth = useAuthStore()
const canManage = computed(() => auth.hasPermission('home.manage'))

interface BannerForm {
  position: BannerPosition
  image: string
  title: string
  subtitle: string
  link_url: string
  sort_order: number
  is_enabled: boolean
}

// ---------------- 列表 ----------------
const loading = ref(false)
const rows = ref<HomeBannerRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const keyword = ref('')
const positionFilter = ref('')

async function load() {
  loading.value = true
  try {
    const { data } = await getHomeBanners({
      keyword: keyword.value || undefined,
      position: positionFilter.value || undefined,
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
const uploading = ref(false)
const form = ref<BannerForm>(emptyForm())

function emptyForm(): BannerForm {
  return { position: 'banner', image: '', title: '', subtitle: '', link_url: '', sort_order: 0, is_enabled: true }
}

function openCreate() {
  editingId.value = null
  form.value = emptyForm()
  showEditor.value = true
}

function openEdit(row: HomeBannerRow) {
  editingId.value = row.id
  form.value = {
    position: row.position,
    image: row.image,
    title: row.title,
    subtitle: row.subtitle ?? '',
    link_url: row.link_url ?? '',
    sort_order: row.sort_order,
    is_enabled: row.is_enabled,
  }
  showEditor.value = true
}

function closeEditor() {
  showEditor.value = false
}

// 图片上传（后台统一上传接口 POST /api/admin/upload）
const fileInput = ref<HTMLInputElement | null>(null)
function pickImage() {
  fileInput.value?.click()
}
async function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploading.value = true
  try {
    const { data } = await uploadImage(file)
    form.value.image = data.data.url
    notify('ok', '图片已上传')
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '图片上传失败')
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function saveEditor() {
  if (!form.value.title.trim()) {
    notify('err', '请填写大标题')
    return
  }
  if (!form.value.image.trim()) {
    notify('err', '请上传图片')
    return
  }
  saving.value = true
  try {
    const payload = {
      ...form.value,
      subtitle: form.value.subtitle.trim() || undefined,
      link_url: form.value.link_url.trim() || undefined,
    }
    if (editingId.value == null) {
      await createHomeBanner(payload)
      notify('ok', '已创建')
    } else {
      await updateHomeBanner(editingId.value, payload)
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

// ---------------- 启停 / 删除 ----------------
async function doToggle(row: HomeBannerRow) {
  try {
    await toggleHomeBanner(row.id)
    notify('ok', row.is_enabled ? '已停用' : '已启用')
    resetAndLoad()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '操作失败')
  }
}

const deleteTarget = ref<HomeBannerRow | null>(null)
function askDelete(row: HomeBannerRow) {
  deleteTarget.value = row
}
async function confirmDelete() {
  if (!deleteTarget.value) return
  try {
    await deleteHomeBanner(deleteTarget.value.id)
    notify('ok', '已删除')
    resetAndLoad()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '删除失败')
  } finally {
    deleteTarget.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <h2 class="text-base font-semibold text-slate-800">首页广告位管理</h2>

    <!-- 筛选行 -->
    <div class="mt-4 flex flex-wrap items-center gap-2">
      <input
        v-model="keyword"
        type="text"
        placeholder="搜索标题 / 副标题"
        class="h-9 w-56 rounded-md border border-slate-300 px-3 text-sm outline-none focus:border-[#1677ff]"
        data-testid="banner-keyword"
        @keyup.enter="resetAndLoad"
      />
      <select
        v-model="positionFilter"
        class="h-9 rounded-md border border-slate-300 px-2 text-sm outline-none focus:border-[#1677ff]"
        data-testid="banner-position-filter"
      >
        <option value="">全部位置</option>
        <option value="banner">主轮播图</option>
        <option value="promo">中部广告位</option>
        <option value="bottom">底部广告位</option>
      </select>
      <button
        class="h-9 rounded-md bg-[#1677ff] px-4 text-sm text-white transition-colors hover:bg-[#4096ff]"
        data-testid="banner-search"
        @click="resetAndLoad"
      >查询</button>

      <div class="flex-1" />
      <button
        v-if="canManage"
        class="h-9 rounded-md border border-[#1677ff] px-4 text-sm text-[#1677ff] transition-colors hover:bg-[#eaf4ff]"
        data-testid="banner-create"
        @click="openCreate"
      >新建广告位</button>
    </div>

    <!-- 列表 -->
    <div class="mt-4">
      <LoadingSpinner v-if="loading" />
      <table v-else class="w-full text-left text-sm" data-testid="banner-table">
        <thead>
          <tr class="border-b border-slate-100 text-xs text-slate-400">
            <th class="w-20 py-2.5 font-medium">图片</th>
            <th class="py-2.5 font-medium">大标题</th>
            <th class="py-2.5 font-medium">小标题</th>
            <th class="w-24 py-2.5 font-medium">位置</th>
            <th class="w-14 py-2.5 font-medium">排序</th>
            <th class="w-16 py-2.5 font-medium">状态</th>
            <th v-if="canManage" class="w-36 py-2.5 text-right font-medium">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id" class="border-b border-slate-50 hover:bg-slate-50/60">
            <td class="py-2.5">
              <img :src="row.image" alt="" class="h-10 w-16 rounded object-cover" />
            </td>
            <td class="max-w-[240px] py-2.5">
              <div class="truncate font-medium text-slate-700" :title="row.title">{{ row.title }}</div>
              <div v-if="row.link_url" class="mt-0.5 truncate text-xs text-slate-400" :title="row.link_url">{{ row.link_url }}</div>
            </td>
            <td class="max-w-[200px] py-2.5 text-xs text-slate-500">
              <span class="block truncate">{{ row.subtitle ?? '—' }}</span>
            </td>
            <td class="py-2.5">
              <span class="rounded bg-[#eaf4ff] px-1.5 py-0.5 text-xs text-[#1677ff]">{{ row.position_label }}</span>
            </td>
            <td class="py-2.5 text-xs text-slate-500">{{ row.sort_order }}</td>
            <td class="py-2.5">
              <span
                class="rounded px-1.5 py-0.5 text-xs"
                :class="row.is_enabled ? 'bg-green-50 text-green-600' : 'bg-slate-100 text-slate-400'"
              >{{ row.is_enabled ? '启用' : '停用' }}</span>
            </td>
            <td v-if="canManage" class="py-2.5 text-right">
              <button class="mr-2 text-xs text-[#1677ff] hover:underline" data-testid="banner-edit" @click="openEdit(row)">编辑</button>
              <button class="mr-2 text-xs text-orange-500 hover:underline" data-testid="banner-toggle" @click="doToggle(row)">
                {{ row.is_enabled ? '停用' : '启用' }}
              </button>
              <button class="text-xs text-red-500 hover:underline" data-testid="banner-delete" @click="askDelete(row)">删除</button>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="7" class="py-10 text-center text-sm text-slate-400">暂无广告位，点击右上角「新建广告位」添加</td>
          </tr>
        </tbody>
      </table>
    </div>

    <TablePagination v-if="rows.length" :pagination="pagination" @change="onPageChange" />

    <!-- 编辑弹窗 -->
    <div v-if="showEditor" class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4" @click.self="closeEditor">
      <div class="flex max-h-[90vh] w-full max-w-lg flex-col rounded-lg bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
          <span class="text-sm font-semibold text-slate-800">{{ editingId == null ? '新建广告位' : '编辑广告位' }}</span>
          <button class="text-slate-400 hover:text-slate-600" @click="closeEditor">✕</button>
        </div>

        <div class="flex-1 space-y-3 overflow-y-auto px-5 py-4">
          <label class="block text-[13px]">
            <span class="mb-1 block text-slate-500">位置</span>
            <select
              v-model="form.position"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
              data-testid="banner-form-position"
            >
              <option value="banner">主轮播图（首页多张轮播）</option>
              <option value="promo">中部广告位（建议 4 张）</option>
              <option value="bottom">底部广告位（建议 2 张）</option>
            </select>
          </label>

          <div class="text-[13px]">
            <span class="mb-1 block text-slate-500">图片</span>
            <div class="flex items-center gap-3">
              <img v-if="form.image" :src="form.image" alt="" class="h-16 w-28 rounded border border-slate-200 object-cover" />
              <div
                v-else
                class="flex h-16 w-28 items-center justify-center rounded border border-dashed border-slate-300 text-xs text-slate-400"
              >未上传</div>
              <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFileChange" />
              <button
                class="h-9 rounded-md border border-[#1677ff] px-3 text-sm text-[#1677ff] transition-colors hover:bg-[#eaf4ff] disabled:opacity-50"
                :disabled="uploading"
                data-testid="banner-form-upload"
                @click="pickImage"
              >{{ uploading ? '上传中…' : '上传图片' }}</button>
            </div>
          </div>

          <label class="block text-[13px]">
            <span class="mb-1 block text-slate-500">大标题</span>
            <input
              v-model="form.title"
              type="text"
              maxlength="64"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
              data-testid="banner-form-title"
            />
          </label>

          <label class="block text-[13px]">
            <span class="mb-1 block text-slate-500">小标题（可选）</span>
            <input
              v-model="form.subtitle"
              type="text"
              maxlength="128"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
              data-testid="banner-form-subtitle"
            />
          </label>

          <label class="block text-[13px]">
            <span class="mb-1 block text-slate-500">跳转链接（可选，站内路由如 /search?keyword=数码 或完整 URL）</span>
            <input
              v-model="form.link_url"
              type="text"
              maxlength="500"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
              data-testid="banner-form-link"
            />
          </label>

          <div class="flex items-center gap-4">
            <label class="block text-[13px]">
              <span class="mb-1 block text-slate-500">排序（小者在前）</span>
              <input
                v-model.number="form.sort_order"
                type="number"
                min="0"
                max="9999"
                class="w-24 rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
                data-testid="banner-form-sort"
              />
            </label>
            <label class="mt-5 flex items-center gap-1.5 text-[13px] text-slate-600">
              <input v-model="form.is_enabled" type="checkbox" data-testid="banner-form-enabled" /> 启用
            </label>
          </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
          <button class="h-9 rounded-md border border-slate-300 px-4 text-sm text-slate-600 hover:bg-slate-50" @click="closeEditor">取消</button>
          <button
            class="h-9 rounded-md bg-[#1677ff] px-4 text-sm text-white transition-colors hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="saving"
            data-testid="banner-form-save"
            @click="saveEditor"
          >{{ saving ? '保存中…' : '保存' }}</button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :open="!!deleteTarget"
      title="删除广告位"
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
