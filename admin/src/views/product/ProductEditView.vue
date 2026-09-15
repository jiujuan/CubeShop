<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, ChevronDown, CloudUpload, Layers, Plus, Save, Trash2, Wand2 } from 'lucide-vue-next'
import {
  createProduct, getCategories, getProduct, updateProduct, updateProductStatus, uploadImage,
  type CategoryNode, type ProductPayload,
} from '@/api/product'
import {
  getAttributes, getBrands, getCategoryTemplate, previewSkuMatrix,
  type AttributeRow, type BrandRow, type CategoryTemplate,
} from '@/api/attribute'
import { Button } from '@/components/ui/button'
import RichTextEditor from '@/components/RichTextEditor.vue'

/**
 * 商品新建/编辑/查看（V1.1 E01 / T-010，五步式）
 * ① 基础信息（含品牌/重量/视频）→ ② 规格属性（勾选生成 SKU）→ ③ 参数属性
 * → ④ SKU 表（自动生成 + 批量填充 + 文本逃生舱）→ ⑤ 图片与详情
 * 兼容：分类无规格模板时回退为 V1.0 的手输规格文本表格。
 */
const route = useRoute()
const router = useRouter()

const productId = computed(() => (route.params.id ? Number(route.params.id) : null))
const mode = computed<'create' | 'edit' | 'view'>(() => {
  if (!productId.value) return 'create'
  return route.path.endsWith('/edit') ? 'edit' : 'view'
})
const pageTitle = computed(() => ({ create: '新建商品', edit: '编辑商品', view: '商品详情' }[mode.value]))

// ---------- 基础信息 ----------
const categories = ref<CategoryNode[]>([])
const brands = ref<BrandRow[]>([])
const allAttributes = ref<AttributeRow[]>([])
const template = ref<CategoryTemplate | null>(null)

const form = ref({
  title: '',
  subtitle: '',
  category_id: null as number | null,
  brand_id: null as number | null,
  weight: 0,
  video_url: '',
  status: 0,
  sort: 100,
  description: '',
})

// ---------- 图片 ----------
const mainImage = ref('')
const detailImages = ref<string[]>([])
const mainFileRef = ref<HTMLInputElement>()
const detailFileRef = ref<HTMLInputElement>()
const uploading = ref(false)

async function pickImage(file: File, target: 'main' | 'detail') {
  uploading.value = true
  try {
    const { data } = await uploadImage(file)
    if (target === 'main') mainImage.value = data.data.url
    else if (detailImages.value.length < 10) detailImages.value.push(data.data.url)
  } finally {
    uploading.value = false
  }
}
function onMainChange(e: Event) {
  const input = e.target as HTMLInputElement | null
  const file = input?.files?.[0]
  if (file) pickImage(file, 'main')
  if (input) input.value = ''
}
function onDetailChange(e: Event) {
  const input = e.target as HTMLInputElement | null
  const file = input?.files?.[0]
  if (file) pickImage(file, 'detail')
  if (input) input.value = ''
}

// ---------- 属性模板 ----------
/** 模板中的规格属性 / 参数属性 */
const specAttributes = computed(() => (template.value?.attributes ?? []).filter((a) => a.type === 'spec'))
const paramAttributes = computed(() => (template.value?.attributes ?? []).filter((a) => a.type === 'param'))

/** 属性 id → 属性定义（含属性值） */
const attributeMap = computed(() => {
  const map: Record<number, AttributeRow> = {}
  for (const a of allAttributes.value) map[a.id] = a
  return map
})

function attrValues(attributeId: number) {
  return attributeMap.value[attributeId]?.values ?? []
}

/** 规格勾选：attribute_id → 选中的属性值 id 列表 */
const specSelection = ref<Record<number, number[]>>({})

function isSpecValueSelected(attributeId: number, valueId: number) {
  return (specSelection.value[attributeId] ?? []).includes(valueId)
}

async function toggleSpecValue(attributeId: number, valueId: number) {
  const cur = specSelection.value[attributeId] ?? []
  const next = cur.includes(valueId) ? cur.filter((v) => v !== valueId) : [...cur, valueId]
  const map = { ...specSelection.value }
  if (next.length) map[attributeId] = next
  else delete map[attributeId]
  specSelection.value = map
  await refreshMatrix()
}

