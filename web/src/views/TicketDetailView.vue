<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, ImagePlus, Package, Send, X } from 'lucide-vue-next'
import {
  addTicketMessage, closeTicket, getTicket, uploadTicketImage,
  type TicketDetail, type TicketMessage, type TicketOrderSummary,
} from '@/api/cs'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 工单详情（CS-113）
 *
 * 对话式沟通：气泡区分「我 / 客服 / 系统」；图片可预览；内部备注前端兜底过滤（后端已过滤）；
 * 已关闭工单隐藏输入区与关闭按钮。
 */
const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const loading = ref(true)
const notFound = ref(false)
const ticket = ref<TicketDetail | null>(null)
const order = ref<TicketOrderSummary | null>(null)

const replyText = ref('')
const replyImages = ref<string[]>([])
const uploading = ref(false)
const sending = ref(false)
const tip = ref('')
const closeConfirm = ref(false)
const closing = ref(false)
const previewImage = ref<string | null>(null)
const streamRef = ref<HTMLElement | null>(null)

const MAX_IMAGES = 9

/** 前端兜底：内部备注即使返回也不渲染（AC-113.3） */
const visibleMessages = computed<TicketMessage[]>(
  () => (ticket.value?.messages ?? []).filter((m) => !m.is_internal),
)
const isClosed = computed(() => ticket.value?.status === 'closed')

