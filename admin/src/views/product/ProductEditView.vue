<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, ChevronDown, CloudUpload, Plus, Save, Trash2 } from 'lucide-vue-next'
import {
  createProduct, getCategories, getProduct, updateProduct, updateProductStatus, uploadImage,
  type CategoryNode, type ProductPayload,
} from '@/api/product'
import { Button } from '@/components/ui/button'
import RichTextEditor from '@/components/RichTextEditor.vue'

/**
 * 商品新建/编辑/查看（原型四段式：基本信息 → 商品图片 → SKU → 商品详情）
 * 路由：/products/new（新建）| /products/:id/edit（编辑）| /products/:id（查看）
 */
const route = useRoute()
const router = useRouter()

const productId = computed(() => {
  const id = route.params.id
  return id ? Number(id) : null
})
const mode = computed<'create' | 'edit' | 'view'>(() => {
  if (!productId.value) return 'create'
  return route.path.endsWith('/edit') ? 'edit' : 'view'
})
const pageTitle = computed(() => ({ create: '新建商品', edit: '编辑商品', view: '商品详情' }[mode.value]))

// ---------- 基本信息 ----------
const categories = ref<CategoryNode[]>([])
const form = ref({
  title: '',
  subtitle: '',
  category_id: null as number | null,
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

// ---------- SKU ----------
interface SkuRow {
  specsText: string
  sku_code: string
  price: number | string
  stock: number
  status: number
}
const skus = ref<SkuRow[]>([])

function addSku() {
  skus.value.push({ specsText: '', sku_code: '', price: '', stock: 0, status: 1 })
}

function removeSku(index: number) {
  skus.value.splice(index, 1)
}

/** "黑色/M" → { 颜色: 黑色, 尺码: M } */
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
  const { data } = await getCategories()
  categories.value = data.data

  if (productId.value) {
    const res = await getProduct(productId.value)
    const p = res.data.data
    form.value = {
      title: p.title,
      subtitle: p.subtitle ?? '',
      category_id: p.category_id ?? null,
      status: p.status,
      sort: 100,
      description: p.description ?? '',
    }
    mainImage.value = p.main_image ?? ''
    detailImages.value = p.images ?? []
    skus.value = (p.skus ?? []).map((s) => ({
      specsText: Object.values(s.specs ?? {}).join('/'),
      sku_code: s.sku_code ?? '',
      price: s.price,
      stock: s.stock,
      status: s.status,
    }))
  }
})

// ---------- 保存 ----------
const saving = ref(false)
const errorMsg = ref('')

function buildPayload(): ProductPayload {
  return {
    category_id: form.value.category_id,
    title: form.value.title.trim(),
    subtitle: form.value.subtitle,
    main_image: mainImage.value || null,
    description: form.value.description,
    status: 0, // 默认先存草稿；「保存并上架」会置 1
    sort: form.value.sort,
    skus: skus.value.map((s) => ({
      sku_code: s.sku_code || null,
      specs: parseSpecs(s.specsText),
      price: Number(s.price),
      stock: Number(s.stock),
      status: s.status,
    })),
    images: detailImages.value,
  }
}

function validate(): string {
  if (!form.value.title.trim()) return '请输入商品标题'
  if (!form.value.category_id) return '请选择商品分类'
  if (!skus.value.length) return '请至少添加一个 SKU 规格'
  for (const [i, s] of skus.value.entries()) {
    if (!s.specsText.trim()) return `第 ${i + 1} 行 SKU：请填写规格组合`
    if (!s.price || Number(s.price) <= 0) return `第 ${i + 1} 行 SKU：请填写有效售价`
    if (Number(s.stock) < 0) return `第 ${i + 1} 行 SKU：库存不能为负`
  }
  return ''
}

