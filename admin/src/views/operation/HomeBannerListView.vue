<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { ChevronLeft, ChevronRight, Pencil, Plus, Search } from 'lucide-vue-next'
import {
  createHomeBanner, deleteHomeBanner, getHomeBanners, toggleHomeBanner, updateHomeBanner,
  type BannerPosition, type HomeBannerRow,
} from '@/api/homeBanner'
import { uploadImage } from '@/api/product'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 首页广告位管理（P-HomeBanner，权限 home.manage）
 *
 * UI 与营销管理页同款：标题旁 Tab 药丸（主轮播图 / 中部广告位 / 底部广告位）+
 * 面板内工具行（搜索/重置/新建）+ 表格 + 内联分页 + Teleport 弹层。
 * 每条记录：图片 + 大标题 + 小标题 + 跳转链接 + 排序 + 启用。
 */
type Tab = BannerPosition
const tab = ref<Tab>('banner')

const tabs: Array<{ key: Tab; label: string; hint: string }> = [
  { key: 'banner', label: '主轮播图', hint: '首页多张自动轮播' },
  { key: 'promo', label: '中部广告位', hint: '建议 4 张' },
  { key: 'bottom', label: '底部广告位', hint: '建议 2 张' },
]

// ---------------- 列表 ----------------
const loading = ref(false)
const rows = ref<HomeBannerRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const keyword = ref('')

