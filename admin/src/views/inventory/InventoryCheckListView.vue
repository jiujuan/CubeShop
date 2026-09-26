<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  createInventoryCheck,
  getInventoryChecks,
  CHECK_SCOPE_LABELS,
  CHECK_STATUS_LABELS,
  type CheckScopeType,
  type CheckStatus,
  type InventoryCheckBrief,
} from '@/api/inventory-check'
import { getCategories } from '@/api/product'
import { getBrands } from '@/api/attribute'
import { Plus, Search } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'

/**
 * 库存盘点列表
 *
 * 盘点的是「平台可售库存」（inventories），不是仓库库存（wms_inventory_snapshots）。
 * 与 WMS 库存差异页的分工：那里是仓库系统对账，这里是人工实物校准。
 */
const router = useRouter()

const list = ref<InventoryCheckBrief[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(false)

const checkNo = ref('')
const statusFilter = ref<'' | CheckStatus>('')

// 新建弹层
const showCreate = ref(false)
const submitting = ref(false)
const formError = ref('')
const form = ref({
  title: '',
  scope_type: 'all' as CheckScopeType,
  scope_value: '',
  remark: '',
})
const file = ref<File | null>(null)
const categories = ref<{ id: number; name: string; children?: { id: number; name: string }[] }[]>([])
const brands = ref<{ id: number; name: string }[]>([])

async function load() {
  loading.value = true
  try {
    const { data } = await getInventoryChecks({
      check_no: checkNo.value.trim() || undefined,
      status: statusFilter.value || undefined,
      page: pagination.value.page,
      page_size: pagination.value.page_size,
    })
    list.value = data.data.list ?? []
    pagination.value = data.data.pagination ?? pagination.value
  } finally {
    loading.value = false
  }
}

function search() {
  pagination.value.page = 1
  load()
}

function reset() {
  checkNo.value = ''
  statusFilter.value = ''
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages) return
  pagination.value.page = p
  load()
}

async function openCreate() {
  showCreate.value = true
  formError.value = ''
  form.value = { title: '', scope_type: 'all', scope_value: '', remark: '' }
  file.value = null

  // 分类与品牌字典只在打开弹层时拉一次
  if (categories.value.length === 0) {
    try {
      const { data } = await getCategories()
      categories.value = (data.data ?? []) as typeof categories.value
    } catch {
      // 全局 toast 已提示
    }
  }
  if (brands.value.length === 0) {
    try {
      const { data } = await getBrands({ page_size: 100 })
      brands.value = (data.data.list ?? []) as typeof brands.value
    } catch {
      // 全局 toast 已提示
    }
  }
}

function closeCreate() {
  showCreate.value = false
}

function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const selected = input.files?.[0] ?? null
  file.value = selected && /\.(xlsx|xls)$/i.test(selected.name) ? selected : null
  if (selected && !file.value) {
    formError.value = '仅支持 .xlsx / .xls 文件'
  }
  input.value = ''
}

async function submitCreate() {
  if (submitting.value) return

  const scope = form.value.scope_type
  const value = form.value.scope_value.trim()
  if ((scope === 'category' || scope === 'brand' || scope === 'keyword') && value === '') {
    formError.value = '请填写或选择盘点范围'
    return
  }
  if (scope === 'custom' && !file.value) {
    formError.value = '自定义清单模式请上传含 SKU 编码的 xlsx 文件'
    return
  }

  submitting.value = true
  formError.value = ''
  try {
    const { data } = await createInventoryCheck({
      title: form.value.title.trim() || undefined,
      scope_type: scope,
      scope_value: value || undefined,
      remark: form.value.remark.trim() || undefined,
      file: file.value ?? undefined,
    })
    closeCreate()
    router.push(`/inventory-checks/${data.data.id}`)
  } catch (err) {
    formError.value = (err as Error).message || '创建失败'
  } finally {
    submitting.value = false
  }
}

