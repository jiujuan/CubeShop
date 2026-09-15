<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft } from 'lucide-vue-next'
import { getProduct, type AdminProduct } from '@/api/product'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 商品详情（纯展示：无表单控件，只读渲染商品信息/SKU/图集）
 */
const router = useRouter()
const productId = computed(() => router.currentRoute.value.params.id as string)

const product = ref<AdminProduct | null>(null)
const loading = ref(true)
const errorMsg = ref('')

onMounted(async () => {
  try {
    const res = await getProduct(productId.value)
    product.value = res.data.data
  } catch {
    errorMsg.value = '商品不存在或已删除'
  } finally {
    loading.value = false
  }
})

const specNames = computed<string[]>(() => {
  const names: string[] = []
  for (const s of product.value?.skus ?? []) {
    for (const k of Object.keys(s.specs ?? {})) {
      if (!names.includes(k)) names.push(k)
    }
  }
  return names
})
</script>

<template>
  <div class="mx-auto max-w-4xl">
    <div class="mb-4 flex items-center justify-between border-b-2 border-[#1677ff] pb-2">
      <h2 class="text-lg font-semibold text-slate-800">商品详情</h2>
      <button
        type="button"
        class="flex items-center gap-1 rounded-md px-2.5 py-1.5 text-[13px] text-slate-600 transition-colors hover:bg-slate-100 hover:text-[#1677ff]"
        @click="router.back()"
      >
        <ArrowLeft class="h-4 w-4" /> 返回
      </button>
    </div>

    <LoadingSpinner v-if="loading" />
    <div v-else-if="errorMsg || !product" class="py-16 text-center text-sm text-slate-400">
      {{ errorMsg || '暂时无数据' }}
    </div>

    <template v-else>
      <!-- 基本信息 -->
      <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mb-4 text-sm font-semibold text-slate-800">基本信息</h3>
        <div class="flex gap-5">
          <div class="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-100 bg-slate-50">
            <img v-if="product.main_image" :src="product.main_image" class="h-full w-full object-cover" alt="主图" />
            <span v-else class="text-3xl text-slate-300">📦</span>
          </div>
          <dl class="grid min-w-0 flex-1 grid-cols-[5rem_1fr] gap-x-4 gap-y-2.5 text-[13px] leading-5">
            <dt class="text-slate-400">商品名称</dt>
            <dd class="font-medium text-slate-800">{{ product.title }}</dd>
            <dt class="text-slate-400">副标题</dt>
            <dd class="text-slate-600">{{ product.subtitle || '-' }}</dd>
            <dt class="text-slate-400">所属分类</dt>
            <dd class="text-slate-600">{{ product.category?.name || '-' }}</dd>
            <dt class="text-slate-400">价格</dt>
            <dd class="font-medium text-[#ff4d4f]">¥{{ product.price }}</dd>
            <dt class="text-slate-400">总库存</dt>
            <dd class="text-slate-600">{{ product.total_stock ?? '-' }}</dd>
            <dt class="text-slate-400">销量</dt>
            <dd class="text-slate-600">{{ product.sales_count }}</dd>
            <dt class="text-slate-400">状态</dt>
            <dd>
              <span
                class="rounded px-2 py-0.5 text-xs"
                :class="product.status === 1 ? 'bg-[#e8f7ec] text-[#2e9e57]' : 'bg-slate-100 text-slate-400'"
              >{{ product.status === 1 ? '上架' : '下架' }}</span>
            </dd>
            <dt class="text-slate-400">创建时间</dt>
            <dd class="text-slate-600">{{ product.created_at?.replace('T', ' ').slice(0, 19) || '-' }}</dd>
          </dl>
        </div>
      </section>

      <!-- SKU 规格 -->
      <section v-if="product.skus?.length" class="mt-4 rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mb-4 text-sm font-semibold text-slate-800">SKU 规格</h3>
        <table class="w-full text-[13px]">
          <thead>
            <tr class="border-b border-slate-100 text-left text-slate-400">
              <th v-for="name in specNames" :key="name" class="px-3 py-1.5 font-normal">{{ name }}</th>
              <th class="w-24 px-3 py-1.5 font-normal">SKU 编码</th>
              <th class="w-24 px-3 py-1.5 font-normal">价格</th>
              <th class="w-20 px-3 py-1.5 font-normal">库存</th>
              <th class="w-16 px-3 py-1.5 font-normal">状态</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sku in product.skus" :key="sku.id" class="border-b border-slate-50 last:border-0">
              <td v-for="name in specNames" :key="name" class="px-3 py-1.5 text-slate-700">
                {{ sku.specs?.[name] ?? '-' }}
              </td>
              <td class="px-3 py-1.5 text-slate-500">{{ sku.sku_code || '-' }}</td>
              <td class="px-3 py-1.5 font-medium text-slate-700">¥{{ sku.price }}</td>
              <td class="px-3 py-1.5 text-slate-600">{{ sku.stock }}</td>
              <td class="px-3 py-1.5">
                <span :class="sku.status === 1 ? 'text-[#2e9e57]' : 'text-slate-400'">
                  {{ sku.status === 1 ? '启用' : '停用' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- 商品图集 -->
      <section v-if="product.images?.length" class="mt-4 rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mb-4 text-sm font-semibold text-slate-800">商品图集</h3>
        <div class="flex flex-wrap gap-3">
          <img
            v-for="(img, i) in product.images" :key="i"
            :src="img" alt=""
            class="h-20 w-20 rounded-lg border border-slate-100 object-cover"
          />
        </div>
      </section>

      <!-- 图文详情 -->
      <section class="mt-4 rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mb-4 text-sm font-semibold text-slate-800">图文详情</h3>
        <div class="prose prose-slate max-w-none text-[13px] leading-6 text-slate-600" v-html="product.description || '暂无详情'"></div>
      </section>
    </template>
  </div>
</template>
