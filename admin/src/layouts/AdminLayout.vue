<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  BarChart3, BookOpen, Box, Calculator, ChevronDown, ChevronRight, ChevronsLeft, ChevronsRight, ClipboardList, CreditCard, FileClock, FolderTree, History, Images, LayoutDashboard, Layers, LayoutList, LifeBuoy, ListTree, LogOut, MapPinned, MessageSquare, Megaphone, Package, RotateCcw, ScrollText, Settings, ShieldCheck,   SquareUser, Tags, Truck, UploadCloud, UserCog, UserRound, Users, Wallet, Warehouse, Ticket,
} from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'

/**
 * 管理端布局：侧栏（动态菜单，分组 + 按权限过滤）+ 顶栏（用户菜单）
 */
const router = useRouter()
const auth = useAuthStore()
const userMenuOpen = ref(false)

/** 菜单分类折叠状态（默认全部展开） */
const collapsed = ref<Record<string, boolean>>({})
function toggleGroup(title: string) {
  collapsed.value[title] = !collapsed.value[title]
}

/** 整个侧栏折叠（图标栏模式），状态持久化到 localStorage */
const SIDEBAR_STATE_KEY = 'cubeshop:sidebar-collapsed'
const sidebarCollapsed = ref(localStorage.getItem(SIDEBAR_STATE_KEY) === '1')
watch(sidebarCollapsed, (val) => localStorage.setItem(SIDEBAR_STATE_KEY, val ? '1' : '0'))

/** 折叠态下平铺展示的全部菜单项（按分组顺序展开） */
const flatMenus = computed<MenuItem[]>(() => menuGroups.value.flatMap((g) => g.items))

/** 图标映射（路由 meta.icon → 组件） */
const icons: Record<string, unknown> = {
  LayoutDashboard,
  Package,
  FolderTree,
  ClipboardList,
  RotateCcw,
  Users,
  Settings,
  FileClock,
  Tags,
  ListTree,
  LayoutList,
  MessageSquare,
  BarChart3,
  UserCog,
  ShieldCheck,
  CreditCard,
  ScrollText,
  History,
  Wallet,
  Layers,
  UploadCloud,
  MapPinned,
  Truck,
  Calculator,
  Ticket,
  LifeBuoy,
  BookOpen,
  Megaphone,
  Images,
  Warehouse,
}

interface MenuItem {
  path: string
  title: string
  icon?: string
  permission?: string
}

interface MenuGroup {
  title: string
  items: MenuItem[]
}

const appTitle = 'CubeShop'