function statusClass(status: CheckStatus): string {
  return {
    draft: 'bg-slate-100 text-slate-600',
    counting: 'bg-amber-50 text-amber-700',
    posted: 'bg-green-50 text-green-700',
    cancelled: 'bg-slate-100 text-slate-400',
  }[status]
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">库存盘点</h2>
        <p class="mt-1 text-[13px] text-slate-500">
          按范围生成盘点单并快照账面库存，录入实盘后过账校准。过账按「过账时刻」的库存计算差异，开单快照仅供展示。
        </p>
      </div>
      <Button class="bg-[#1677ff] hover:bg-[#4096ff]" data-testid="check-create-open" @click="openCreate">
        <Plus class="mr-1 h-4 w-4" /> 新建盘点
      </Button>
    </div>

    <!-- 筛选 -->
    <div class="mt-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="checkNo"
        type="text"
        placeholder="盘点单号"
        class="w-48 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="check-filter-no"
        @keyup.enter="search"
      />
      <select
        v-model="statusFilter"
        class="w-36 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="check-filter-status"
      >
        <option value="">全部状态</option>
        <option v-for="(label, key) in CHECK_STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <Button class="bg-[#1677ff] hover:bg-[#4096ff]" data-testid="check-search" @click="search">
        <Search class="mr-1 h-4 w-4" /> 搜索
      </Button>
      <Button variant="outline" @click="reset">重置</Button>
    </div>

    <!-- 列表 -->
    <table class="mt-4 w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-100 text-left text-slate-500">
          <th class="px-3 py-2">盘点单号</th>
          <th class="px-3 py-2">名称</th>
          <th class="px-3 py-2">范围</th>
          <th class="px-3 py-2">状态</th>
          <th class="px-3 py-2">明细</th>
          <th class="px-3 py-2">已盘</th>
          <th class="px-3 py-2">差异</th>
          <th class="px-3 py-2">创建人 / 时间</th>
          <th class="px-3 py-2">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="!list.length">
          <td colspan="9" class="px-3 py-6 text-center text-slate-400">暂无盘点单</td>
        </tr>
        <tr
          v-for="row in list"
          :key="row.id"
          class="border-b border-slate-50 hover:bg-slate-50"
          :data-testid="`check-row-${row.id}`"
        >
          <td class="px-3 py-2 font-mono text-slate-700">{{ row.check_no }}</td>
          <td class="px-3 py-2 text-slate-700">{{ row.title || '—' }}</td>
          <td class="px-3 py-2 text-slate-600">
            {{ CHECK_SCOPE_LABELS[row.scope_type] || row.scope_type }}
            <span v-if="row.scope_value" class="text-slate-400">（{{ row.scope_value }}）</span>
          </td>
          <td class="px-3 py-2">
            <span class="rounded px-2 py-0.5 text-[12px]" :class="statusClass(row.status)">{{ row.status_label }}</span>
          </td>
          <td class="px-3 py-2 text-slate-700">{{ row.item_count }}</td>
          <td class="px-3 py-2 text-slate-700">{{ row.counted_count }}</td>
          <td class="px-3 py-2" :class="row.diff_count ? 'text-red-500' : 'text-slate-500'">
            {{ row.diff_count }} 行 / {{ row.total_diff_qty }} 件
          </td>
          <td class="px-3 py-2 text-slate-500">{{ row.created_by_name || '—' }} {{ row.created_at || '' }}</td>
          <td class="px-3 py-2">
            <Button variant="outline" size="sm" @click="router.push(`/inventory-checks/${row.id}`)">查看</Button>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <div v-if="pagination.total_pages > 1" class="mt-4 flex items-center justify-end gap-2 text-[13px]">
      <Button variant="outline" size="sm" :disabled="pagination.page <= 1" data-testid="check-prev" @click="goPage(pagination.page - 1)">
        上一页
      </Button>
      <span class="text-slate-500">{{ pagination.page }} / {{ pagination.total_pages }}</span>
      <Button
        variant="outline"
        size="sm"
        :disabled="pagination.page >= pagination.total_pages"
        data-testid="check-next"
        @click="goPage(pagination.page + 1)"
      >
        下一页
      </Button>
    </div>

    <!-- 新建弹层 -->
    <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" data-testid="check-create-dialog">
      <div class="w-[520px] rounded-lg bg-white p-5 shadow-lg">
        <h3 class="text-base font-semibold text-slate-800">新建盘点单</h3>
        <p class="mt-1 text-[13px] text-slate-500">建单时会快照当前账面库存；生成后即可导出清单线下盘点。</p>

        <div class="mt-4 space-y-3 text-[13px]">
          <div>
            <label class="block text-slate-600">盘点名称</label>
            <input
              v-model="form.title"
              type="text"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              data-testid="check-title-input"
              placeholder="如：2026 年 9 月末全盘"
            />
          </div>

          <div>
            <label class="block text-slate-600">盘点范围</label>
            <select
              v-model="form.scope_type"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              data-testid="check-scope-select"
            >
              <option value="all">全部商品</option>
              <option value="category">按分类（含子分类）</option>
              <option value="brand">按品牌</option>
              <option value="keyword">按关键词</option>
              <option value="custom">自定义清单（上传 xlsx）</option>
            </select>
          </div>

          <div v-if="form.scope_type === 'category'">
            <label class="block text-slate-600">分类</label>
            <select
              v-model="form.scope_value"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              data-testid="check-scope-category"
            >
              <option value="">请选择分类</option>
              <template v-for="cat in categories" :key="cat.id">
                <option :value="String(cat.id)">{{ cat.name }}</option>
                <option v-for="child in cat.children ?? []" :key="child.id" :value="String(child.id)">
                  {{ cat.name }} / {{ child.name }}
                </option>
              </template>
            </select>
          </div>

          <div v-if="form.scope_type === 'brand'">
            <label class="block text-slate-600">品牌</label>
            <select
              v-model="form.scope_value"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              data-testid="check-scope-brand"
            >
              <option value="">请选择品牌</option>
              <option v-for="brand in brands" :key="brand.id" :value="String(brand.id)">{{ brand.name }}</option>
            </select>
          </div>

          <div v-if="form.scope_type === 'keyword'">
            <label class="block text-slate-600">关键词（SKU 编码或商品标题）</label>
            <input
              v-model="form.scope_value"
              type="text"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              data-testid="check-scope-keyword"
            />
          </div>

          <div v-if="form.scope_type === 'custom'">
            <label class="block text-slate-600">选品清单（xlsx，第一列为 SKU 编码）</label>
            <input
              type="file"
              accept=".xlsx,.xls"
              class="mt-1 w-full text-[13px]"
              data-testid="check-scope-file"
              @change="onFileChange"
            />
            <p v-if="file" class="mt-1 text-slate-500">已选择：{{ file.name }}</p>
          </div>

          <div>
            <label class="block text-slate-600">备注</label>
            <textarea
              v-model="form.remark"
              rows="2"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              data-testid="check-remark"
            />
          </div>
        </div>

        <p v-if="formError" class="mt-2 text-[13px] text-red-500" data-testid="check-create-error">{{ formError }}</p>

        <div class="mt-4 flex justify-end gap-2">
          <Button variant="outline" data-testid="check-create-cancel" @click="closeCreate">取消</Button>
          <Button
            class="bg-[#1677ff] hover:bg-[#4096ff]"
            :disabled="submitting"
            data-testid="check-create-submit"
            @click="submitCreate"
          >
            {{ submitting ? '创建中…' : '创建盘点单' }}
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>