/** 参数属性值：attribute_id → 文本 */
const paramValues = ref<Record<number, string>>({})

// ---------- SKU 矩阵 ----------
interface SkuRow {
  signature: string
  specs: Record<string, string>
  sku_code: string
  price: number | string
  stock: number
  status: number
  isNew: boolean
}
const skuRows = ref<SkuRow[]>([])
const matrixMeta = ref<{ total: number; maxSkus: number; removed: number } | null>(null)
const matrixLoading = ref(false)
const matrixError = ref('')
const selectedRows = ref<Set<string>>(new Set())

/** 是否走矩阵链路（分类配置了规格属性且有勾选值） */
const useMatrix = computed(() => specAttributes.value.length > 0)

/** 规格列名（用于表头） */
const specColumns = computed(() => specAttributes.value.map((a) => a.name))

function buildSelection() {
  return Object.entries(specSelection.value)
    .filter(([, values]) => values.length)
    .map(([aid, values]) => ({ attribute_id: Number(aid), values }))
}

async function refreshMatrix() {
  matrixError.value = ''
  const selection = buildSelection()
  if (!selection.length) {
    skuRows.value = []
    matrixMeta.value = null
    return
  }
  matrixLoading.value = true
  try {
    const { data } = await previewSkuMatrix({ product_id: productId.value, specs_selection: selection })
    const preview = data.data
    matrixMeta.value = { total: preview.total, maxSkus: preview.max_skus, removed: preview.removed.length }
    const createdSet = new Set(preview.created.map((c) => c.signature))
    const old = new Map(skuRows.value.map((r) => [r.signature, r]))
    skuRows.value = [...preview.created, ...preview.kept].map((item) => {
      const prev = old.get(item.signature)
      return {
        signature: item.signature,
        specs: item.specs,
        sku_code: prev?.sku_code ?? '',
        price: prev?.price ?? '',
        stock: prev?.stock ?? 0,
        status: prev?.status ?? 1,
        isNew: createdSet.has(item.signature) && !prev,
      }
    })
  } catch (e) {
    matrixError.value = e instanceof Error ? e.message : '规格组合生成失败'
    skuRows.value = []
  } finally {
    matrixLoading.value = false
  }
}

// ---------- 批量填充 / 按维度设值 ----------
const batchPrice = ref<number | ''>('')
const batchStock = ref<number | ''>('')
const batchAttr = ref<number | ''>('')
const batchAttrValue = ref('')
const batchDelta = ref<number | ''>('')

function toggleRow(signature: string, checked: boolean) {
  const next = new Set(selectedRows.value)
  if (checked) next.add(signature)
  else next.delete(signature)
  selectedRows.value = next
}

function toggleAllRows(checked: boolean) {
  selectedRows.value = checked ? new Set(skuRows.value.map((r) => r.signature)) : new Set()
}

/** 批量填充：选中行统一设价格/库存 */
function applyBatchFill() {
  for (const row of skuRows.value) {
    if (!selectedRows.value.has(row.signature)) continue
    if (batchPrice.value !== '') row.price = Number(batchPrice.value)
    if (batchStock.value !== '') row.stock = Number(batchStock.value)
  }
  batchPrice.value = ''
  batchStock.value = ''
}

/** 按维度批量设值：所有匹配「属性:值」的行价格 +delta */
const batchAttributeName = computed(() => {
  const id = Number(batchAttr.value)
  return attributeMap.value[id]?.name ?? ''
})

function applyDimensionDelta() {
  if (!batchAttr.value || !batchAttrValue.value || batchDelta.value === '') return
  const name = batchAttributeName.value
  const delta = Number(batchDelta.value)
  for (const row of skuRows.value) {
    if (row.specs[name] === batchAttrValue.value) {
      row.price = (Number(row.price || 0) + delta).toFixed(2)
    }
  }
  batchDelta.value = ''
}

// ---------- 文本逃生舱（批量粘贴规格文本） ----------
const pasteOpen = ref(false)
const pasteText = ref('')
const pasteMsg = ref('')

