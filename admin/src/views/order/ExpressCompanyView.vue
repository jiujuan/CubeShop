<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  createShippingCompany,
  deleteShippingCompany,
  getShippingCompanies,
  updateShippingCompany,
  type ShippingCompany,
} from '@/api/order'
import { Pencil, Plus, Search, Trash2 } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 快递公司字典（V1.1 T-047，E03；权限 shipping.manage）
 * CRUD + 启停 + 排序 + channel_code（第三方查询渠道编码）。
 * 被运单引用的编码禁止删除（后端 40009），建议停用。
 */
const loading = ref(true)
const list = ref<ShippingCompany[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })

const keyword = ref('')
const statusFilter = ref<'' | 0 | 1>('')

// 编辑弹窗：{} 形态=新增（无 id），完整 ShippingCompany=编辑
const editing = ref<ShippingCompany | (Omit<ShippingCompany, 'id'> & { id?: never }) | null>(null)
const form = ref({ code: '', name: '', channel_code: '', sort: 0, status: 1 })
const saving = ref(false)
const deleting = ref<ShippingCompany | null>(null)

const isCreate = computed(() => editing.value !== null && editing.value.id === undefined)
const formValid = computed(() => form.value.code.trim() !== '' && form.value.name.trim() !== '')

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getShippingCompanies({
      keyword: keyword.value.trim() || undefined,
      status: statusFilter.value === '' ? undefined : statusFilter.value,
      page,
      page_size: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

function search() {
  load(1)
}

function openCreate() {
  editing.value = { code: '', name: '', channel_code: null, sort: 0, status: 1 }
  form.value = { code: '', name: '', channel_code: '', sort: list.value.length ? Math.max(...list.value.map((c) => c.sort)) + 10 : 0, status: 1 }
}

function openEdit(company: ShippingCompany) {
  editing.value = company
  form.value = {
    code: company.code,
    name: company.name,
    channel_code: company.channel_code ?? '',
    sort: company.sort,
    status: company.status,
  }
}

async function doSave() {
  if (!formValid.value || saving.value) return
  saving.value = true
  try {
    const payload = {
      name: form.value.name.trim(),
      channel_code: form.value.channel_code.trim() || null,
      sort: form.value.sort,
      status: form.value.status,
    }
    if (isCreate.value) {
      await createShippingCompany({ ...payload, code: form.value.code.trim() })
    } else if (editing.value) {
      await updateShippingCompany(editing.value.id as number, { ...payload, code: form.value.code.trim() })
    }
    editing.value = null
    await load(pagination.value.page)
  } finally {
    saving.value = false
  }
}

/** 启停切换（部分更新语义，只传 status） */
async function toggleStatus(company: ShippingCompany) {
  await updateShippingCompany(company.id, { status: company.status === 1 ? 0 : 1 })
  await load(pagination.value.page)
}

async function doDelete() {
  if (!deleting.value) return
  await deleteShippingCompany(deleting.value.id)
  deleting.value = null
  await load(pagination.value.page)
}

function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

onMounted(() => load())
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">快递公司字典</h2>
      <Button class="bg-[#1677ff] hover:bg-[#4096ff]" data-testid="company-create" @click="openCreate">
        <Plus class="mr-1 h-4 w-4" /> 新增快递公司
      </Button>
    </div>

    <!-- 筛选 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="keyword" type="text" placeholder="编码 / 名称"
        class="w-48 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="search"
      />
      <select v-model="statusFilter" data-testid="company-status-filter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="">全部状态</option>
        <option :value="1">启用</option>
        <option :value="0">停用</option>
      </select>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" @click="search"><Search class="mr-1 h-4 w-4" /> 搜索</Button>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">编码</th>
          <th class="px-3 py-1.5">名称</th>
          <th class="px-3 py-1.5">渠道编码</th>
          <th class="w-16 px-3 py-1.5">排序</th>
          <th class="w-20 px-3 py-1.5">状态</th>
          <th class="w-40 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="company in list" :key="company.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 font-mono text-black">{{ company.code }}</td>
          <td class="px-3 py-1.5 text-black">{{ company.name }}</td>
          <td class="px-3 py-1.5 font-mono text-slate-500">{{ company.channel_code || '—' }}</td>
          <td class="px-3 py-1.5 text-slate-500">{{ company.sort }}</td>
          <td class="px-3 py-1.5">
            <button
              class="rounded px-2 py-0.5 text-xs"
              :class="company.status === 1 ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'"
              :data-testid="`company-toggle-${company.code}`"
              :title="company.status === 1 ? '点击停用' : '点击启用'"
              @click="toggleStatus(company)"
            >{{ company.status === 1 ? '启用' : '停用' }}</button>
          </td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-2 text-[#1677ff]">
              <button class="flex items-center gap-0.5 hover:underline" :data-testid="`company-edit-${company.code}`" @click="openEdit(company)">
                <Pencil class="h-3 w-3" /> 编辑
              </button>
              <button class="flex items-center gap-0.5 text-red-500 hover:underline" :data-testid="`company-delete-${company.code}`" @click="deleting = company">
                <Trash2 class="h-3 w-3" /> 删除
              </button>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="6"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="6" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <TablePagination :pagination="pagination" @change="goPage" />

    <!-- 新增 / 编辑弹窗 -->
    <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="editing = null">
      <div class="w-full max-w-sm rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">{{ isCreate ? '新增快递公司' : `编辑：${editing.name}` }}</h3>

        <label class="mt-4 block text-xs text-slate-500">编码 <span class="text-red-500">*</span></label>
        <input
          v-model="form.code" type="text" placeholder="如 SF / ZTO / YTO"
          data-testid="company-form-code"
          class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] font-mono outline-none focus:border-[#1677ff]"
        />

        <label class="mt-3 block text-xs text-slate-500">名称 <span class="text-red-500">*</span></label>
        <input
          v-model="form.name" type="text" placeholder="如 顺丰速运"
          data-testid="company-form-name"
          class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
        />

        <label class="mt-3 block text-xs text-slate-500">渠道编码（第三方轨迹查询用，可空）</label>
        <input
          v-model="form.channel_code" type="text" placeholder="如 shunfeng"
          data-testid="company-form-channel"
          class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] font-mono outline-none focus:border-[#1677ff]"
        />

        <div class="mt-3 flex gap-3">
          <div class="flex-1">
            <label class="block text-xs text-slate-500">排序（越小越靠前）</label>
            <input v-model.number="form.sort" type="number" min="0" max="9999" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
          </div>
          <div class="flex-1">
            <label class="block text-xs text-slate-500">状态</label>
            <select v-model="form.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]">
              <option :value="1">启用</option>
              <option :value="0">停用</option>
            </select>
          </div>
        </div>

        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="editing = null">取消</button>
          <button
            class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="!formValid || saving"
            data-testid="company-form-save"
            @click="doSave"
          >{{ saving ? '保存中…' : '保存' }}</button>
        </div>
      </div>
    </div>

    <!-- 删除确认 -->
    <div v-if="deleting" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="deleting = null">
      <div class="w-full max-w-sm rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">删除快递公司</h3>
        <p class="mt-2 text-[13px] leading-5 text-slate-500">
          确定删除 <span class="font-mono">{{ deleting.code }}</span>（{{ deleting.name }}）？
          若已有运单记录，删除将被拒绝（建议改用停用）。
        </p>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="deleting = null">取消</button>
          <button class="rounded-md bg-red-500 px-4 py-1.5 text-[13px] text-white hover:bg-red-600" data-testid="company-delete-confirm" @click="doDelete">确认删除</button>
        </div>
      </div>
    </div>
  </div>
</template>
