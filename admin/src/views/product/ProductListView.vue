<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Download, Plus, RotateCcw, Search, Upload } from 'lucide-vue-next'
import {
  batchProducts, getCategories, getProducts, updateProductStatus,
  type AdminProduct, type CategoryNode, type Pagination,
} from '@/api/product'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 商品列表（原型：筛选区 + 表格 + 批量操作 + 分页）
 */
const router = useRouter()

// 筛选
const keyword = ref('')
const categoryId = ref<number | ''>('')
const status = ref<number | ''>('')
const minPrice = ref('')
const maxPrice = ref('')
const sort = ref('newest')

const categories = ref<CategoryNode[]>([])
const list = ref<AdminProduct[]>([])
const pagination = ref<Pagination>({ page: 1, page_size: 10, total: 0, total_pages: 1 })
const loading = ref(false)
const selected = ref<Set<number>>(new Set())

const categoryOptions = ref<Array<{ id: number; label: string }>>([])

async function loadCategories() {
  const { data } = await getCategories()
  categories.value = data.data
  const opts: Array<{ id: number; label: string }> = []
  for (const root of data.data) {
    opts.push({ id: root.id, label: root.name })
    for (const c of root.children) opts.push({ id: c.id, label: `${root.name} / ${c.name}` })
  }
  categoryOptions.value = opts
}

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getProducts({
      keyword: keyword.value || undefined,
      category_id: categoryId.value || undefined,
      status: status.value === '' ? undefined : status.value,
      min_price: minPrice.value || undefined,
      max_price: maxPrice.value || undefined,
      sort: sort.value,
      page,
      page_size: 10,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
    selected.value = new Set()
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadCategories()
  load()
})

function reset() {
  keyword.value = ''
  categoryId.value = ''
  status.value = ''
  minPrice.value = ''
  maxPrice.value = ''
  sort.value = 'newest'
  load(1)
}

function toggleAll(e: Event) {
  const checked = (e.target as HTMLInputElement).checked
  selected.value = checked ? new Set(list.value.map((p) => p.id)) : new Set()
}

function toggleOne(id: number, e: Event) {
  const next = new Set(selected.value)
  if ((e.target as HTMLInputElement).checked) next.add(id)
  else next.delete(id)
  selected.value = next
}

/** 危险操作二次确认：pending 存放待执行动作 */
const confirmState = ref<{ title: string; message: string; run: () => Promise<void> } | null>(null)

function askConfirm(title: string, message: string, run: () => Promise<void>) {
  confirmState.value = { title, message, run }
}

async function onConfirm() {
  const c = confirmState.value
  confirmState.value = null
  await c?.run()
}

async function doToggleStatus(p: AdminProduct) {
  await updateProductStatus(p.id, p.status === 1 ? 0 : 1)
  await load(pagination.value.page)
}

function toggleStatus(p: AdminProduct) {
  // 仅「下架」需确认，上架直接执行
  if (p.status === 1) {
    askConfirm('下架商品', `确定将商品「${p.title}」下架？下架后前台将不可见。`, () => doToggleStatus(p))
  } else {
    doToggleStatus(p)
  }
}

async function doBatch(action: 'on_shelf' | 'off_shelf') {
  await batchProducts([...selected.value], action)
  selected.value = new Set()
  await load(pagination.value.page)
}

function batch(action: 'on_shelf' | 'off_shelf') {
  if (!selected.value.size) return
  if (action === 'off_shelf') {
    askConfirm('批量下架', `确定将选中的 ${selected.value.size} 件商品批量下架？下架后前台将不可见。`, () => doBatch(action))
  } else {
    doBatch(action)
  }
}

function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

watch(sort, () => load(1))

