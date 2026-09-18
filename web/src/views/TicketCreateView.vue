<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, ImagePlus, X } from 'lucide-vue-next'
import { createTicket, getTicketTypes, uploadTicketImage, type TicketType } from '@/api/cs'
import { getOrders } from '@/api/order'
import { getProfile } from '@/api/user'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 提交工单（CS-113）
 *
 * 类型联动「是否必须关联订单」；凭证图片 ≤9 张；联系方式默认带出账号手机。
 * 提交成功后跳转工单详情（展示工单号）。
 */
const route = useRoute()
const router = useRouter()

const MAX_IMAGES = 9

interface OrderOption {
  id: string | number
  order_no: string
  status_label?: string
  items?: Array<{ product_title?: string; sku_image?: string | null }>
}

const loading = ref(true)
const types = ref<TicketType[]>([])
const orders = ref<OrderOption[]>([])

const typeId = ref<number | null>(null)
const orderId = ref<string | null>(route.query.order_id ? String(route.query.order_id) : null)
const title = ref((route.query.title as string) ?? '')
const content = ref('')
const contact = ref('')
const images = ref<string[]>([])
const uploading = ref(false)
const submitting = ref(false)
const formError = ref('')

const selectedType = computed(() => types.value.find((t) => t.id === typeId.value) ?? null)
const orderRequired = computed(() => selectedType.value?.require_order === true)

async function loadOptions() {
  loading.value = true
  try {
    const [typeRes, orderRes] = await Promise.all([
      getTicketTypes(),
      getOrders({ page: 1, page_size: 20 }).catch(() => null),
    ])
    types.value = typeRes.data.data
    orders.value = (orderRes?.data.data.list ?? []) as OrderOption[]
  } catch (e) {
    formError.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
  }
}

function validate(): boolean {
  formError.value = ''
  if (!typeId.value) { formError.value = '请选择问题类型'; return false }
  if (orderRequired.value && !orderId.value) { formError.value = '该问题类型必须关联订单，请选择订单'; return false }
  if (!title.value.trim()) { formError.value = '请填写标题'; return false }
  if (!content.value.trim()) { formError.value = '请描述您的问题'; return false }
  if (images.value.length > MAX_IMAGES) { formError.value = `凭证图片最多 ${MAX_IMAGES} 张`; return false }
  return true
}

async function onFiles(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  for (const f of files) {
    if (images.value.length >= MAX_IMAGES) {
      formError.value = `凭证图片最多 ${MAX_IMAGES} 张`
      break
    }
    uploading.value = true
    try {
      const { data } = await uploadTicketImage(f)
      images.value.push(data.data.url)
    } catch (err) {
      // 上传失败提示，但不阻塞整体提交流程
      formError.value = err instanceof Error ? err.message : '图片上传失败，可稍后重试'
    } finally {
      uploading.value = false
    }
  }
}

function removeImage(i: number) {
  images.value.splice(i, 1)
}

async function submit() {
  if (!validate()) return
  submitting.value = true
  try {
    const { data } = await createTicket({
      type_id: typeId.value as number,
      title: title.value.trim(),
      content: content.value.trim(),
      order_id: orderId.value ?? undefined,
      images: images.value.length ? images.value : undefined,
      contact: contact.value.trim() || undefined,
    })
    router.replace(`/service-center/tickets/${data.data.ticket.id}`)
  } catch (e) {
    formError.value = e instanceof Error ? e.message : '提交失败'
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await loadOptions()
  if (!contact.value) {
    try {
      const { data } = await getProfile()
      contact.value = data.data.phone ?? ''
    } catch {
      /* 忽略 */
    }
  }
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-2xl flex-1 px-4 py-6 sm:px-6" data-testid="ticket-create">
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/service-center')">服务中心</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">提交工单</span>
      </nav>

      <h1 class="mb-4 text-lg font-bold text-slate-800">提交服务工单</h1>

      <LoadingSpinner v-if="loading" />

      <form v-else class="space-y-4 rounded-xl bg-white p-5 sm:p-6" @submit.prevent="submit">
        <label class="block">
          <span class="mb-1 block text-sm text-slate-600">问题类型 <span class="text-[#ff4d4f]">*</span></span>
          <select v-model.number="typeId" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]" data-testid="ticket-type-select">
            <option :value="null" disabled>请选择问题类型</option>
            <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm text-slate-600">
            关联订单 <span v-if="orderRequired" class="text-[#ff4d4f]">*</span>
            <span v-else class="text-xs text-slate-400">（选填）</span>
          </span>
          <select v-model="orderId" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]" data-testid="ticket-order-select">
            <option :value="null">不关联订单</option>
            <option v-for="o in orders" :key="o.id" :value="o.id">{{ o.order_no }}</option>
          </select>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm text-slate-600">标题 <span class="text-[#ff4d4f]">*</span></span>
          <input v-model="title" type="text" maxlength="128" placeholder="一句话描述您的问题" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]" data-testid="ticket-title-input" />
        </label>

        <label class="block">
          <span class="mb-1 block text-sm text-slate-600">问题描述 <span class="text-[#ff4d4f]">*</span></span>
          <textarea v-model="content" rows="5" maxlength="2000" placeholder="请尽量描述清楚，便于客服快速处理" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]" data-testid="ticket-content-input"></textarea>
        </label>

        <div class="block">
          <span class="mb-1 block text-sm text-slate-600">凭证图片 <span class="text-xs text-slate-400">（最多 {{ MAX_IMAGES }} 张）</span></span>
          <div class="flex flex-wrap gap-2">
            <div v-for="(img, i) in images" :key="img" class="relative h-20 w-20 overflow-hidden rounded-md border border-slate-200">
              <img :src="img" class="h-full w-full object-cover" alt="" />
              <button type="button" class="absolute right-0 top-0 rounded-bl bg-black/50 p-0.5 text-white" :data-testid="`remove-image-${i}`" @click="removeImage(i)"><X class="h-3 w-3" /></button>
            </div>
            <label v-if="images.length < MAX_IMAGES" class="flex h-20 w-20 cursor-pointer flex-col items-center justify-center gap-1 rounded-md border border-dashed border-slate-300 text-slate-400 hover:border-[#1677ff] hover:text-[#1677ff]">
              <ImagePlus class="h-5 w-5" />
              <span class="text-[10px]">{{ uploading ? '上传中…' : '添加' }}</span>
              <input type="file" accept="image/*" multiple class="hidden" data-testid="ticket-images-input" @change="onFiles" />
            </label>
          </div>
        </div>

        <label class="block">
          <span class="mb-1 block text-sm text-slate-600">联系方式</span>
          <input v-model="contact" type="text" maxlength="64" placeholder="手机号 / 邮箱" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]" data-testid="ticket-contact-input" />
        </label>

        <p v-if="formError" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-500" data-testid="form-error">{{ formError }}</p>

        <div class="flex justify-end gap-3 pt-1">
          <button type="button" class="rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-500 hover:text-slate-700" @click="router.back()">取消</button>
          <button type="submit" class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff] disabled:opacity-50" :disabled="submitting" data-testid="ticket-submit">
            {{ submitting ? '提交中…' : '提交工单' }}
          </button>
        </div>
      </form>
    </main>

    <ShopFooter />
  </div>
</template>
