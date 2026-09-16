<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Save } from 'lucide-vue-next'
import {
  getAttributes, getCategoryTemplate, saveCategoryTemplate,
  type AttributeRow,
} from '@/api/attribute'
import { getCategories, type CategoryNode } from '@/api/product'
import { Button } from '@/components/ui/button'

/**
 * 分类属性模板（V1.1 E01 / T-011）
 * 左：分类树；右：属性勾选表（是否必填 / 排序）→ 覆盖保存
 * 保存后该分类下商品编辑时按此模板渲染（T-010 表单消费）
 */
const categories = ref<CategoryNode[]>([])
const allAttributes = ref<AttributeRow[]>([])
const activeCategoryId = ref<number | null>(null)

/** 已选中属性的配置：attribute_id → { is_required, sort } */
const selected = ref<Record<number, { is_required: boolean; sort: number }>>({})
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

/** 当前分类名（吸附操作栏用，滚到列表底部时也能确认在给哪个分类保存） */
const activeCategoryLabel = computed(
  () => flatCategories.value.find((c) => c.id === activeCategoryId.value)?.label ?? '',
)

/** 已勾选属性数 */
const selectedCount = computed(() => Object.keys(selected.value).length)

onMounted(async () => {
  const [cRes, aRes] = await Promise.all([getCategories(), getAttributes({ page_size: 200 })])
  categories.value = cRes.data.data
  allAttributes.value = aRes.data.data.list
  if (flatCategories.value.length) selectCategory(flatCategories.value[0].id)
})

async function selectCategory(id: number) {
  activeCategoryId.value = id
  message.value = ''
  loading.value = true
  try {
    const { data } = await getCategoryTemplate(id)
    const map: Record<number, { is_required: boolean; sort: number }> = {}
    for (const a of data.data.attributes) {
      map[a.attribute_id] = { is_required: a.is_required, sort: a.sort }
    }
    selected.value = map
  } finally {
    loading.value = false
  }
}

function isChecked(id: number) {
  return id in selected.value
}

/** 取已选配置（模板中已用 isChecked 保证存在） */
function cfgOf(id: number) {
  return selected.value[id]!
}

function toggle(id: number) {
  const next = { ...selected.value }
  if (id in next) delete next[id]
  else next[id] = { is_required: false, sort: 0 }
  selected.value = next
}

async function save() {
  if (activeCategoryId.value == null) return
  saving.value = true
  message.value = ''
  try {
    const attributes = Object.entries(selected.value).map(([aid, cfg], idx) => ({
      attribute_id: Number(aid),
      is_required: cfg.is_required,
      sort: cfg.sort || Object.keys(selected.value).length - idx,
    }))
    await saveCategoryTemplate(activeCategoryId.value, attributes)
    message.value = '模板保存成功，该分类下商品编辑时将按此模板渲染'
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
    <h2 class="mb-4 text-lg font-semibold text-slate-800">分类属性模板</h2>

    <div class="flex gap-4">
      <!-- 左：分类树 -->
      <div class="w-64 shrink-0 rounded-md border border-slate-100 p-2">
        <button
          v-for="cat in flatCategories" :key="cat.id"
          class="flex w-full items-center rounded-md px-3 py-2 text-left text-[13px]"
          :class="activeCategoryId === cat.id ? 'bg-[#e6f4ff] font-medium text-[#1677ff]' : 'text-slate-600 hover:bg-slate-50'"
          :style="{ paddingLeft: `${12 + cat.depth * 16}px` }"
          @click="selectCategory(cat.id)"
        >{{ cat.label }}</button>
      </div>

      <!-- 右：属性勾选表 -->
      <div class="min-w-0 flex-1">
        <p class="mb-2 text-[13px] text-slate-500">
          勾选该分类商品需要填写的属性。规格类属性用于生成 SKU，参数类属性用于商品详情展示。
        </p>

        <table class="w-full text-[13px]">
          <thead>
            <tr class="border-b border-slate-200 text-left text-slate-500">
              <th class="w-12 px-3 py-2">选择</th>
              <th class="px-3 py-2">属性名</th>
              <th class="w-20 px-3 py-2">类型</th>
              <th class="w-24 px-3 py-2">可筛选</th>
              <th class="w-24 px-3 py-2">必填</th>
              <th class="w-24 px-3 py-2">排序</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="attr in allAttributes" :key="attr.id" class="border-b border-slate-100 hover:bg-slate-50">
              <td class="px-3 py-2">
                <input type="checkbox" :checked="isChecked(attr.id)" :data-testid="`tmpl-attr-${attr.id}`" @change="toggle(attr.id)" />
              </td>
              <td class="px-3 py-2 font-medium text-slate-700">{{ attr.name }}</td>
              <td class="px-3 py-2">
                <span class="rounded px-1.5 py-0.5 text-xs" :class="attr.type === 'spec' ? 'bg-[#e6f4ff] text-[#1677ff]' : 'bg-slate-100 text-slate-500'">
                  {{ attr.type_label }}
                </span>
              </td>
              <td class="px-3 py-2 text-slate-500">{{ attr.is_filterable ? '是' : '否' }}</td>
              <td class="px-3 py-2">
                <input v-if="isChecked(attr.id)" v-model="cfgOf(attr.id).is_required" type="checkbox" />
                <span v-else class="text-slate-300">-</span>
              </td>
              <td class="px-3 py-2">
                <input
                  v-if="isChecked(attr.id)" v-model.number="cfgOf(attr.id).sort" type="number"
                  class="w-16 rounded-md border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]"
                />
                <span v-else class="text-slate-300">-</span>
              </td>
            </tr>
            <tr v-if="!allAttributes.length && !loading"><td colspan="6" class="px-3 py-12 text-center text-slate-400">属性库为空，请先在「属性库」中创建属性</td></tr>
          </tbody>
        </table>

        <!-- 操作栏：吸附在内容区底部，属性值再多也不用滚到底才能保存 -->
        <div class="sticky -bottom-4 z-10 mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 bg-white pb-3 pt-3 shadow-[0_-6px_16px_-12px_rgba(15,23,42,0.35)]">
          <Button v-permission="'product.update'" class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="saving || activeCategoryId == null" @click="save">
            <Save class="mr-1 h-4 w-4" /> {{ saving ? '保存中...' : '保存模板' }}
          </Button>
          <span v-if="message" class="text-[13px]" :class="message.includes('成功') ? 'text-[#2e9e57]' : 'text-red-500'">{{ message }}</span>
          <span class="ml-auto text-xs text-slate-400">
            <template v-if="activeCategoryLabel">当前分类：{{ activeCategoryLabel }} · </template>已选 {{ selectedCount }} 项
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
