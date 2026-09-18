<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Pencil, Plus, Search, Settings2, Boxes } from 'lucide-vue-next'

import {
  createWmsWarehouse,
  getWmsWarehouses,
  updateWmsWarehouse,
  WMS_MAPPING_MODE_LABELS,
  type WarehousePayload,
  type WarehouseRow,
} from '@/api/wms'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 仓库档案（WMS 计划 P0 / F1，权限 wms.config.manage）
 *
 * 仓库是 WMS 配置、SKU 映射、履约与退货入库的归属维度。
 * 本页负责仓库 CRUD，「配置 WMS」「SKU 映射」跳转到对应子页。
 */
const router = useRouter()

const loading = ref(true)
const list = ref<WarehouseRow[]>([])
const pagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })

const keyword = ref('')
const statusFilter = ref<'' | 0 | 1>('')

/** 弹层：null=关闭；createMode 区分新增/编辑 */
const showForm = ref(false)
const createMode = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const form = reactive<WarehousePayload>({
  code: '', name: '', contact_name: '', contact_phone: '',
  province: '', city: '', district: '', address: '', status: 1,
})

const formValid = computed(() => form.code.trim().length > 0 && form.name.trim().length > 0)

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getWmsWarehouses({
      keyword: keyword.value || undefined,
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

function goPage(page: number) {
  load(page)
}

function openCreate() {
  createMode.value = true
  editingId.value = null
  Object.assign(form, {
    code: '', name: '', contact_name: '', contact_phone: '',
    province: '', city: '', district: '', address: '', status: 1,
  })
  showForm.value = true
}

function openEdit(row: WarehouseRow) {
  createMode.value = false
  editingId.value = row.id
  Object.assign(form, {
    code: row.code,
    name: row.name,
    contact_name: row.contact_name ?? '',
    contact_phone: row.contact_phone ?? '',
    province: row.province ?? '',
    city: row.city ?? '',
    district: row.district ?? '',
    address: row.address ?? '',
    status: row.status,
  })
  showForm.value = true
}

async function doSave() {
  if (!formValid.value || saving.value) return
  saving.value = true
  try {
    if (createMode.value) {
      await createWmsWarehouse({ ...form })
    } else if (editingId.value !== null) {
      await updateWmsWarehouse(editingId.value, { ...form })
    }
    showForm.value = false
    await load(pagination.value.page)
  } catch {
    // 错误提示由 request 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

function gotoConfig(row: WarehouseRow) {
  router.push(`/wms/warehouses/${row.id}/config`)
}

function gotoMappings(row: WarehouseRow) {
  router.push(`/wms/warehouses/${row.id}/mappings`)
}

onMounted(() => load())
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">仓库档案</h2>
      <Button class="bg-[#1677ff] hover:bg-[#4096ff]" data-testid="warehouse-create" @click="openCreate">
        <Plus class="mr-1 h-4 w-4" /> 新增仓库
      </Button>
    </div>

    <!-- 筛选 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="keyword"
        type="text"
        placeholder="仓库编码 / 名称"
        class="w-48 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="search"
      />
      <select
        v-model="statusFilter"
        data-testid="warehouse-status-filter"
        class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]"
      >
        <option value="">全部状态</option>
        <option :value="1">启用</option>
        <option :value="0">停用</option>
      </select>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" @click="search">
        <Search class="mr-1 h-4 w-4" /> 搜索
      </Button>
    </div>

    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">编码</th>
          <th class="px-3 py-1.5">名称</th>
          <th class="px-3 py-1.5">联系人</th>
          <th class="px-3 py-1.5">地址</th>
          <th class="px-3 py-1.5">WMS 对接</th>
          <th class="w-20 px-3 py-1.5">状态</th>
          <th class="w-52 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 font-mono text-black">{{ row.code }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.name }}</td>
          <td class="px-3 py-1.5 text-slate-500">
            {{ row.contact_name || '—' }}
            <template v-if="row.contact_phone"><span class="text-slate-400">（{{ row.contact_phone }}）</span></template>
          </td>
          <td class="px-3 py-1.5 text-slate-500">{{ [row.province, row.city, row.district, row.address].filter(Boolean).join('') || '—' }}</td>
          <td class="px-3 py-1.5">
            <template v-if="row.wms">
              <span class="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-[#1677ff]">{{ row.wms.provider_label }}</span>
              <span class="ml-1 rounded px-1.5 py-0.5 text-xs" :class="row.wms.enabled ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'">
                {{ row.wms.enabled ? '已启用' : '已停用' }}
              </span>
              <span class="ml-1 text-xs text-slate-400">
                {{ row.wms.api_env === 'prod' ? '生产' : '沙箱' }} ·
                {{ WMS_MAPPING_MODE_LABELS[row.wms.sku_mapping_mode] }}
              </span>
            </template>
            <span v-else class="text-xs text-slate-400">未配置</span>
          </td>
          <td class="px-3 py-1.5">
            <span
              class="rounded px-2 py-0.5 text-xs"
              :class="row.status === 1 ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'"
            >{{ row.status === 1 ? '启用' : '停用' }}</span>
          </td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-3 text-[#1677ff]">
              <button class="flex items-center gap-0.5 hover:underline" :data-testid="`warehouse-config-${row.id}`" @click="gotoConfig(row)">
                <Settings2 class="h-3 w-3" /> 配置 WMS
              </button>
              <button class="flex items-center gap-0.5 hover:underline" :data-testid="`warehouse-mapping-${row.id}`" @click="gotoMappings(row)">
                <Boxes class="h-3 w-3" /> SKU 映射
              </button>
              <button class="flex items-center gap-0.5 hover:underline" :data-testid="`warehouse-edit-${row.id}`" @click="openEdit(row)">
                <Pencil class="h-3 w-3" /> 编辑
              </button>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="7"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="7" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <TablePagination :pagination="pagination" @change="goPage" />

    <!-- 新增 / 编辑弹窗 -->
    <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="showForm = false">
      <div class="w-full max-w-md rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">{{ createMode ? '新增仓库' : `编辑仓库：${form.code}` }}</h3>

        <div class="mt-4 flex gap-3">
          <div class="flex-1">
            <label class="block text-xs text-slate-500">仓库编码 <span class="text-red-500">*</span></label>
            <input
              v-model="form.code"
              type="text"
              placeholder="如 WH_SZ"
              data-testid="warehouse-form-code"
              class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]"
            />
          </div>
          <div class="flex-1">
            <label class="block text-xs text-slate-500">仓库名称 <span class="text-red-500">*</span></label>
            <input
              v-model="form.name"
              type="text"
              placeholder="如 深圳仓"
              data-testid="warehouse-form-name"
              class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
            />
          </div>
        </div>

        <div class="mt-3 flex gap-3">
          <div class="flex-1">
            <label class="block text-xs text-slate-500">联系人</label>
            <input v-model="form.contact_name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
          </div>
          <div class="flex-1">
            <label class="block text-xs text-slate-500">联系电话</label>
            <input v-model="form.contact_phone" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
          </div>
        </div>

        <div class="mt-3 flex gap-3">
          <div class="flex-1">
            <label class="block text-xs text-slate-500">省</label>
            <input v-model="form.province" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
          </div>
          <div class="flex-1">
            <label class="block text-xs text-slate-500">市</label>
            <input v-model="form.city" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
          </div>
          <div class="flex-1">
            <label class="block text-xs text-slate-500">区</label>
            <input v-model="form.district" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
          </div>
        </div>

        <label class="mt-3 block text-xs text-slate-500">详细地址</label>
        <input v-model="form.address" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />

        <label class="mt-3 block text-xs text-slate-500">状态</label>
        <select v-model.number="form.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]">
          <option :value="1">启用</option>
          <option :value="0">停用</option>
        </select>

        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="showForm = false">取消</button>
          <button
            class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="!formValid || saving"
            data-testid="warehouse-form-save"
            @click="doSave"
          >{{ saving ? '保存中…' : '保存' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
