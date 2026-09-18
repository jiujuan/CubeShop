<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Bell, ChevronDown, ClipboardList, Clock, Heart, House, MapPin, Package, ShoppingCart, SquareUser, Ticket, UserRound, Volume2 } from 'lucide-vue-next'
import { getCategories, type CategoryNode } from '@/api/shop'
import { getAnnouncements, type AnnouncementListItem } from '@/api/announcement'
import { getCartCount } from '@/api/user'
import NotificationBell from '@/components/NotificationBell.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 顶栏（按原型：公告条 + logo/搜索/购物车 + 分类导航）
 * 登录态：显示购物车角标 / 用户菜单；未登录显示登录注册入口
 *
 * 响应式约定（P1 修复：375px 下曾横向溢出 296px）：
 * - < sm：公告条隐藏账号入口、logo 只留图标、右侧只留图标，搜索框 `min-w-0` 可压缩
 * - ≥ lg：恢复桌面版完整布局（文字标签、副标语、我的订单、分类下拉）
 * - 分类导航在 < lg 横向滚动（overflow-x-auto），故分类下拉只在 ≥ lg 渲染，避免被滚动容器裁剪
 */
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const keyword = ref('')
const categories = ref<CategoryNode[]>([])
const cartCount = ref(0)
const userMenuOpen = ref(false)

/** 顶部公告条（P-Announcement）：拉取后台已发布公告，置顶优先轮播 */
const announcements = ref<AnnouncementListItem[]>([])
const topIndex = ref(0)
let rotateTimer: ReturnType<typeof setInterval> | null = null
const topAnnouncement = computed<AnnouncementListItem | null>(
  () => announcements.value[topIndex.value] ?? null,
)

onMounted(async () => {
  try {
    const { data } = await getCategories()
    categories.value = data.data
  } catch {
    categories.value = []
  }
  refreshCartCount()

  try {
    const { data } = await getAnnouncements({ per_page: 5 })
    announcements.value = data.data.list
    if (announcements.value.length > 1) {
      rotateTimer = setInterval(() => {
        topIndex.value = (topIndex.value + 1) % announcements.value.length
      }, 3500)
    }
  } catch {
    /* 公告加载失败不影响顶栏其余功能 */
  }
})

onBeforeUnmount(() => {
  if (rotateTimer) clearInterval(rotateTimer)
})

/** 登录态变化 / 加购后刷新角标 */
function refreshCartCount() {
  if (!auth.token) {
    cartCount.value = 0
    return
  }
  getCartCount()
    .then(({ data }) => (cartCount.value = data.data.count))
    .catch(() => (cartCount.value = 0))
}

defineExpose({ refreshCartCount })

function search() {
  router.push({ path: '/search', query: keyword.value.trim() ? { keyword: keyword.value.trim() } : {} })
}

function goCategory(id: string) {
  router.push(`/category/${id}`)
}

/** 当前所在一级分类（分类页导航高亮） */
const activeRootId = computed(() => {
  if (route.name !== 'category' || !route.params.id) return undefined
  const id = route.params.id as string
  const root = categories.value.find((r) => r.id === id || r.children.some((c) => c.id === id))
  return root?.id
})

function goCart() {
  router.push(auth.token ? '/cart' : { path: '/login', query: { redirect: '/cart' } })
}

async function handleLogout() {
  await auth.logout()
  userMenuOpen.value = false
  router.push('/')
}
</script>