async function load() {
  loading.value = true
  try {
    const { data } = await getHomeBanners({
      keyword: keyword.value || undefined,
      position: tab.value,
      page: pagination.value.page,
      per_page: pagination.value.page_size,
    })
    rows.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(load)

watch(tab, () => {
  pagination.value.page = 1
  load()
})

function search() {
  pagination.value.page = 1
  load()
}

function reset() {
  keyword.value = ''
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
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

// ---------------- 编辑弹层 ----------------
const formOpen = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const uploading = ref(false)

interface BannerForm {
  position: BannerPosition
  image: string
  title: string
  subtitle: string
  link_url: string
  sort_order: number
  is_enabled: boolean
}

function emptyForm(): BannerForm {
  return { position: tab.value, image: '', title: '', subtitle: '', link_url: '', sort_order: 0, is_enabled: true }
}
const form = ref<BannerForm>(emptyForm())

function openCreate() {
  editingId.value = null
  form.value = emptyForm()
  formOpen.value = true
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
  formOpen.value = true
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

async function save() {
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
    formOpen.value = false
    search()
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
    load()
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
    load()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '删除失败')
  } finally {
    deleteTarget.value = null
  }
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <!-- 标题 + Tab（与营销管理页同款药丸样式） -->
    <div class="mb-4 flex items-center gap-3">
      <h2 class="text-lg font-semibold text-slate-800">首页广告位</h2>
      <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm" data-testid="banner-tabs">
        <button
          v-for="t in tabs" :key="t.key"
          class="rounded-md px-4 py-1.5 transition-colors"
          :class="tab === t.key ? 'bg-white font-medium text-[#1677ff] shadow-sm' : 'text-slate-500 hover:text-slate-700'"
          :data-testid="`banner-tab-${t.key}`"
          @click="tab = t.key"
        >{{ t.label }}</button>
      </div>
    </div>

    <!-- 工具行 -->
    <div class="mb-3 flex flex-wrap items-center gap-2 text-[13px]">
      <span class="text-xs text-slate-400">{{ tabs.find((t) => t.key === tab)?.hint }}</span>
      <input
        v-model="keyword" type="text" placeholder="按标题 / 副标题搜索"
        class="w-56 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="banner-search-input"
        @keyup.enter="search"
      />
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" data-testid="banner-search-btn" @click="search">
        <Search class="mr-1 h-4 w-4" /> 搜索
      </Button>
      <Button variant="outline" data-testid="banner-reset-btn" @click="reset">重置</Button>
      <button
        v-permission="'home.manage'"
        class="ml-auto flex items-center gap-1 rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff]"
        data-testid="banner-create"
        @click="openCreate"
      ><Plus class="h-4 w-4" /> 新建广告位</button>
    </div>

    <p
      v-if="tip" class="mb-3 rounded-md px-3 py-2 text-xs"
      :class="tipType === 'ok' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-500'"
    >{{ tip }}</p>

    <table class="w-full text-[13px]" data-testid="banner-table">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">图片</th>
          <th class="px-3 py-1.5">大标题</th>
          <th class="px-3 py-1.5">小标题</th>
          <th class="px-3 py-1.5">跳转链接</th>
          <th class="px-3 py-1.5">排序</th>
          <th class="px-3 py-1.5">状态</th>
          <th class="px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-50 hover:bg-slate-50/60">
          <td class="px-3 py-2"><img :src="row.image" alt="" class="h-10 w-16 rounded object-cover" /></td>
          <td class="max-w-[220px] px-3 py-2">
            <div class="truncate font-medium text-slate-700" :title="row.title">{{ row.title }}</div>
          </td>
          <td class="max-w-[200px] px-3 py-2 text-slate-500">
            <span class="block truncate">{{ row.subtitle ?? '—' }}</span>
          </td>
          <td class="max-w-[200px] px-3 py-2 text-slate-400">
            <span class="block truncate" :title="row.link_url ?? ''">{{ row.link_url ?? '—' }}</span>
          </td>
          <td class="px-3 py-2 text-slate-500">{{ row.sort_order }}</td>
          <td class="px-3 py-2">
            <span
              class="rounded px-1.5 py-0.5 text-xs"
              :class="row.is_enabled ? 'bg-green-50 text-green-600' : 'bg-slate-100 text-slate-400'"
            >{{ row.is_enabled ? '启用' : '停用' }}</span>
          </td>
          <td class="px-3 py-2">
            <div class="flex items-center gap-2">
              <button class="text-[#1677ff] hover:underline" :data-testid="`banner-edit-${row.id}`" @click="openEdit(row)"><Pencil class="h-3.5 w-3.5" /></button>
              <button
                class="hover:underline" :class="row.is_enabled ? 'text-slate-400 hover:text-orange-500' : 'text-green-600 hover:underline'"
                :data-testid="`banner-toggle-${row.id}`"
                @click="doToggle(row)"
              >{{ row.is_enabled ? '停用' : '启用' }}</button>
              <button class="text-slate-400 hover:text-red-500" :data-testid="`banner-delete-${row.id}`" @click="askDelete(row)">删除</button>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="7"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!rows.length && !loading">
          <td colspan="7" class="px-3 py-12 text-center text-slate-400" data-testid="banner-empty">
            暂无广告位，点击右上角「新建广告位」添加
          </td>
        </tr>
      </tbody>
    </table>

    <!-- 分页（与营销管理同款内联分页） -->
    <div v-if="pagination.total_pages > 1" class="mt-4 flex items-center justify-between text-[13px] text-slate-500">
      <span>共 {{ pagination.total }} 条记录 / 每页 {{ pagination.page_size }} 条</span>
      <div class="flex items-center gap-1">
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)"
        ><ChevronLeft class="h-4 w-4" /></button>
        <button
          v-for="page in pagination.total_pages" :key="page"
          class="h-7 min-w-7 rounded border px-1.5"
          :class="page === pagination.page ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
          @click="goPage(page)"
        >{{ page }}</button>
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)"
        ><ChevronRight class="h-4 w-4" /></button>
      </div>
    </div>

    <!-- 创建/编辑弹层（与营销管理同款 Teleport + 卡片） -->
    <Teleport to="body">
      <div v-if="formOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="formOpen = false">
        <div class="max-h-[88vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" data-testid="banner-form-dialog">
          <h3 class="mb-4 text-base font-semibold text-slate-800">{{ editingId == null ? '新建广告位' : '编辑广告位' }}</h3>

          <div class="space-y-3 text-[13px]">
            <label class="block">
              <span class="mb-1 block text-slate-500">位置 *</span>
              <select
                v-model="form.position"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
                data-testid="banner-form-position"
              >
                <option value="banner">主轮播图（首页多张轮播）</option>
                <option value="promo">中部广告位（建议 4 张）</option>
                <option value="bottom">底部广告位（建议 2 张）</option>
              </select>
            </label>

            <div>
              <span class="mb-1 block text-slate-500">图片 *</span>
              <div class="flex items-center gap-3">
                <img v-if="form.image" :src="form.image" alt="" class="h-16 w-28 rounded border border-slate-200 object-cover" />
                <div
                  v-else
                  class="flex h-16 w-28 items-center justify-center rounded border border-dashed border-slate-300 text-xs text-slate-400"
                >未上传</div>
                <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFileChange" />
                <button
                  class="rounded-md border border-[#1677ff] px-3 py-2 text-[#1677ff] transition-colors hover:bg-[#eaf4ff] disabled:opacity-50"
                  :disabled="uploading"
                  data-testid="banner-form-upload"
                  @click="pickImage"
                >{{ uploading ? '上传中…' : '上传图片' }}</button>
              </div>
            </div>

            <label class="block">
              <span class="mb-1 block text-slate-500">大标题 *</span>
              <input
                v-model="form.title" type="text" maxlength="64"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
                data-testid="banner-form-title"
              />
            </label>

            <label class="block">
              <span class="mb-1 block text-slate-500">小标题（可选）</span>
              <input
                v-model="form.subtitle" type="text" maxlength="128"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
                data-testid="banner-form-subtitle"
              />
            </label>

            <label class="block">
              <span class="mb-1 block text-slate-500">跳转链接（可选，站内路由如 /search?keyword=数码 或完整 URL）</span>
              <input
                v-model="form.link_url" type="text" maxlength="500"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
                data-testid="banner-form-link"
              />
            </label>

            <div class="flex items-center gap-4">
              <label class="block">
                <span class="mb-1 block text-slate-500">排序（小者在前）</span>
                <input
                  v-model.number="form.sort_order" type="number" min="0" max="9999"
                  class="w-24 rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
                  data-testid="banner-form-sort"
                />
              </label>
              <label class="mt-5 flex items-center gap-1.5 text-slate-600">
                <input v-model="form.is_enabled" type="checkbox" data-testid="banner-form-enabled" /> 启用
              </label>
            </div>
          </div>

          <div class="mt-5 flex justify-end gap-3">
            <button class="rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-500" data-testid="banner-form-cancel" @click="formOpen = false">取消</button>
            <button
              class="rounded-md bg-[#1677ff] px-5 py-2 text-sm text-white hover:bg-[#4096ff] disabled:opacity-50"
              :disabled="saving"
              data-testid="banner-form-save"
              @click="save"
            >{{ saving ? '保存中…' : '保存' }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <ConfirmDialog
      :open="!!deleteTarget"
      title="删除广告位"
      :message="`确认删除「${deleteTarget?.title ?? ''}」？此操作不可恢复。`"
      confirm-text="删除"
      danger
      @confirm="confirmDelete"
      @cancel="deleteTarget = null"
    />
  </div>
</template>