/** 菜单分组定义：概览 / 商品 / 交易 / 用户 / 系统 */
const menuGroups = computed<MenuGroup[]>(() => {
  const groups: MenuGroup[] = [
    {
      title: '概览',
      items: [
        // 工作台含今日销售额等经营数据（接口层 dashboard.view 校验），
        // 菜单同步按该权限显隐：客服角色只持有 cs.*，不应看到进不去的入口
        { path: '/dashboard', title: '工作台', icon: 'LayoutDashboard', permission: 'dashboard.view' },
        { path: '/reports', title: '报表中心', icon: 'BarChart3', permission: 'report.view' },
      ],
    },
    {
      title: '商品',
      items: [
        { path: '/products', title: '商品管理', icon: 'Package', permission: 'product.view' },
        { path: '/categories', title: '分类管理', icon: 'FolderTree', permission: 'category.manage' },
        { path: '/brands', title: '品牌管理', icon: 'Tags', permission: 'product.view' },
        { path: '/attributes', title: '属性库', icon: 'ListTree', permission: 'product.view' },
        { path: '/category-attributes', title: '分类属性模板', icon: 'LayoutList', permission: 'product.view' },
        { path: '/category-brands', title: '分类可选品牌', icon: 'Layers', permission: 'product.view' },
      ],
    },
    {
      title: '交易',
      items: [
        { path: '/orders', title: '订单管理', icon: 'ClipboardList', permission: 'order.view' },
        { path: '/batch-ship', title: '批量发货', icon: 'UploadCloud', permission: 'order.ship' },
        { path: '/shipping-monitor', title: '物流监控', icon: 'MapPinned', permission: 'order.view' },
        { path: '/payments', title: '支付管理', icon: 'CreditCard', permission: 'payment.view' },
        { path: '/payment-logs', title: '支付日志', icon: 'ScrollText', permission: 'payment.view' },
        { path: '/order-logs', title: '订单流水', icon: 'History', permission: 'order.log' },
        { path: '/refunds', title: '退款处理', icon: 'RotateCcw', permission: 'refund.view' },
        { path: '/balance-recharges', title: '余额充值单', icon: 'Wallet', permission: 'balance.recharge.view' },
      ],
    },
    {
      title: '运营管理',
      items: [
        { path: '/reviews', title: '评价管理', icon: 'MessageSquare', permission: 'review.manage' },
        { path: '/marketing', title: '营销管理', icon: 'Ticket', permission: 'marketing.manage' },
        { path: '/announcements', title: '公告管理', icon: 'Megaphone', permission: 'announcement.manage' },
        { path: '/home-banners', title: '首页广告位', icon: 'Images', permission: 'home.manage' },
        { path: '/cs/tickets', title: '服务工单', icon: 'LifeBuoy', permission: 'cs.ticket.view' },
        { path: '/cs/faq', title: '帮助中心', icon: 'BookOpen', permission: 'cs.faq.manage' },
        { path: '/cs/quick-replies', title: '回复模板管理', icon: 'MessageSquare', permission: 'cs.faq.manage' },
      ],
    },
    {
      title: '仓库与物流',
      items: [
        // WMS 对接（WMS 计划 P0）：仓库档案 / 配置 / SKU 映射入口
        { path: '/wms/warehouses', title: 'WMS 对接', icon: 'Warehouse', permission: 'wms.config.manage' },
      ],
    },
    {
      title: '用户',
      items: [
        { path: '/users', title: '用户管理', icon: 'Users', permission: 'user.manage' },
      ],
    },
    {
      title: '系统',
      items: [
        { path: '/configs', title: '系统配置', icon: 'Settings', permission: 'config.manage' },
        { path: '/operation-logs', title: '操作日志', icon: 'FileClock', permission: 'log.view' },
        { path: '/accounts', title: '管理员账号', icon: 'UserCog', permission: 'account.manage' },
        { path: '/roles', title: '角色权限', icon: 'ShieldCheck', permission: 'role.manage' },
        { path: '/payment-channels', title: '支付渠道配置', icon: 'CreditCard', permission: 'payment.channel.manage' },
        { path: '/shipping-companies', title: '快递公司字典', icon: 'Truck', permission: 'shipping.manage' },
        { path: '/freight-templates', title: '运费模板', icon: 'Calculator', permission: 'shipping.manage' },
      ],
    },
  ]

  // 动态菜单：按权限过滤子项（超管直通），并隐藏无任何可见项的分类
  return groups
    .map((g) => ({ ...g, items: g.items.filter((m) => auth.hasPermission(m.permission)) }))
    .filter((g) => g.items.length > 0)
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
    <aside
      class="flex shrink-0 flex-col border-r border-[#d6e9ff] bg-[#e6f4ff] transition-[width] duration-200"
      :class="sidebarCollapsed ? 'w-14' : 'w-56'"
    >
      <div class="flex h-16 items-center gap-2.5 px-5" :class="sidebarCollapsed && 'justify-center px-0'">
        <Box class="h-7 w-7 shrink-0 text-[#1677ff]" />
        <span v-if="!sidebarCollapsed" class="text-lg font-bold text-slate-900">{{ appTitle }}</span>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-3">
        <!-- 折叠态：只显示图标，鼠标悬停显示名称 -->
        <template v-if="sidebarCollapsed">
          <RouterLink
            v-for="menu in flatMenus"
            :key="menu.path"
            :to="menu.path"
            :title="menu.title"
            class="flex items-center justify-center rounded-lg py-1.5 text-[#1f2329] transition-colors hover:bg-[#d6e9ff] data-[active=true]:bg-[#1677ff] data-[active=true]:text-white"
            :data-active="$route.path.startsWith(menu.path)"
          >
            <component :is="icons[menu.icon ?? '']" class="h-4 w-4" />
          </RouterLink>
        </template>

        <div v-for="group in menuGroups" v-else :key="group.title" class="mb-0.5">
          <button
            class="flex w-full items-center justify-between rounded px-3.5 py-1 text-[11px] font-semibold tracking-wider text-[#4e5969] hover:bg-[#d6e9ff]/70"
            :data-test="`menu-group-${group.title}`"
            @click="toggleGroup(group.title)"
          >
            <span>{{ group.title }}</span>
            <component :is="collapsed[group.title] ? ChevronRight : ChevronDown" class="h-3.5 w-3.5" />
          </button>

          <div v-show="!collapsed[group.title]" class="mt-0.5 space-y-0.5">
            <RouterLink
              v-for="menu in group.items"
              :key="menu.path"
              :to="menu.path"
              class="flex items-center gap-3 rounded-lg px-3.5 py-1.5 text-sm font-medium text-[#1f2329] transition-colors hover:bg-[#d6e9ff] data-[active=true]:bg-[#1677ff] data-[active=true]:text-white"
              :data-active="$route.path.startsWith(menu.path)"
            >
              <component :is="icons[menu.icon ?? '']" class="h-4 w-4" />
              {{ menu.title }}
            </RouterLink>
          </div>
        </div>
      </nav>

      <!-- 底部：侧栏折叠开关 -->
      <div class="border-t border-[#d6e9ff] p-2">
        <button
          class="flex w-full items-center rounded-lg px-3.5 py-1.5 text-[13px] text-[#4e5969] transition-colors hover:bg-[#d6e9ff]"
          :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-2'"
          :title="sidebarCollapsed ? '展开菜单' : '折叠菜单'"
          data-test="sidebar-toggle"
          @click="sidebarCollapsed = !sidebarCollapsed"
        >
          <component :is="sidebarCollapsed ? ChevronsRight : ChevronsLeft" class="h-4 w-4 shrink-0" />
          <span v-if="!sidebarCollapsed">折叠菜单</span>
        </button>
      </div>
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