async function load() {
  loading.value = true
  notFound.value = false
  try {
    const { data } = await getTicket(id)
    ticket.value = data.data.ticket
    order.value = data.data.order
    await scrollToBottom()
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

async function scrollToBottom() {
  await nextTick()
  if (streamRef.value) streamRef.value.scrollTop = streamRef.value.scrollHeight
}

async function onFiles(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  for (const f of files) {
    if (replyImages.value.length >= MAX_IMAGES) { tip.value = `图片最多 ${MAX_IMAGES} 张`; break }
    uploading.value = true
    try {
      const { data } = await uploadTicketImage(f)
      replyImages.value.push(data.data.url)
    } catch (err) {
      tip.value = err instanceof Error ? err.message : '图片上传失败'
    } finally {
      uploading.value = false
    }
  }
}

async function send() {
  tip.value = ''
  if (!replyText.value.trim() && !replyImages.value.length) {
    tip.value = '请输入内容或添加图片'
    return
  }
  sending.value = true
  try {
    const { data } = await addTicketMessage(id, {
      content: replyText.value.trim() || undefined,
      images: replyImages.value.length ? replyImages.value : undefined,
    })
    ticket.value = data.data.ticket
    replyText.value = ''
    replyImages.value = []
    await scrollToBottom()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '发送失败'
  } finally {
    sending.value = false
  }
}

async function doClose() {
  closeConfirm.value = false
  closing.value = true
  try {
    const { data } = await closeTicket(id)
    ticket.value = { ...(ticket.value as TicketDetail), ...data.data }
    await scrollToBottom()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '关闭失败'
  } finally {
    closing.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto flex w-full max-w-3xl flex-1 flex-col px-4 py-6 sm:px-6" data-testid="ticket-detail">
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/service-center')">服务中心</button>
        <ChevronRight class="h-3 w-3" />
        <button class="hover:text-[#1677ff]" @click="router.push('/service-center/tickets')">我的工单</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">详情</span>
      </nav>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="notFound || !ticket" class="rounded-xl bg-white py-20 text-center" data-testid="ticket-not-found">
        <p class="text-sm text-slate-500">工单不存在</p>
        <button class="mt-3 rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-500 hover:text-[#1677ff]" @click="router.push('/service-center/tickets')">返回我的工单</button>
      </div>

      <template v-else>
        <!-- 头部 -->
        <section class="mb-4 rounded-xl bg-white p-5">
          <div class="flex items-center justify-between gap-3">
            <h1 class="min-w-0 flex-1 text-base font-bold text-slate-800" data-testid="ticket-title">{{ ticket.title }}</h1>
            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs" :class="isClosed ? 'bg-slate-100 text-slate-400' : 'bg-[#e6f4ff] text-[#1677ff]'">{{ ticket.status_label }}</span>
          </div>
          <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
            <span data-testid="ticket-no">{{ ticket.ticket_no }}</span>
            <span v-if="ticket.type_name">· {{ ticket.type_name }}</span>
            <span>· {{ ticket.created_at }}</span>
          </p>

          <button
            v-if="order && ticket.order_id"
            class="mt-3 flex w-full items-center gap-3 rounded-lg border border-slate-100 p-3 text-left hover:border-[#1677ff]"
            data-testid="ticket-order-card"
            @click="router.push(`/orders/${ticket.order_id}`)"
          >
            <img v-if="order.product_image" :src="order.product_image" class="h-10 w-10 rounded object-cover" alt="" />
            <span v-else class="flex h-10 w-10 items-center justify-center rounded bg-slate-100 text-slate-400"><Package class="h-5 w-5" /></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm text-slate-700">订单 {{ order.order_no }}</span>
              <span class="mt-0.5 block text-xs text-slate-400">实付 ¥{{ order.pay_amount }}</span>
            </span>
            <ChevronRight class="h-4 w-4 shrink-0 text-slate-300" />
          </button>
        </section>

        <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500" data-testid="ticket-tip">{{ tip }}</p>

        <!-- 消息流 -->
        <section ref="streamRef" class="mb-4 max-h-[52vh] flex-1 space-y-4 overflow-y-auto rounded-xl bg-white p-5" data-testid="ticket-messages">
          <div
            v-for="m in visibleMessages" :key="m.id"
            class="flex flex-col"
            :class="m.sender_type === 'user' ? 'items-end' : 'items-start'"
            :data-testid="`msg-${m.id}`"
            :data-sender="m.sender_type"
          >
            <span class="mb-1 px-1 text-[11px] text-slate-400">{{ m.sender_name }} · {{ m.created_at }}</span>
            <div
              class="max-w-[85%] whitespace-pre-wrap break-words rounded-lg px-3 py-2 text-sm"
              :class="m.sender_type === 'user'
                ? 'bg-[#1677ff] text-white'
                : m.sender_type === 'system'
                  ? 'bg-slate-100 text-slate-500 italic'
                  : 'bg-slate-100 text-slate-700'"
            >
              <template v-if="m.content">{{ m.content }}</template>
            </div>
            <div v-if="m.images?.length" class="mt-1 flex flex-wrap gap-2">
              <img
                v-for="(img, i) in m.images" :key="i"
                :src="img"
                class="h-20 w-20 cursor-pointer rounded-md border border-slate-200 object-cover"
                alt=""
                :data-testid="`msg-image-${m.id}-${i}`"
                @click="previewImage = img"
              />
            </div>
          </div>
        </section>

        <!-- 已关闭提示 / 输入区 -->
        <p v-if="isClosed" class="rounded-xl bg-slate-100 py-4 text-center text-sm text-slate-500" data-testid="ticket-closed-tip">工单已关闭</p>

        <section v-else class="rounded-xl bg-white p-4" data-testid="ticket-reply">
          <div v-if="replyImages.length" class="mb-2 flex flex-wrap gap-2">
            <div v-for="(img, i) in replyImages" :key="img" class="relative h-16 w-16 overflow-hidden rounded-md border border-slate-200">
              <img :src="img" class="h-full w-full object-cover" alt="" />
              <button class="absolute right-0 top-0 rounded-bl bg-black/50 p-0.5 text-white" @click="replyImages.splice(i, 1)"><X class="h-3 w-3" /></button>
            </div>
          </div>
          <textarea v-model="replyText" rows="2" maxlength="2000" placeholder="输入消息…" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]" data-testid="ticket-reply-input"></textarea>
          <div class="mt-2 flex items-center justify-between">
            <div class="flex items-center gap-2">
              <label class="flex cursor-pointer items-center gap-1 text-xs text-slate-400 hover:text-[#1677ff]">
                <ImagePlus class="h-4 w-4" /> {{ uploading ? '上传中…' : '图片' }}
                <input type="file" accept="image/*" multiple class="hidden" data-testid="ticket-reply-images" @change="onFiles" />
              </label>
              <button class="text-xs text-slate-400 hover:text-red-500" :disabled="closing" data-testid="ticket-close" @click="closeConfirm = true">关闭工单</button>
            </div>
            <button class="flex items-center gap-1 rounded-full bg-[#1677ff] px-5 py-1.5 text-sm text-white hover:bg-[#4096ff] disabled:opacity-50" :disabled="sending" data-testid="ticket-send" @click="send">
              <Send class="h-4 w-4" /> {{ sending ? '发送中…' : '发送' }}
            </button>
          </div>
        </section>
      </template>
    </main>

    <ShopFooter />

    <!-- 图片预览 -->
    <div v-if="previewImage" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-6" data-testid="image-preview" @click="previewImage = null">
      <img :src="previewImage" class="max-h-full max-w-full rounded-lg" alt="" />
    </div>

    <ConfirmDialog
      v-model="closeConfirm"
      title="关闭工单"
      content="关闭后无法继续回复，确认关闭该工单吗？"
      confirm-text="确认关闭"
      :loading="closing"
      @confirm="doClose"
      @cancel="closeConfirm = false"
    />
  </div>
</template>
