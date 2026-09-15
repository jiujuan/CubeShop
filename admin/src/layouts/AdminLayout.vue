<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  Box, FileClock, FolderTree, LayoutDashboard, LogOut, Package, RotateCcw, Settings, SquareUser, UserRound,
} from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'

/**
 * 管理端布局：侧栏（动态菜单，按权限过滤）+ 顶栏（用户菜单）
 */
const router = useRouter()
const auth = useAuthStore()
const userMenuOpen = ref(false)

/** 图标映射（路由 meta.icon → 组件） */
const icons: Record<string, unknown> = {
  LayoutDashboard,
  Package,
  FolderTree,
  Settings,
  FileClock,
  RotateCcw,
}

interface MenuItem {
  path: string
  title: string
  icon?: string
  permission?: string
}

const appTitle = 'CubeShop'

const menus = computed<MenuItem[]>(() => {
  const items: MenuItem[] = [
    { path: '/dashboard', title: '工作台', icon: 'LayoutDashboard' },
    { path: '/products', title: '商品管理', icon: 'Package', permission: 'product.view' },
    { path: '/categories', title: '分类管理', icon: 'FolderTree', permission: 'category.manage' },
    { path: '/refunds', title: '退款处理', icon: 'RotateCcw', permission: 'refund.view' },
    { path: '/configs', title: '系统配置', icon: 'Settings', permission: 'config.manage' },
    { path: '/operation-logs', title: '操作日志', icon: 'FileClock', permission: 'log.view' },
  ]
  // 动态菜单：按权限过滤（超管直通）
  return items.filter((m) => auth.hasPermission(m.permission))
})

const pageTitle = computed(() => (router.currentRoute.value.meta.title as string) || '')

async function handleLogout() {
  await auth.logout()
  router.replace('/login')
}
</script>

<template>
  <div class="flex h-screen min-h-0 overflow-hidden">
    <!-- 侧栏 -->
    <aside class="flex w-56 shrink-0 flex-col border-r border-[#d6e9ff] bg-[#e6f4ff]">
      <div class="flex h-16 items-center gap-2.5 px-5">
        <Box class="h-7 w-7 text-[#1677ff]" />
        <span class="text-lg font-bold text-slate-900">{{ appTitle }}</span>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-3">
        <RouterLink
          v-for="menu in menus"
          :key="menu.path"
          :to="menu.path"
          class="flex items-center gap-3 rounded-lg px-3.5 py-2.5 text-sm font-medium text-[#1f2329] transition-colors hover:bg-[#d6e9ff] data-[active=true]:bg-[#1677ff] data-[active=true]:text-white"
          :data-active="$route.path.startsWith(menu.path)"
        >
          <component :is="icons[menu.icon ?? '']" class="h-5 w-5" />
          {{ menu.title }}
        </RouterLink>
      </nav>
    </aside>

    <!-- 主区域 -->
    <div class="flex min-w-0 flex-1 flex-col">
      <!-- 顶栏 -->
      <header class="flex h-12 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4">
        <div class="text-[13px] font-medium text-slate-600">{{ pageTitle }}</div>

        <div class="relative">
          <button
            class="flex items-center gap-2 rounded px-2 py-1 text-[13px] text-slate-600 hover:bg-slate-100"
            data-test="user-menu"
            @click="userMenuOpen = !userMenuOpen"
          >
            <img v-if="auth.user?.avatar" :src="auth.user.avatar" alt="头像" class="h-6 w-6 rounded-full" />
            <SquareUser v-else class="h-5 w-5 text-slate-400" />
            {{ auth.user?.nickname || auth.user?.username || '用户' }}
          </button>

          <div
            v-if="userMenuOpen"
            class="absolute right-0 top-10 z-10 w-36 rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
            @click="userMenuOpen = false"
          >
            <RouterLink to="/profile" class="flex items-center gap-2 px-3 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50">
              <UserRound class="h-4 w-4" />
              个人中心
            </RouterLink>
            <button class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[13px] text-red-500 hover:bg-slate-50" @click="handleLogout">
              <LogOut class="h-4 w-4" />
              退出登录
            </button>
          </div>
        </div>
      </header>

      <!-- 内容区 -->
      <main class="min-h-0 flex-1 overflow-y-auto p-4">
        <RouterView />
      </main>
    </div>
  </div>
</template>