function fmtTime(dt?: string) {
  return dt ? dt.replace('T', ' ').slice(0, 19) : '-'
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <!-- 标题 + 操作 -->
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">商品列表</h2>
      <div class="flex items-center gap-2">
        <Button
          v-permission="'product.create'"
          class="bg-[#1677ff] hover:bg-[#4096ff]"
          @click="router.push('/products/new')"
        ><Plus class="mr-0.5 h-4 w-4" /> 新建商品</Button>
        <Button variant="outline" disabled><Upload class="mr-1 h-4 w-4" /> 导入</Button>
        <Button variant="outline" disabled><Download class="mr-1 h-4 w-4" /> 导出</Button>
        <Button
          v-if="selected.size" variant="outline"
          class="border-[#1677ff] text-[#1677ff] hover:bg-[#e6f4ff]"
          @click="batch('on_shelf')"
        >批量上架 ({{ selected.size }})</Button>
        <Button
          v-if="selected.size" variant="outline"
          class="border-red-300 text-red-500 hover:bg-red-50"
          @click="batch('off_shelf')"
        >批量下架</Button>
      </div>
    </div>

    <!-- 筛选区 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="keyword" type="text" placeholder="商品名称"
        class="w-40 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="load(1)"
      />
      <select v-model="categoryId" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="">分类</option>
        <option v-for="opt in categoryOptions" :key="opt.id" :value="opt.id">{{ opt.label }}</option>
      </select>
      <select v-model="status" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="">状态</option>
        <option :value="1">上架</option>
        <option :value="0">下架</option>
      </select>
      <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1.5">
        <span class="text-slate-400">¥</span>
        <input v-model="minPrice" type="number" placeholder="最低价" class="w-16 outline-none" />
        <span class="text-slate-300">–</span>
        <span class="text-slate-400">¥</span>
        <input v-model="maxPrice" type="number" placeholder="最高价" class="w-16 outline-none" />
      </div>
      <select v-model="sort" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="newest">最新创建</option>
        <option value="sales_desc">销量优先</option>
        <option value="price_asc">价格从低到高</option>
        <option value="price_desc">价格从高到低</option>
      </select>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" @click="load(1)"><Search class="mr-1 h-4 w-4" /> 搜索</Button>
      <Button variant="outline" @click="reset"><RotateCcw class="mr-1 h-4 w-4" /> 重置</Button>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-10 px-3 py-1.5"><input type="checkbox" :checked="list.length > 0 && selected.size === list.length" @change="toggleAll" /></th>
          <th class="px-3 py-1.5">商品信息</th>
          <th class="w-24 px-3 py-1.5">分类</th>
          <th class="w-24 px-3 py-1.5">价格</th>
          <th class="w-20 px-3 py-1.5">库存</th>
          <th class="w-20 px-3 py-1.5">销量</th>
          <th class="w-16 px-3 py-1.5">状态</th>
          <th class="w-40 px-3 py-1.5">创建时间</th>
          <th class="w-40 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="p in list" :key="p.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5"><input type="checkbox" :checked="selected.has(p.id)" @change="toggleOne(p.id, $event)" /></td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-3">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-100">
                <img v-if="p.main_image" :src="p.main_image" class="h-full w-full object-cover" alt="" />
                <span v-else class="text-lg text-slate-300">📦</span>
              </div>
              <div class="min-w-0">
                <div class="truncate font-medium text-black">
                  {{ p.title }}
                  <span
                    v-if="p.is_home_recommended"
                    class="ml-1 rounded bg-[#fff1f0] px-1.5 py-0.5 text-[10px] font-normal text-[#ff4d4f]"
                  >首页推荐</span>
                </div>
                <div class="truncate text-xs text-slate-400">{{ p.subtitle || '-' }}</div>
              </div>
            </div>
          </td>
          <td class="px-3 py-1.5">
            <span class="rounded bg-[#e6f4ff] px-2 py-0.5 text-xs text-[#1677ff]">{{ p.category?.name || '-' }}</span>
          </td>
          <td class="px-3 py-1.5 font-medium text-black">¥{{ p.price }}</td>
          <td class="px-3 py-1.5 text-black">{{ p.total_stock ?? '-' }}</td>
          <td class="px-3 py-1.5 text-black">{{ p.sales_count }}</td>
          <td class="px-3 py-1.5">
            <span
              class="rounded px-2 py-0.5 text-xs"
              :class="p.status === 1 ? 'bg-[#e8f7ec] text-[#2e9e57]' : 'bg-slate-100 text-slate-400'"
            >{{ p.status === 1 ? '上架' : '下架' }}</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ fmtTime(p.created_at) }}</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-1 text-[#1677ff]">
              <button class="hover:underline" @click="router.push(`/products/${p.id}`)">查看</button>
              <span class="text-slate-200">|</span>
              <button class="hover:underline" @click="router.push(`/products/${p.id}/edit`)">编辑</button>
              <span class="text-slate-200">|</span>
              <button
                class="hover:underline"
                :class="p.status === 1 ? 'text-orange-500' : 'text-emerald-600'"
                @click="toggleStatus(p)"
              >{{ p.status === 1 ? '下架' : '上架' }}</button>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="9"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="9" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <TablePagination :pagination="pagination" @change="goPage" />

    <!-- 下架/删除确认弹层 -->
    <ConfirmDialog
      :open="!!confirmState"
      :title="confirmState?.title"
      :message="confirmState?.message"
      confirm-text="确定下架"
      danger
      @confirm="onConfirm"
      @cancel="confirmState = null"
    />
  </div>
</template>