/** 行格式：规格组合|价格|库存|编码（规格组合用 / 分隔，顺序与规格列一致） */
function applyPaste() {
  pasteMsg.value = ''
  const lines = pasteText.value.split('\n').map((l) => l.trim()).filter(Boolean)
  if (!lines.length) return
  let hit = 0
  for (const line of lines) {
    const [specPart, price, stock, code] = line.split('|').map((s) => s?.trim() ?? '')
    if (!specPart) continue
    const values = specPart.split('/').map((s) => s.trim())
    const target = skuRows.value.find((row) =>
      specColumns.value.every((col, i) => (row.specs[col] ?? '') === (values[i] ?? '')),
    )
    if (!target) continue
    if (price) target.price = Number(price)
    if (stock) target.stock = Number(stock)
    if (code) target.sku_code = code
    hit++
  }
  pasteMsg.value = `已匹配并填充 ${hit} 行`
  pasteText.value = ''
}

// ---------- 旧结构（无模板时的回退） ----------
interface LegacySkuRow {
  specsText: string
  sku_code: string
  price: number | string
  stock: number
  status: number
}
const legacySkus = ref<LegacySkuRow[]>([])

function addLegacySku() {
  legacySkus.value.push({ specsText: '', sku_code: '', price: '', stock: 0, status: 1 })
}
function removeLegacySku(i: number) {
  legacySkus.value.splice(i, 1)
}
function parseSpecs(text: string): Record<string, string> {
  const parts = text.split('/').map((s) => s.trim()).filter(Boolean)
  const keys = ['颜色', '尺码', '规格', '容量', '轴体', '版本']
  const specs: Record<string, string> = {}
  parts.forEach((value, i) => {
    specs[keys[i] ?? `规格${i + 1}`] = value
  })
  return specs
}

// ---------- 加载 ----------
onMounted(async () => {
  const [cRes, bRes, aRes] = await Promise.all([
    getCategories(),
    getBrands({ page_size: 100 }),
    getAttributes({ page_size: 200 }),
  ])
  categories.value = cRes.data.data
  brands.value = bRes.data.data.list
  allAttributes.value = aRes.data.data.list

  if (productId.value) {
    const res = await getProduct(productId.value)
    const p = res.data.data
    form.value = {
      title: p.title,
      subtitle: p.subtitle ?? '',
      category_id: p.category_id ?? null,
      brand_id: p.brand_id ?? null,
      weight: p.weight ?? 0,
      video_url: p.video_url ?? '',
      status: p.status,
      sort: p.sort ?? 100,
      description: p.description ?? '',
    }
    mainImage.value = p.main_image ?? ''
    detailImages.value = p.images ?? []

    // 参数属性回显
    for (const av of p.attribute_values ?? []) {
      paramValues.value[av.attribute_id] = av.value
    }

    await loadTemplate(p.category_id ?? null)

    // 规格勾选回显（后端给出 {attribute_id, name, value_names}）
    const sel: Record<number, number[]> = {}
    for (const dim of (p.specs_selection ?? []) as Array<{ attribute_id: number | null; name: string; value_names: string[] }>) {
      const aid = dim.attribute_id ?? allAttributes.value.find((a) => a.name === dim.name)?.id
      if (!aid) continue
      const ids = attrValues(aid)
        .filter((v) => dim.value_names.includes(v.value))
        .map((v) => v.id)
      if (ids.length) sel[aid] = ids
    }
    specSelection.value = sel

    if (Object.keys(sel).length) {
      // 先填充已有 SKU 行（保留价格库存），再按勾选刷新矩阵
      skuRows.value = (p.skus ?? []).map((s) => ({
        signature: s.signature ?? '',
        specs: s.specs ?? {},
        sku_code: s.sku_code ?? '',
        price: s.price,
        stock: s.stock,
        status: s.status,
        isNew: false,
      }))
      await refreshMatrix()
    } else if ((p.skus ?? []).length) {
      // 老商品（无规格勾选）：回退旧表格
      legacySkus.value = (p.skus ?? []).map((s) => ({
        specsText: Object.values(s.specs ?? {}).join('/'),
        sku_code: s.sku_code ?? '',
        price: s.price,
        stock: s.stock,
        status: s.status,
      }))
    }
  }
})

async function loadTemplate(categoryId: number | null) {
  if (!categoryId) {
    template.value = null
    return
  }
  const { data } = await getCategoryTemplate(categoryId)
  template.value = data.data
}

