<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Bell, CheckCheck } from 'lucide-vue-next'
import { getNotifications, markNotificationsRead, type NotificationItem } from '@/api/notification'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 通知中心（V1.1 F02 / T-019）
 *
 * 全部 / 未读 Tab + 分页 + 单条已读并跳转 + 一键全部已读 + 空态。
 */
const router = useRouter()
const auth = useAuthStore()

const list = ref<NotificationItem[]>([])
const pagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
const loading = ref(true)
const tab = ref<'all' | 'unread'>('all')
const tip = ref('')

const unreadCount = computed(() => list.value.filter((n) => !n.is_read).length)

async function load() {
  loading.value = true
  try {
    const { data } = await getNotifications({
      is_read: tab.value === 'unread' ? 0 : undefined,
      page: pagination.value.page,
      page_size: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (auth.token) await load()
  else loading.value = false
})

function switchTab(t: 'all' | 'unread') {
  tab.value = t
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load()
}

async function openItem(n: NotificationItem) {
  tip.value = ''
  if (!n.is_read) {
    try {
      await markNotificationsRead([n.id])
      n.is_read = true
    } catch (e) {
      tip.value = e instanceof Error ? e.message : '操作失败'
    }
  }
  if (n.link) router.push(n.link)
  else await load()
}

async function markAll() {
  tip.value = ''
  try {
    await markNotificationsRead([])
    await load()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '操作失败'
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-6 py-8">
      <div class="mb-5 flex items-center justify-between">
        <h1 class="flex items-center gap-2 text-xl font-bold text-slate-800">
          <Bell class="h-5 w-5 text-[#1677ff]" /> 消息通知
        </h1>
        <button
          class="flex items-center gap-1 text-sm text-slate-500 hover:text-[#1677ff]"
          data-testid="mark-all"
          @click="markAll"
        ><CheckCheck class="h-4 w-4" /> 全部已读</button>
      </div>

      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <div v-else-if="!auth.token" class="flex flex-col items-center py-24 text-slate-400">
        <p class="mb-4">请先登录</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="router.push('/login')">去登录</button>
      </div>

      <template v-else>
        <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

        <!-- Tab -->
        <div class="mb-4 flex gap-2" data-testid="notification-tabs">
          <button
            class="rounded-full px-4 py-1.5 text-sm transition-colors"
            :class="tab === 'all' ? 'bg-[#1677ff] text-white' : 'bg-white text-slate-500 hover:text-[#1677ff]'"
            data-testid="tab-all"
            @click="switchTab('all')"
          >全部</button>
          <button
            class="rounded-full px-4 py-1.5 text-sm transition-colors"
            :class="tab === 'unread' ? 'bg-[#1677ff] text-white' : 'bg-white text-slate-500 hover:text-[#1677ff]'"
            data-testid="tab-unread"
            @click="switchTab('unread')"
          >未读</button>
          <span v-if="unreadCount" class="self-center text-xs text-slate-400">本页 {{ unreadCount }} 条未读</span>
        </div>

        <!-- 列表 -->
        <div v-if="!list.length" class="flex flex-col items-center rounded-xl bg-white py-20 text-slate-400">
          <Bell class="mb-3 h-10 w-10 text-slate-200" />
          <p class="text-sm" data-testid="notification-empty">
            {{ tab === 'unread' ? '暂无未读消息' : '暂无消息' }}
          </p>
        </div>

        <div v-else class="overflow-hidden rounded-xl bg-white">
          <button
            v-for="n in list" :key="n.id"
            class="flex w-full items-start gap-3 border-b border-slate-50 px-5 py-4 text-left last:border-0 hover:bg-slate-50"
            :data-testid="`notification-row-${n.id}`"
            @click="openItem(n)"
          >
            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.is_read ? 'bg-transparent' : 'bg-[#ff4d4f]'"></span>
            <span class="min-w-0 flex-1">
              <span class="flex items-center justify-between">
                <span class="text-sm font-medium" :class="n.is_read ? 'text-slate-500' : 'text-slate-800'">{{ n.title }}</span>
                <span class="text-xs text-slate-300">{{ n.created_at }}</span>
              </span>
              <span class="mt-1 block text-sm text-slate-500">{{ n.content }}</span>
            </span>
          </button>
        </div>

        <!-- 分页 -->
        <div v-if="pagination.total_pages > 1" class="mt-4 flex items-center justify-center gap-1 text-sm">
          <button
            class="rounded border border-slate-200 px-3 py-1 disabled:opacity-40"
            :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)"
          >上一页</button>
          <span class="px-3 text-slate-400">{{ pagination.page }} / {{ pagination.total_pages }}</span>
          <button
            class="rounded border border-slate-200 px-3 py-1 disabled:opacity-40"
            :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)"
          >下一页</button>
        </div>
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
