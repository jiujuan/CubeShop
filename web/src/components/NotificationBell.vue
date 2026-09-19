<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Bell } from 'lucide-vue-next'
import { getNotifications, getUnreadCount, markNotificationsRead, type NotificationItem } from '@/api/notification'
import { useAuthStore } from '@/stores/auth'

/**
 * 顶栏通知铃铛（V1.1 F02 / T-019）
 *
 * - 登录态可见；未读数角标
 * - 下拉展示最近 5 条 + 「查看全部」
 * - 60s 轮询，页面隐藏时暂停（visibilitychange）
 * - 点击条目：先标记已读再跳转 link
 */
const POLL_MS = 60_000

const router = useRouter()
const auth = useAuthStore()

const unread = ref(0)
const items = ref<NotificationItem[]>([])
const open = ref(false)
const loading = ref(false)

let timer: ReturnType<typeof setInterval> | null = null

async function refreshCount() {
  if (!auth.token) {
    unread.value = 0
    return
  }
  try {
    const { data } = await getUnreadCount()
    unread.value = data.data.count
  } catch {
    // 静默失败，不打断页面
  }
}

async function refreshList() {
  if (!auth.token) return
  loading.value = true
  try {
    const { data } = await getNotifications({ page: 1, page_size: 5 })
    items.value = data.data.list
    unread.value = data.data.list.filter((n) => !n.is_read).length
    // 列表未读可能少于总数（存在更早未读），以角标接口为准
    void refreshCount()
  } catch {
    items.value = []
  } finally {
    loading.value = false
  }
}

function startPolling() {
  stopPolling()
  timer = setInterval(() => {
    if (document.visibilityState === 'visible') void refreshCount()
  }, POLL_MS)
}

function stopPolling() {
  if (timer) {
    clearInterval(timer)
    timer = null
  }
}

function onVisibilityChange() {
  if (document.visibilityState === 'visible') void refreshCount()
}

async function toggle() {
  open.value = !open.value
  if (open.value) await refreshList()
}

async function openItem(n: NotificationItem) {
  open.value = false
  if (!n.is_read) {
    try {
      await markNotificationsRead([n.id])
      await refreshCount()
    } catch {
      // 标记失败不阻塞跳转
    }
  }
  if (n.link) router.push(n.link)
}

function goAll() {
  open.value = false
  router.push('/notifications')
}

onMounted(() => {
  if (!auth.token) return
  void refreshCount()
  startPolling()
  document.addEventListener('visibilitychange', onVisibilityChange)
})

onBeforeUnmount(() => {
  stopPolling()
  document.removeEventListener('visibilitychange', onVisibilityChange)
})

defineExpose({ refreshCount, refreshList })
</script>

<template>
  <!-- @mouseleave 关闭：与「个人中心」浮层一致，鼠标移出触发区/面板（含透明过渡带）即收起 -->
  <div v-if="auth.token" class="relative" data-testid="notification-root" @mouseleave="open = false">
    <button
      class="flex flex-col items-center text-xs hover:text-[#1677ff]"
      data-testid="notification-bell"
      @click="toggle"
    >
      <span class="relative">
        <Bell class="h-5 w-5" />
        <span
          v-if="unread > 0"
          class="absolute -right-2 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-[#ff4d4f] px-0.5 text-[10px] text-white"
          data-testid="notification-badge"
        >{{ unread > 99 ? '99+' : unread }}</span>
      </span>
      消息
    </button>

    <!-- 下拉面板：left-1/2 -translate-x-1/2 使其水平居中于铃铛正下方；
         top-full + pt-2 透明过渡带，避免鼠标从按钮下移途中误触发 mouseleave -->
    <div
      v-if="open"
      class="absolute left-1/2 top-full z-20 w-72 -translate-x-1/2 rounded-lg border border-slate-100 bg-white pt-2 shadow-lg"
      data-testid="notification-panel"
    >
      <div class="flex items-center justify-between border-b border-slate-50 px-3 py-2 text-xs text-slate-400">
        <span>消息通知</span>
        <span v-if="unread > 0">{{ unread }} 条未读</span>
      </div>

      <div v-if="loading" class="px-3 py-6 text-center text-xs text-slate-400">加载中…</div>

      <div v-else-if="!items.length" class="px-3 py-6 text-center text-xs text-slate-400" data-testid="notification-panel-empty">
        暂无消息
      </div>

      <div v-else class="max-h-80 overflow-y-auto">
        <button
          v-for="n in items" :key="n.id"
          class="flex w-full items-start gap-2 border-b border-slate-50 px-3 py-2.5 text-left last:border-0 hover:bg-slate-50"
          :data-testid="`notification-item-${n.id}`"
          @click="openItem(n)"
        >
          <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="n.is_read ? 'bg-transparent' : 'bg-[#ff4d4f]'"></span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-xs font-medium" :class="n.is_read ? 'text-slate-500' : 'text-slate-700'">{{ n.title }}</span>
            <span class="mt-0.5 block truncate text-[11px] text-slate-400">{{ n.content }}</span>
            <span class="mt-0.5 block text-[10px] text-slate-300">{{ n.created_at }}</span>
          </span>
        </button>
      </div>

      <button
        class="w-full border-t border-slate-50 py-2 text-center text-xs text-[#1677ff] hover:bg-slate-50"
        data-testid="notification-view-all"
        @click="goAll"
      >查看全部</button>
    </div>
  </div>
</template>