/** 切换分类：重载模板并清空规格选择 */
async function onCategoryChange() {
  specSelection.value = {}
  paramValues.value = {}
  skuRows.value = []
  matrixMeta.value = null
  await loadTemplate(form.value.category_id)
}

// ---------- 保存 ----------
const saving = ref(false)
const errorMsg = ref('')
const summaryOpen = ref(false)
const summary = ref({ created: 0, kept: 0, invalid: 0 })

function buildPayload(): ProductPayload {
  const attributeValues = Object.entries(paramValues.value)
    .filter(([, v]) => v && v.trim() !== '')
    .map(([aid, value]) => ({ attribute_id: Number(aid), value: value.trim() }))

  const payload: ProductPayload = {
    category_id: form.value.category_id,
    title: form.value.title.trim(),
    subtitle: form.value.subtitle,
    main_image: mainImage.value || null,
    description: form.value.description,
    status: form.value.status,
    sort: form.value.sort,
    brand_id: form.value.brand_id,
    weight: Number(form.value.weight) || 0,
    video_url: form.value.video_url || null,
    attribute_values: attributeValues,
    images: detailImages.value,
    skus: [],
  }

  if (useMatrix.value && buildSelection().length) {
    payload.specs_selection = buildSelection()
    payload.skus = skuRows.value.map((r) => ({
      signature: r.signature,
      specs: r.specs,
      sku_code: r.sku_code || null,
      price: Number(r.price),
      stock: Number(r.stock),
      status: r.status,
    }))
  } else {
    payload.skus = legacySkus.value.map((s) => ({
      sku_code: s.sku_code || null,
      specs: parseSpecs(s.specsText),
      price: Number(s.price),
      stock: Number(s.stock),
      status: s.status,
    }))
  }

  return payload
}

function validate(): string {
  if (!form.value.title.trim()) return '请输入商品标题'
  if (!form.value.category_id) return '请选择商品分类'

  if (useMatrix.value) {
    if (!buildSelection().length) return '请至少勾选一个规格属性及其值'
    if (!skuRows.value.length) return '规格组合生成失败，请调整规格勾选'
    if (matrixMeta.value && matrixMeta.value.total > matrixMeta.value.maxSkus) {
      return `将生成 ${matrixMeta.value.total} 个 SKU，超过上限 ${matrixMeta.value.maxSkus}，请减少规格值`
    }
    for (const [i, r] of skuRows.value.entries()) {
      if (!r.price || Number(r.price) <= 0) return `第 ${i + 1} 行 SKU：请填写有效售价`
      if (Number(r.stock) < 0) return `第 ${i + 1} 行 SKU：库存不能为负`
    }
    const codes = skuRows.value.map((r) => r.sku_code).filter(Boolean)
    if (new Set(codes).size !== codes.length) return 'SKU 编码存在重复，请修改'
  } else {
    if (!legacySkus.value.length) return '请至少添加一个 SKU 规格'
    for (const [i, s] of legacySkus.value.entries()) {
      if (!s.specsText.trim()) return `第 ${i + 1} 行 SKU：请填写规格组合`
      if (!s.price || Number(s.price) <= 0) return `第 ${i + 1} 行 SKU：请填写有效售价`
      if (Number(s.stock) < 0) return `第 ${i + 1} 行 SKU：库存不能为负`
    }
  }
  return ''
}

/** 打开保存前摘要确认（新增 / 保留 / 失效） */
function askSave() {
  errorMsg.value = validate()
  if (errorMsg.value) return
  const created = skuRows.value.filter((r) => r.isNew).length
  const kept = skuRows.value.length - created
  const invalid = matrixMeta.value?.removed ?? 0
  summary.value = { created, kept, invalid }
  summaryOpen.value = true
}

