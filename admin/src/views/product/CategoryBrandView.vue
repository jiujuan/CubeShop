<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Save } from 'lucide-vue-next'
import {
  getBrands, getCategoryBrands, saveCategoryBrands,
  type BrandRow,
} from '@/api/attribute'
import { getCategories, type CategoryNode } from '@/api/product'
import { Button } from '@/components/ui/button'

/**
 * 分类可选品牌（分类 ↔ 品牌 多对多，2026-09-19）
 *
 * 左：分类树；右：品牌勾选表（排序）→ 覆盖保存。
 * 品牌与分类是两个**正交**维度、互不隶属：同一品牌可挂在多个分类下，一个分类下也可有多个品牌。
 * 本页只配置「该分类下可选哪些品牌」，前台按分类浏览时据此收敛品牌范围；未配置的分类不做品牌限制。
 */
const categories = ref<CategoryNode[]>([])
const allBrands = ref<BrandRow[]>([])
const activeCategoryId = ref<number | null>(null)

/** 已勾选品牌：brand_id → sort（越大越靠前） */
const selected = ref<Record<number, number>>({})
const loading = ref(false)
const saving = ref(false)
const message = ref('')

/** 扁平化分类（一级 + 子级） */
const flatCategories = computed(() => {
  const out: Array<{ id: number; label: string; depth: number }> = []
  for (const root of categories.value) {
    out.push({ id: root.id, label: root.name, depth: 0 })
    for (const c of root.children) out.push({ id: c.id, label: c.name, depth: 1 })
  }
  return out
})

/** 当前分类名（吸附操作栏用） */
const activeCategoryLabel = computed(
  () => flatCategories.value.find((c) => c.id === activeCategoryId.value)?.label ?? '',
)

const selectedCount = computed(() => Object.keys(selected.value).length)

onMounted(async () => {
  const [cRes, bRes] = await Promise.all([getCategories(), getBrands({ page_size: 200 })])
  categories.value = cRes.data.data
  allBrands.value = bRes.data.data.list
  if (flatCategories.value.length) selectCategory(flatCategories.value[0].id)
})

async function selectCategory(id: number) {
  activeCategoryId.value = id
  message.value = ''
  loading.value = true
  try {
    const { data } = await getCategoryBrands(id)
    const map: Record<number, number> = {}
    for (const b of data.data.brands) map[b.brand_id] = b.sort
    selected.value = map
  } finally {
    loading.value = false
  }
}

function isChecked(id: number) {
  return id in selected.value
}

function toggle(id: number) {
  const next = { ...selected.value }
  if (id in next) delete next[id]
  else next[id] = 0
  selected.value = next
}

async function save() {
  if (activeCategoryId.value == null) return
  saving.value = true
  message.value = ''
  try {
    const total = Object.keys(selected.value).length
    const brands = Object.entries(selected.value).map(([bid, sort], idx) => ({
      brand_id: Number(bid),
      sort: sort || total - idx,
    }))
    await saveCategoryBrands(activeCategoryId.value, brands)
    message.value = '品牌配置保存成功，前台该分类下将展示这些品牌'
    await selectCategory(activeCategoryId.value)
  } catch (e) {
    message.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <h2 class="mb-4 text-lg font-semibold text-slate-800">分类可选品牌</h2>

    <div class="flex gap-4">
      <!-- 左：分类树 -->
      <div class="w-64 shrink-0 rounded-md border border-slate-100 p-2">
        <button
          v-for="cat in flatCategories" :key="cat.id"
          class="flex w-full items-center rounded-md px-3 py-2 text-left text-[13px]"
          :class="activeCategoryId === cat.id ? 'bg-[#e6f4ff] font-medium text-[#1677ff]' : 'text-slate-600 hover:bg-slate-50'"
          :style="{ paddingLeft: `${12 + cat.depth * 16}px` }"
          :data-testid="`cb-category-${cat.id}`"
          @click="selectCategory(cat.id)"
        >{{ cat.label }}</button>
      </div>

      <!-- 右：品牌勾选表 -->
      <div class="min-w-0 flex-1">
        <p class="mb-2 text-[13px] text-slate-500">
          勾选该分类下可选的品牌。品牌与分类是两个独立维度：同一品牌可挂在多个分类下，一个分类下也可有多个品牌。
          未配置的分类不做品牌限制。
        </p>

        <table class="w-full text-[13px]">
          <thead>
            <tr class="border-b border-slate-200 text-left text-slate-500">
              <th class="w-12 px-3 py-2">选择</th>
              <th class="px-3 py-2">品牌名</th>
              <th class="w-24 px-3 py-2">状态</th>
              <th class="w-24 px-3 py-2">排序</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="brand in allBrands" :key="brand.id" class="border-b border-slate-100 hover:bg-slate-50">
              <td class="px-3 py-2">
                <input type="checkbox" :checked="isChecked(brand.id)" :data-testid="`cb-brand-${brand.id}`" @change="toggle(brand.id)" />
              </td>
              <td class="px-3 py-2 font-medium text-slate-700">{{ brand.name }}</td>
              <td class="px-3 py-2">
                <span
                  class="rounded px-1.5 py-0.5 text-xs"
                  :class="brand.status === 1 ? 'bg-[#e6f4ff] text-[#1677ff]' : 'bg-slate-100 text-slate-500'"
                >{{ brand.status === 1 ? '启用' : '停用' }}</span>
              </td>
              <td class="px-3 py-2">
                <input
                  v-if="isChecked(brand.id)"
                  :value="selected[brand.id]"
                  type="number"
                  class="w-16 rounded-md border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]"
                  :data-testid="`cb-sort-${brand.id}`"
                  @input="selected[brand.id] = Number(($event.target as HTMLInputElement).value) || 0"
                />
                <span v-else class="text-slate-300">-</span>
              </td>
            </tr>
            <tr v-if="!allBrands.length && !loading"><td colspan="4" class="px-3 py-12 text-center text-slate-400">品牌库为空，请先在「品牌管理」中创建品牌</td></tr>
          </tbody>
        </table>

        <!-- 操作栏：吸附在内容区底部 -->
        <div class="sticky -bottom-4 z-10 mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 bg-white pb-3 pt-3 shadow-[0_-6px_16px_-12px_rgba(15,23,42,0.35)]">
          <Button v-permission="'product.update'" class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="saving || activeCategoryId == null" @click="save">
            <Save class="mr-1 h-4 w-4" /> {{ saving ? '保存中...' : '保存配置' }}
          </Button>
          <span v-if="message" class="text-[13px]" :class="message.includes('成功') ? 'text-[#2e9e57]' : 'text-red-500'">{{ message }}</span>
          <span class="ml-auto text-xs text-slate-400">
            <template v-if="activeCategoryLabel">当前分类：{{ activeCategoryLabel }} · </template>已选 {{ selectedCount }} 个品牌
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
