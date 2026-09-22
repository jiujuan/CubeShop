<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Pencil, Plus, Search, Trash2 } from 'lucide-vue-next'
import {
  createBrand, deleteBrand, getBrands, updateBrand,
  type BrandRow, type Pagination,
} from '@/api/attribute'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import TablePagination from '@/components/TablePagination.vue'
import ImagePicker from '@/components/ImagePicker.vue'

/**
 * 品牌管理（V1.1 E01 / T-011）
 * 列表 + 新增/编辑弹窗 + 删除（被引用时后端拒绝并提示数量）
 */
const keyword = ref('')
const status = ref<number | ''>('')
const list = ref<BrandRow[]>([])
const pagination = ref<Pagination>({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(false)

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getBrands({
      keyword: keyword.value || undefined,
      status: status.value === '' ? undefined : status.value,
      page,
      page_size: 20,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(() => load())

// ---------- 编辑弹窗 ----------
const dialogOpen = ref(false)
const editingId = ref<number | null>(null)
const form = ref({ name: '', logo: '', sort: 0, status: 1 })
const formError = ref('')
const saving = ref(false)

function openCreate() {
  editingId.value = null
  form.value = { name: '', logo: '', sort: 0, status: 1 }
  formError.value = ''
  dialogOpen.value = true
}

function openEdit(row: BrandRow) {
  editingId.value = row.id
  form.value = { name: row.name, logo: row.logo ?? '', sort: row.sort, status: row.status }
  formError.value = ''
  dialogOpen.value = true
}

async function submit() {
  formError.value = ''
  if (!form.value.name.trim()) {
    formError.value = '请输入品牌名称'
    return
  }
  saving.value = true
  try {
    const payload = {
      name: form.value.name.trim(),
      logo: form.value.logo || null,
      sort: Number(form.value.sort) || 0,
      status: form.value.status,
    }
    if (editingId.value) await updateBrand(editingId.value, payload)
    else await createBrand(payload)
    dialogOpen.value = false
    await load(pagination.value.page)
  } catch (e) {
    formError.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

// ---------- 删除 ----------
const confirmState = ref<{ title: string; message: string; run: () => Promise<void> } | null>(null)
const deleteError = ref('')

function askDelete(row: BrandRow) {
  deleteError.value = ''
  confirmState.value = {
    title: '删除品牌',
    message: `确定删除品牌「${row.name}」？若已被商品引用将无法删除。`,
    run: async () => {
      try {
        await deleteBrand(row.id)
        await load(pagination.value.page)
      } catch (e) {
        deleteError.value = e instanceof Error ? e.message : '删除失败'
      }
    },
  }
}

async function onConfirm() {
  const c = confirmState.value
  confirmState.value = null
  await c?.run()
}

function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

// 品牌 logo：保留 URL 文本框（原入口），另提供「从媒体库选择」复用已有图片
const pickerOpen = ref(false)
function openLogoPicker() {
  pickerOpen.value = true
}
function onLogoPicked(urls: string[]) {
  if (urls.length) form.value.logo = urls[0]
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">品牌管理</h2>
      <Button v-permission="'product.update'" class="bg-[#1677ff] hover:bg-[#4096ff]" @click="openCreate">
        <Plus class="mr-0.5 h-4 w-4" /> 新建品牌
      </Button>
    </div>

    <!-- 筛选 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="keyword" type="text" placeholder="品牌名称"
        class="w-44 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="load(1)"
      />
      <select v-model="status" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="">状态</option>
        <option :value="1">启用</option>
        <option :value="0">停用</option>
      </select>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" @click="load(1)"><Search class="mr-1 h-4 w-4" /> 搜索</Button>
    </div>

    <p v-if="deleteError" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-[13px] text-red-500">{{ deleteError }}</p>

    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-16 px-3 py-2">ID</th>
          <th class="px-3 py-2">品牌</th>
          <th class="w-24 px-3 py-2">商品数</th>
          <th class="w-20 px-3 py-2">排序</th>
          <th class="w-20 px-3 py-2">状态</th>
          <th class="w-32 px-3 py-2">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-2 text-slate-500">{{ row.id }}</td>
          <td class="px-3 py-2">
            <div class="flex items-center gap-2">
              <img v-if="row.logo" :src="row.logo" class="h-7 w-7 rounded object-cover" alt="" />
              <span class="font-medium text-black">{{ row.name }}</span>
            </div>
          </td>
          <td class="px-3 py-2 text-slate-600">{{ row.product_count }}</td>
          <td class="px-3 py-2 text-slate-600">{{ row.sort }}</td>
          <td class="px-3 py-2">
            <span class="rounded px-2 py-0.5 text-xs" :class="row.status === 1 ? 'bg-[#e8f7ec] text-[#2e9e57]' : 'bg-slate-100 text-slate-400'">
              {{ row.status === 1 ? '启用' : '停用' }}
            </span>
          </td>
          <td class="px-3 py-2">
            <div class="flex items-center gap-2 text-[#1677ff]">
              <button v-permission="'product.update'" class="flex items-center gap-0.5 hover:underline" @click="openEdit(row)">
                <Pencil class="h-3.5 w-3.5" /> 编辑
              </button>
              <button
                v-permission="'product.update'" class="flex items-center gap-0.5 text-red-400 hover:underline"
                @click="askDelete(row)"
              >
                <Trash2 class="h-3.5 w-3.5" /> 删除
              </button>
            </div>
          </td>
        </tr>
        <tr v-if="!list.length && !loading"><td colspan="6" class="px-3 py-12 text-center text-slate-400">暂无品牌</td></tr>
      </tbody>
    </table>

    <TablePagination :pagination="pagination" :total-text="`共 ${pagination.total} 条`" @change="goPage" />

    <!-- 编辑弹窗 -->
    <div v-if="dialogOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-lg">
        <h3 class="mb-4 text-base font-semibold text-slate-800">{{ editingId ? '编辑品牌' : '新建品牌' }}</h3>
        <div class="space-y-3 text-[13px]">
          <div>
            <label class="mb-1 block text-slate-600">品牌名称 <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
          </div>
          <div>
            <label class="mb-1 block text-slate-600">Logo</label>
            <div class="flex items-start gap-3">
              <img v-if="form.logo" :src="form.logo" class="h-10 w-10 shrink-0 rounded border border-slate-200 object-cover" alt="" data-testid="brand-form-logo-preview" />
              <div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded border border-dashed border-slate-300 text-xs text-slate-400">无</div>
              <div class="flex flex-1 flex-col gap-1.5">
                <input v-model="form.logo" type="text" placeholder="Logo URL（也可从媒体库选择）" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="brand-form-logo-input" />
                <button
                  type="button"
                  v-permission="'media.view'"
                  class="w-fit rounded-md border border-[#1677ff] px-3 py-1.5 text-[#1677ff] transition-colors hover:bg-[#eaf4ff]"
                  data-testid="brand-form-library"
                  @click="openLogoPicker"
                >从媒体库选择</button>
              </div>
            </div>
          </div>
          <div class="flex gap-4">
            <div class="flex-1">
              <label class="mb-1 block text-slate-600">排序</label>
              <input v-model.number="form.sort" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
            </div>
            <div class="flex-1">
              <label class="mb-1 block text-slate-600">状态</label>
              <select v-model.number="form.status" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]">
                <option :value="1">启用</option>
                <option :value="0">停用</option>
              </select>
            </div>
          </div>
          <p v-if="formError" class="rounded-md bg-red-50 px-3 py-2 text-red-500">{{ formError }}</p>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <Button variant="outline" @click="dialogOpen = false">取消</Button>
          <Button class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="saving" @click="submit">{{ saving ? '保存中...' : '保存' }}</Button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :open="!!confirmState"
      :title="confirmState?.title"
      :message="confirmState?.message"
      confirm-text="删除"
      danger
      @confirm="onConfirm"
      @cancel="confirmState = null"
    />

    <ImagePicker v-model:open="pickerOpen" module="brands" @select="onLogoPicked" />
  </div>
</template>