async function doSave() {
  summaryOpen.value = false
  saving.value = true
  errorMsg.value = ''
  try {
    const payload = buildPayload()
    if (mode.value === 'create') {
      const { data } = await createProduct(payload)
      if (form.value.status === 1) await updateProductStatus(data.data.id, 1).catch(() => null)
      router.replace('/products')
    } else {
      await updateProduct(productId.value!, payload)
      await updateProductStatus(productId.value!, form.value.status).catch(() => null)
      router.replace('/products')
    }
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

function cancel() {
  router.push('/products')
}
</script>

<template>
  <div class="rounded-lg bg-white p-6 shadow-sm">
    <div class="mb-5 flex items-center justify-between border-b-2 border-[#1677ff] pb-2">
      <h2 class="text-lg font-semibold text-slate-800">{{ pageTitle }}</h2>
      <button
        type="button"
        class="flex items-center gap-1 rounded-md px-2.5 py-1.5 text-[13px] text-slate-600 transition-colors hover:bg-slate-100 hover:text-[#1677ff]"
        @click="router.back()"
      >
        <ArrowLeft class="h-4 w-4" /> 返回
      </button>
    </div>

    <div class="mx-auto max-w-5xl space-y-8 text-[13px]" :class="mode === 'view' ? 'pointer-events-none opacity-95' : ''">
      <!-- ① 基本信息 -->
      <section>
        <h3 class="mb-3 font-semibold text-slate-700"><span class="mr-1.5 rounded bg-[#1677ff] px-1.5 py-0.5 text-xs text-white">1</span>基本信息</h3>
        <div class="space-y-3">
          <div class="flex items-start">
            <label class="w-28 shrink-0 pt-2 text-slate-600">商品标题 <span class="text-red-500">*</span></label>
            <div class="flex-1">
              <input v-model="form.title" type="text" maxlength="60" placeholder="请输入商品标题" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
            </div>
          </div>
          <div class="flex items-start">
            <label class="w-28 shrink-0 pt-2 text-slate-600">副标题</label>
            <div class="flex-1">
              <input v-model="form.subtitle" type="text" placeholder="请输入副标题" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
            </div>
          </div>
          <div class="flex items-start">
            <label class="w-28 shrink-0 pt-2 text-slate-600">商品分类 <span class="text-red-500">*</span></label>
            <div class="relative flex-1">
              <select v-model="form.category_id" data-testid="category-select" class="w-full appearance-none rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" @change="onCategoryChange">
                <option :value="null" disabled>请选择分类</option>
                <optgroup v-for="root in categories" :key="root.id" :label="root.name">
                  <option :value="root.id">{{ root.name }}</option>
                  <option v-for="c in root.children" :key="c.id" :value="c.id">{{ root.name }} / {{ c.name }}</option>
                </optgroup>
              </select>
              <ChevronDown class="pointer-events-none absolute right-2 top-2.5 h-4 w-4 text-slate-400" />
            </div>
          </div>
          <div class="flex items-start">
            <label class="w-28 shrink-0 pt-2 text-slate-600">品牌</label>
            <div class="relative flex-1">
              <select v-model="form.brand_id" class="w-full appearance-none rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]">
                <option :value="null">无品牌</option>
                <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
              </select>
              <ChevronDown class="pointer-events-none absolute right-2 top-2.5 h-4 w-4 text-slate-400" />
            </div>
          </div>
          <div class="flex items-start">
            <label class="w-28 shrink-0 pt-2 text-slate-600">重量（克）</label>
            <div class="flex-1">
              <input v-model.number="form.weight" type="number" min="0" class="w-40 rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
              <span class="ml-2 text-xs text-slate-400">用于后续按重量计算运费</span>
            </div>
          </div>
          <div class="flex items-start">
            <label class="w-28 shrink-0 pt-2 text-slate-600">商品视频</label>
            <div class="flex-1">
              <input v-model="form.video_url" type="text" placeholder="视频 URL（选填）" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
            </div>
          </div>
          <div class="flex items-center">
            <label class="w-28 shrink-0 text-slate-600">状态 <span class="text-red-500">*</span></label>
            <div class="flex gap-6">
              <label class="flex items-center gap-1.5"><input v-model.number="form.status" type="radio" :value="1" /> 上架</label>
              <label class="flex items-center gap-1.5"><input v-model.number="form.status" type="radio" :value="0" /> 下架</label>
            </div>
          </div>
          <div class="flex items-start">
            <label class="w-28 shrink-0 pt-2 text-slate-600">排序</label>
            <input v-model.number="form.sort" type="number" class="w-28 rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
          </div>
        </div>
      </section>

      <!-- ② 规格属性 -->
      <section v-if="useMatrix">
        <h3 class="mb-3 font-semibold text-slate-700"><span class="mr-1.5 rounded bg-[#1677ff] px-1.5 py-0.5 text-xs text-white">2</span>规格属性（勾选后自动生成 SKU）</h3>
        <div v-for="attr in specAttributes" :key="attr.attribute_id" class="mb-3" :data-testid="`spec-attr-${attr.attribute_id}`">
          <div class="mb-1.5 text-slate-600">{{ attr.name }}</div>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="v in attrValues(attr.attribute_id)" :key="v.id"
              class="rounded-lg border px-3 py-1 transition-colors"
              :class="isSpecValueSelected(attr.attribute_id, v.id)
                ? 'border-[#1677ff] bg-[#e6f4ff] text-[#1677ff]'
                : 'border-slate-200 text-slate-600 hover:border-[#1677ff]'"
              :data-testid="`spec-value-${attr.attribute_id}-${v.id}`"
              @click="toggleSpecValue(attr.attribute_id, v.id)"
            >{{ v.value }}</button>
          </div>
        </div>
        <p v-if="!specAttributes.length" class="text-slate-400">该分类未配置规格属性，请在「分类属性模板」中配置。</p>
      </section>

      <!-- ③ 参数属性 -->
      <section v-if="paramAttributes.length">
        <h3 class="mb-3 font-semibold text-slate-700"><span class="mr-1.5 rounded bg-[#1677ff] px-1.5 py-0.5 text-xs text-white">{{ useMatrix ? 3 : 2 }}</span>参数属性</h3>
        <div v-for="attr in paramAttributes" :key="attr.attribute_id" class="mb-2 flex items-center" :data-testid="`param-attr-${attr.attribute_id}`">
          <label class="w-28 shrink-0 text-slate-600">
            {{ attr.name }}<span v-if="attr.is_required" class="text-red-500"> *</span>
          </label>
          <input
            v-model="paramValues[attr.attribute_id]" type="text" placeholder="请输入属性值"
            class="w-72 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
          />
        </div>
      </section>

      <!-- ④ SKU 表 -->
      <section>
        <h3 class="mb-3 flex items-center gap-2 font-semibold text-slate-700">
          <span class="rounded bg-[#1677ff] px-1.5 py-0.5 text-xs text-white">{{ useMatrix ? (paramAttributes.length ? 4 : 3) : 2 }}</span>
          SKU 规格与价格库存
          <span v-if="matrixMeta" class="ml-2 text-xs font-normal text-slate-400" data-testid="matrix-count">
            将生成 {{ matrixMeta.total }} 个 SKU（上限 {{ matrixMeta.maxSkus }}）
          </span>
          <button v-if="useMatrix && skuRows.length" class="ml-auto flex items-center gap-1 text-xs text-slate-400 hover:text-[#1677ff]" @click="pasteOpen = !pasteOpen">
            <Wand2 class="h-3.5 w-3.5" /> 批量粘贴规格文本
          </button>
        </h3>

        <p v-if="matrixError" class="mb-2 rounded-md bg-red-50 px-3 py-2 text-red-500">{{ matrixError }}</p>

        <!-- 矩阵链路 -->
        <template v-if="useMatrix">
          <div v-if="!skuRows.length" class="rounded-md border border-dashed border-slate-200 py-10 text-center text-slate-400">
            <Layers class="mx-auto mb-2 h-8 w-8 text-slate-300" />
            请先在上方勾选规格属性值，系统将自动生成 SKU 组合
          </div>

          <template v-else>
            <!-- 批量工具条 -->
            <div class="mb-2 flex flex-wrap items-center gap-2 rounded-md bg-slate-50 px-3 py-2 text-xs">
              <span class="text-slate-500">批量填充：</span>
              <input v-model="batchPrice" type="number" step="0.01" placeholder="价格" class="w-20 rounded border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]" />
              <input v-model="batchStock" type="number" placeholder="库存" class="w-20 rounded border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]" />
              <button class="rounded bg-[#e6f4ff] px-2 py-1 text-[#1677ff]" @click="applyBatchFill">应用到选中行</button>
              <span class="mx-2 text-slate-300">|</span>
              <span class="text-slate-500">按维度设值：</span>
              <select v-model="batchAttr" class="rounded border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]">
                <option value="">规格维度</option>
                <option v-for="attr in specAttributes" :key="attr.attribute_id" :value="attr.attribute_id">{{ attr.name }}</option>
              </select>
              <select v-model="batchAttrValue" class="rounded border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]">
                <option value="">值</option>
                <option v-for="v in attrValues(Number(batchAttr) || 0)" :key="v.id" :value="v.value">{{ v.value }}</option>
              </select>
              <input v-model="batchDelta" type="number" step="0.01" placeholder="±元" class="w-20 rounded border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]" />
              <button class="rounded bg-[#e6f4ff] px-2 py-1 text-[#1677ff]" @click="applyDimensionDelta">应用</button>
            </div>

            <table class="w-full text-[13px]">
              <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                  <th class="w-10 px-2 py-2">
                    <input type="checkbox" :checked="selectedRows.size === skuRows.length && skuRows.length > 0" @change="toggleAllRows(($event.target as HTMLInputElement).checked)" />
                  </th>
                  <th v-for="col in specColumns" :key="col" class="px-3 py-2">{{ col }}</th>
                  <th class="w-32 px-3 py-2">SKU编码</th>
                  <th class="w-28 px-3 py-2">售价（元）</th>
                  <th class="w-24 px-3 py-2">库存</th>
                  <th class="w-20 px-3 py-2">状态</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(row, i) in skuRows" :key="row.signature"
                  class="border-b border-slate-100"
                  :class="row.isNew ? 'bg-amber-50/60' : ''"
                  :data-testid="`sku-row-${i}`"
                >
                  <td class="px-2 py-2">
                    <input type="checkbox" :checked="selectedRows.has(row.signature)" @change="toggleRow(row.signature, ($event.target as HTMLInputElement).checked)" />
                  </td>
                  <td v-for="col in specColumns" :key="col" class="px-3 py-2 text-slate-700">{{ row.specs[col] }}</td>
                  <td class="px-3 py-2">
                    <input v-model="row.sku_code" type="text" placeholder="留空自动生成" class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="row.price" type="number" step="0.01" min="0" class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.number="row.stock" type="number" min="0" class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" />
                  </td>
                  <td class="px-3 py-2">
                    <span class="rounded px-2 py-0.5 text-xs" :class="row.status === 1 ? 'bg-[#e8f7ec] text-[#2e9e57]' : 'bg-slate-100 text-slate-400'">
                      {{ row.status === 1 ? '启用' : '禁用' }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>

            <!-- 粘贴逃生舱 -->
            <div v-if="pasteOpen" class="mt-3 rounded-md border border-slate-200 p-3">
              <p class="mb-1.5 text-xs text-slate-500">每行格式：规格组合|价格|库存|编码（规格按上表列顺序用 / 分隔，如「黑色/M|99.00|10|SKU-1」）</p>
              <textarea v-model="pasteText" rows="4" class="w-full rounded-md border border-slate-300 p-2 font-mono text-xs outline-none focus:border-[#1677ff]" />
              <div class="mt-2 flex items-center gap-3">
                <button class="rounded bg-[#e6f4ff] px-3 py-1 text-[#1677ff]" @click="applyPaste">解析并填充</button>
                <span class="text-xs text-[#2e9e57]">{{ pasteMsg }}</span>
              </div>
            </div>
          </template>
        </template>

        <!-- 旧结构回退 -->
        <template v-else>
          <p class="mb-2 text-xs text-slate-400">该分类未配置规格属性模板，使用传统方式填写规格组合（如「黑色/M」）。</p>
          <table class="w-full text-[13px]">
            <thead>
              <tr class="border-b border-slate-200 text-left text-slate-500">
                <th class="px-3 py-2">规格组合</th>
                <th class="w-40 px-3 py-2">SKU编码</th>
                <th class="w-32 px-3 py-2">售价（元）</th>
                <th class="w-28 px-3 py-2">库存</th>
                <th class="w-16 px-3 py-2">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(sku, i) in legacySkus" :key="i" class="border-b border-slate-100">
                <td class="px-3 py-2"><input v-model="sku.specsText" type="text" placeholder="如：黑色/M" class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" /></td>
                <td class="px-3 py-2"><input v-model="sku.sku_code" type="text" placeholder="留空自动生成" class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" /></td>
                <td class="px-3 py-2"><input v-model="sku.price" type="number" step="0.01" min="0" class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" /></td>
                <td class="px-3 py-2"><input v-model.number="sku.stock" type="number" min="0" class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" /></td>
                <td class="px-3 py-2"><button class="text-red-400 hover:text-red-500" @click="removeLegacySku(i)"><Trash2 class="h-4 w-4" /></button></td>
              </tr>
            </tbody>
          </table>
          <button class="mt-3 inline-flex items-center gap-1 rounded-md bg-[#e6f4ff] px-3 py-1.5 text-[#1677ff] hover:bg-[#bae0ff]" @click="addLegacySku">
            <Plus class="h-4 w-4" /> 添加规格
          </button>
        </template>
      </section>

      <!-- ⑤ 商品图片与详情 -->
      <section class="flex items-start">
        <h3 class="w-28 shrink-0 pt-1 font-semibold text-slate-700"><span class="mr-1.5 rounded bg-[#1677ff] px-1.5 py-0.5 text-xs text-white">{{ useMatrix ? 5 : 3 }}</span>图片与详情</h3>
        <div class="flex flex-1 flex-col gap-6">
          <div class="flex flex-wrap gap-10">
            <div>
              <p class="mb-2 text-slate-600">主图</p>
              <button class="flex h-28 w-44 flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-slate-300 text-slate-400 transition-colors hover:border-[#1677ff] hover:text-[#1677ff]" @click="mainFileRef?.click()">
                <img v-if="mainImage" :src="mainImage" class="h-full w-full rounded-lg object-cover" alt="" />
                <template v-else>
                  <CloudUpload class="h-6 w-6" />
                  <span class="text-[#1677ff]">上传主图</span>
                </template>
              </button>
              <input ref="mainFileRef" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onMainChange" />
            </div>
            <div>
              <p class="mb-2 text-slate-600">详情图</p>
              <div class="flex flex-wrap items-center gap-2">
                <div v-for="(img, i) in detailImages" :key="img" class="group relative h-20 w-20 overflow-hidden rounded-lg border border-slate-200">
                  <img :src="img" class="h-full w-full object-cover" alt="" />
                  <button class="absolute inset-0 hidden items-center justify-center bg-black/40 text-white group-hover:flex" @click="detailImages.splice(i, 1)"><Trash2 class="h-4 w-4" /></button>
                </div>
                <button v-if="detailImages.length < 10" class="flex h-20 w-20 items-center justify-center rounded-lg border-2 border-dashed border-slate-300 text-slate-400 hover:border-[#1677ff] hover:text-[#1677ff]" @click="detailFileRef?.click()"><Plus class="h-5 w-5" /></button>
              </div>
              <input ref="detailFileRef" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onDetailChange" />
            </div>
          </div>
          <RichTextEditor v-model="form.description" placeholder="请输入商品详情..." />
        </div>
      </section>

      <p v-if="errorMsg" class="rounded-md bg-red-50 px-3 py-2 text-red-500">{{ errorMsg }}</p>

      <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
        <Button class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="saving" @click="askSave">
          <Save class="mr-1 h-4 w-4" /> {{ saving ? '保存中...' : '保存' }}
        </Button>
        <Button variant="outline" @click="cancel">取消</Button>
      </div>
    </div>

    <!-- 保存摘要确认 -->
    <div v-if="summaryOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-lg bg-white p-5 shadow-lg">
        <h3 class="mb-3 text-base font-semibold text-slate-800">确认保存</h3>
        <p class="text-[13px] leading-6 text-slate-600">
          本次将 <b class="text-amber-600">新增 {{ summary.created }}</b> 个 SKU，
          <b class="text-[#1677ff]">保留 {{ summary.kept }}</b> 个，
          <b class="text-red-500">失效 {{ summary.invalid }}</b> 个（有订单引用将禁用、否则删除）。
        </p>
        <div class="mt-5 flex justify-end gap-2">
          <Button variant="outline" @click="summaryOpen = false">取消</Button>
          <Button class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="saving" @click="doSave">确认保存</Button>
        </div>
      </div>
    </div>
  </div>
</template>