async function save(andPublish: boolean) {
  errorMsg.value = validate()
  if (errorMsg.value) return

  saving.value = true
  try {
    const payload = buildPayload()
    if (andPublish) payload.status = 1

    if (mode.value === 'create') {
      const { data } = await createProduct(payload)
      // 新建后直接上架
      if (andPublish) await updateProductStatus(data.data.id, 1).catch(() => null)
      router.replace('/products')
    } else {
      await updateProduct(productId.value!, payload)
      if (andPublish) await updateProductStatus(productId.value!, 1).catch(() => null)
      else if (form.value.status === 0) await updateProductStatus(productId.value!, 0).catch(() => null)
      router.replace('/products')
    }
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

    <div class="mx-auto max-w-4xl space-y-8 text-[13px]" :class="mode === 'view' ? 'pointer-events-none opacity-95' : ''">
      <!-- 1 基本信息 -->
      <section>
        <h3 class="mb-3 font-semibold text-slate-700"><span class="mr-1.5 rounded bg-[#1677ff] px-1.5 py-0.5 text-xs text-white">1</span>基本信息</h3>
        <div class="space-y-3">
          <div class="flex items-start">
            <label class="w-28 shrink-0 pt-2 text-slate-600">商品标题 <span class="text-red-500">*</span></label>
            <div class="flex-1">
              <input
                v-model="form.title" type="text" maxlength="60" placeholder="请输入商品标题"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
              />
              <p class="mt-1 text-xs text-slate-400">建议控制在 60 字以内</p>
            </div>
          </div>
          <div class="flex items-start">
            <label class="w-28 shrink-0 pt-2 text-slate-600">副标题</label>
            <div class="flex-1">
              <input
                v-model="form.subtitle" type="text" placeholder="请输入副标题"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
              />
              <p class="mt-1 text-xs text-slate-400">选填，适用于 SEO 和列表页描述</p>
            </div>
          </div>
          <div class="flex items-start">
            <label class="w-28 shrink-0 pt-2 text-slate-600">商品分类 <span class="text-red-500">*</span></label>
            <div class="relative flex-1">
              <select
                v-model="form.category_id"
                class="w-full appearance-none rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
              >
                <option :value="null" disabled>请选择分类</option>
                <optgroup v-for="root in categories" :key="root.id" :label="root.name">
                  <option :value="root.id">{{ root.name }}</option>
                  <option v-for="c in root.children" :key="c.id" :value="c.id">{{ root.name }} / {{ c.name }}</option>
                </optgroup>
              </select>
              <ChevronDown class="pointer-events-none absolute right-2 top-2.5 h-4 w-4 text-slate-400" />
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
            <div class="flex-1">
              <input
                v-model.number="form.sort" type="number"
                class="w-28 rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
              />
              <p class="mt-1 text-xs text-slate-400">数值越大越靠前</p>
            </div>
          </div>
        </div>
      </section>

      <!-- 2 商品图片（标题与主图/详情图同行） -->
      <section class="flex items-start">
        <h3 class="w-28 shrink-0 pt-1 font-semibold text-slate-700"><span class="mr-1.5 rounded bg-[#1677ff] px-1.5 py-0.5 text-xs text-white">2</span>商品图片</h3>
        <div class="flex flex-1 flex-wrap gap-10">
          <div>
            <p class="mb-2 text-slate-600">主图</p>
            <button
              class="flex h-28 w-44 flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-slate-300 text-slate-400 transition-colors hover:border-[#1677ff] hover:text-[#1677ff]"
              @click="mainFileRef?.click()"
            >
              <img v-if="mainImage" :src="mainImage" class="h-full w-full rounded-lg object-cover" alt="" />
              <template v-else>
                <CloudUpload class="h-6 w-6" />
                <span class="text-[#1677ff]">上传主图</span>
                <span class="text-xs">建议 800×800，JPG/PNG</span>
              </template>
            </button>
            <input ref="mainFileRef" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onMainChange" />
          </div>

          <div>
            <p class="mb-2 text-slate-600">详情图</p>
            <div class="flex flex-wrap items-center gap-2">
              <div v-for="(img, i) in detailImages" :key="img" class="group relative h-20 w-20 overflow-hidden rounded-lg border border-slate-200">
                <img :src="img" class="h-full w-full object-cover" alt="" />
                <button
                  class="absolute inset-0 hidden items-center justify-center bg-black/40 text-white group-hover:flex"
                  @click="detailImages.splice(i, 1)"
                ><Trash2 class="h-4 w-4" /></button>
              </div>
              <button
                v-if="detailImages.length < 10"
                class="flex h-20 w-20 items-center justify-center rounded-lg border-2 border-dashed border-slate-300 text-slate-400 hover:border-[#1677ff] hover:text-[#1677ff]"
                @click="detailFileRef?.click()"
              ><Plus class="h-5 w-5" /></button>
            </div>
            <p class="mt-1 text-xs text-slate-400">建议 800×800，可上传多张，最多 10 张</p>
            <input ref="detailFileRef" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onDetailChange" />
          </div>
        </div>
      </section>

      <!-- 3 SKU 规格与价格库存 -->
      <section>
        <h3 class="mb-3 font-semibold text-slate-700"><span class="mr-1.5 rounded bg-[#1677ff] px-1.5 py-0.5 text-xs text-white">3</span>SKU 规格与价格库存</h3>
        <table class="w-full text-[13px]">
          <thead>
            <tr class="border-b border-slate-200 text-left text-slate-500">
              <th class="px-3 py-2">规格组合（颜色/尺码）</th>
              <th class="w-40 px-3 py-2">SKU编码</th>
              <th class="w-32 px-3 py-2">售价（元）</th>
              <th class="w-28 px-3 py-2">库存</th>
              <th class="w-20 px-3 py-2">状态</th>
              <th class="w-16 px-3 py-2">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(sku, i) in skus" :key="i" class="border-b border-slate-100">
              <td class="px-3 py-2">
                <input
                  v-model="sku.specsText" type="text" placeholder="如：黑色/M"
                  class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]"
                />
              </td>
              <td class="px-3 py-2">
                <input
                  v-model="sku.sku_code" type="text" placeholder="留空自动生成"
                  class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]"
                />
              </td>
              <td class="px-3 py-2">
                <input
                  v-model="sku.price" type="number" step="0.01" min="0"
                  class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]"
                />
              </td>
              <td class="px-3 py-2">
                <input
                  v-model.number="sku.stock" type="number" min="0"
                  class="w-full rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]"
                />
              </td>
              <td class="px-3 py-2">
                <span
                  class="rounded px-2 py-0.5 text-xs"
                  :class="sku.status === 1 ? 'bg-[#e8f7ec] text-[#2e9e57]' : 'bg-slate-100 text-slate-400'"
                >{{ sku.status === 1 ? '上架' : '下架' }}</span>
              </td>
              <td class="px-3 py-2">
                <button class="text-red-400 hover:text-red-500" @click="removeSku(i)"><Trash2 class="h-4 w-4" /></button>
              </td>
            </tr>
          </tbody>
        </table>
        <button class="mt-3 inline-flex items-center gap-1 rounded-md bg-[#e6f4ff] px-3 py-1.5 text-[#1677ff] hover:bg-[#bae0ff]" @click="addSku">
          <Plus class="h-4 w-4" /> 添加规格
        </button>
      </section>

      <!-- 4 商品详情（标题与编辑器同行，富文本工具栏） -->
      <section class="flex items-start">
        <h3 class="w-28 shrink-0 pt-2 font-semibold text-slate-700"><span class="mr-1.5 rounded bg-[#1677ff] px-1.5 py-0.5 text-xs text-white">4</span>商品详情</h3>
        <div class="min-w-0 flex-1">
          <RichTextEditor v-model="form.description" placeholder="请输入商品详情..." />
        </div>
      </section>

      <!-- 错误提示 -->
      <p v-if="errorMsg" class="rounded-md bg-red-50 px-3 py-2 text-red-500">{{ errorMsg }}</p>

      <!-- 底部操作 -->
      <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
        <Button class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="saving" @click="save(false)">
          <Save class="mr-1 h-4 w-4" /> {{ saving ? '保存中...' : '保存' }}
        </Button>
        <Button
          v-if="mode !== 'view'" class="bg-[#69b1ff] hover:bg-[#91caff]" :disabled="saving"
          @click="save(true)"
        ><CloudUpload class="mr-1 h-4 w-4" /> 保存并上架</Button>
        <Button variant="outline" @click="cancel">取消</Button>
      </div>
    </div>
  </div>
</template>