<template>
  <header class="sticky top-0 z-40 bg-white shadow-sm">
    <!-- 公告条：动态展示后台已发布公告（置顶优先轮播），点击进详情 -->
    <div class="flex h-9 items-center justify-between gap-2 bg-gradient-to-r from-[#e6f4ff] to-white px-3 text-xs text-slate-500 sm:px-6">
      <RouterLink
        v-if="topAnnouncement"
        :to="`/announcements/${topAnnouncement.id}`"
        class="flex min-w-0 items-center gap-2 truncate text-[#1677ff] hover:opacity-80"
      >
        <Volume2 class="h-3.5 w-3.5 shrink-0" />
        <span class="truncate">{{ topAnnouncement.title }}</span>
      </RouterLink>
      <div v-if="!auth.token" class="hidden shrink-0 items-center gap-3 sm:flex">
        <RouterLink to="/login" class="hover:text-[#1677ff]">登录</RouterLink>
        <span class="text-slate-200">|</span>
        <RouterLink to="/register" class="hover:text-[#1677ff]">注册</RouterLink>
      </div>
      <div v-else class="hidden shrink-0 items-center gap-3 sm:flex">
        <span class="hidden max-w-[10rem] truncate md:inline">Hi，{{ auth.user?.nickname || auth.user?.username }}</span>
        <span class="hidden text-slate-200 md:inline">|</span>
        <RouterLink to="/account" class="hover:text-[#1677ff]">个人中心</RouterLink>
      </div>
    </div>

    <!-- 主头部 -->
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-3 lg:gap-8 lg:px-6">
      <RouterLink to="/" class="flex shrink-0 items-center gap-2">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-[#1677ff]">
          <Package class="h-5 w-5 text-white" />
        </span>
        <span class="hidden sm:block">
          <span class="block text-xl font-bold leading-5 text-slate-800">CubeShop</span>
          <span class="hidden text-[11px] text-slate-400 lg:block">品质好物 · 购物无忧</span>
        </span>
      </RouterLink>

      <!-- 搜索（min-w-0：允许 flex 收缩到内容宽度以下，避免把右侧入口挤出视口） -->
      <div class="flex h-10 min-w-0 max-w-xl flex-1 items-center rounded-full border-2 border-[#1677ff] pl-3 sm:pl-4" data-testid="search-box">
        <input
          v-model="keyword" type="text" placeholder="搜索商品"
          class="h-full w-full min-w-0 flex-1 bg-transparent text-sm outline-none"
          @keyup.enter="search"
        />
        <button class="flex h-full w-11 shrink-0 items-center justify-center rounded-r-full bg-[#1677ff] text-white hover:bg-[#4096ff] sm:w-16" @click="search">
          <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
        </button>
      </div>

      <!-- 购物车 / 用户 -->
      <div class="flex shrink-0 items-center gap-3 text-slate-600 lg:gap-6" data-testid="header-actions">
        <button class="relative flex flex-col items-center text-xs hover:text-[#1677ff]" @click="goCart">
          <span class="relative">
            <ShoppingCart class="h-5 w-5" />
            <span
              v-if="cartCount > 0"
              class="absolute -right-2 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-[#ff8a00] px-0.5 text-[10px] text-white"
            >{{ cartCount > 99 ? '99+' : cartCount }}</span>
          </span>
          <span class="hidden lg:block">购物车</span>
        </button>

        <!-- 我的订单入口（< lg 收进用户菜单，避免头部拥挤） -->
        <button class="hidden flex-col items-center text-xs hover:text-[#1677ff] lg:flex" @click="router.push('/orders')">
          <ClipboardList class="h-5 w-5" />
          我的订单
        </button>

        <!-- 通知铃铛（V1.1 F02 / T-019，登录态可见） -->
        <NotificationBell />

        <template v-if="auth.token">
          <div class="relative">
            <button class="flex flex-col items-center text-xs hover:text-[#1677ff]" data-testid="user-menu-trigger" @click="userMenuOpen = !userMenuOpen">
              <SquareUser class="h-5 w-5" />
              <span class="hidden max-w-[4rem] truncate lg:block">{{ auth.user?.nickname || auth.user?.username || '我的' }}</span>
            </button>
            <div
              v-if="userMenuOpen"
              class="absolute right-0 top-10 z-10 w-36 rounded-lg border border-slate-100 bg-white py-1 text-xs shadow-lg"
              data-testid="user-menu"
              @click="userMenuOpen = false"
            >
              <RouterLink to="/account" class="flex items-center gap-1.5 px-3 py-2 text-slate-600 hover:bg-slate-50">
                <UserRound class="h-3.5 w-3.5" /> 个人中心
              </RouterLink>
              <RouterLink to="/orders" class="flex items-center gap-1.5 px-3 py-2 text-slate-600 hover:bg-slate-50">
                <ClipboardList class="h-3.5 w-3.5" /> 我的订单
              </RouterLink>
              <RouterLink to="/coupons/mine" class="flex items-center gap-1.5 px-3 py-2 text-slate-600 hover:bg-slate-50">
                <Ticket class="h-3.5 w-3.5" /> 我的优惠券
              </RouterLink>
              <RouterLink to="/coupons/center" class="flex items-center gap-1.5 px-3 py-2 text-slate-600 hover:bg-slate-50">
                <Ticket class="h-3.5 w-3.5" /> 领券中心
              </RouterLink>
              <RouterLink to="/account/favorites" class="flex items-center gap-1.5 px-3 py-2 text-slate-600 hover:bg-slate-50">
                <Heart class="h-3.5 w-3.5" /> 我的收藏
              </RouterLink>
              <RouterLink to="/account/histories" class="flex items-center gap-1.5 px-3 py-2 text-slate-600 hover:bg-slate-50">
                <Clock class="h-3.5 w-3.5" /> 浏览足迹
              </RouterLink>
              <RouterLink to="/notifications" class="flex items-center gap-1.5 px-3 py-2 text-slate-600 hover:bg-slate-50">
                <Bell class="h-3.5 w-3.5" /> 消息通知
              </RouterLink>
              <RouterLink to="/account/addresses" class="flex items-center gap-1.5 px-3 py-2 text-slate-600 hover:bg-slate-50">
                <MapPin class="h-3.5 w-3.5" /> 收货地址
              </RouterLink>
              <button class="w-full px-3 py-2 text-left text-red-500 hover:bg-slate-50" @click="handleLogout">退出登录</button>
            </div>
          </div>
        </template>
        <button v-else class="flex flex-col items-center text-xs hover:text-[#1677ff]" @click="router.push('/login')">
          <SquareUser class="h-5 w-5" />
          登录
        </button>
      </div>
    </div>

    <!-- 分类导航（< lg 横向滚动；≥ lg 恢复原布局并允许下拉面板溢出） -->
    <nav class="border-t border-slate-100">
      <div class="mx-auto flex h-11 max-w-6xl items-center gap-4 overflow-x-auto px-3 text-sm [scrollbar-width:none] lg:gap-6 lg:overflow-x-visible lg:px-6 [&::-webkit-scrollbar]:hidden" data-testid="category-nav">
        <div class="group relative flex h-full shrink-0 items-center gap-2 whitespace-nowrap bg-[#1677ff] px-4 text-white">
          <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18" /></svg>
          全部商品分类
          <ChevronDown class="h-4 w-4" />
          <!-- 下拉分类（仅 ≥ lg 渲染：移动端滚动容器会裁剪绝对定位面板） -->
          <div class="invisible absolute left-0 top-11 hidden w-56 rounded-b-lg bg-white py-2 text-slate-700 opacity-0 shadow-lg transition-all group-hover:visible group-hover:opacity-100 lg:block">
            <div v-for="root in categories" :key="root.id" class="border-b border-slate-50 px-4 py-2 last:border-0">
              <button class="font-medium hover:text-[#1677ff]" @click="goCategory(root.id)">{{ root.name }}</button>
              <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                <button v-for="c in root.children" :key="c.id" class="hover:text-[#1677ff]" @click="goCategory(c.id)">{{ c.name }}</button>
              </div>
            </div>
          </div>
        </div>

        <button
          class="flex shrink-0 items-center gap-1 whitespace-nowrap border-b-2"
          :class="route.path === '/' ? 'border-[#1677ff] font-medium text-[#1677ff]' : 'border-transparent hover:text-[#1677ff]'"
          @click="router.push('/')"
        >
          <House class="h-3.5 w-3.5" /> 首页
        </button>
        <button
          class="shrink-0 whitespace-nowrap border-b-2"
          :class="route.path === '/search' ? 'border-[#1677ff] font-medium text-[#1677ff]' : 'border-transparent hover:text-[#1677ff]'"
          @click="router.push('/search?sort=sales_desc')"
        >热销推荐</button>
        <button
          v-for="root in categories" :key="root.id"
          class="shrink-0 whitespace-nowrap border-b-2 transition-colors lg:shrink lg:whitespace-normal"
          :class="activeRootId === root.id ? 'border-[#1677ff] font-medium text-[#1677ff]' : 'border-transparent hover:text-[#1677ff]'"
          @click="goCategory(root.id)"
        >{{ root.name }}</button>
      </div>
    </nav>
  </header>
</template>
