<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronDown, Package, ShoppingCart, SquareUser, UserRound } from 'lucide-vue-next'
import { getCategories, type CategoryNode } from '@/api/shop'
import { getCartCount } from '@/api/user'
import { useAuthStore } from '@/stores/auth'

/**
 * 顶栏（按原型：公告条 + logo/搜索/购物车 + 分类导航）
 * 登录态：显示购物车角标 / 用户菜单；未登录显示登录注册入口
 */
const router = useRouter()
const auth = useAuthStore()
const keyword = ref('')
const categories = ref<CategoryNode[]>([])
const cartCount = ref(0)
const userMenuOpen = ref(false)

onMounted(async () => {
  try {
    const { data } = await getCategories()
    categories.value = data.data
  } catch {
    categories.value = []
  }
  refreshCartCount()
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

function goCategory(id: number) {
  router.push(`/category/${id}`)
}

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
    <!-- 公告条 -->
    <div class="flex h-9 items-center justify-between bg-gradient-to-r from-[#e6f4ff] to-white px-6 text-xs text-slate-500">
      <div class="flex items-center gap-2">
        <span class="rounded-sm bg-[#1677ff] px-1 py-0.5 text-[10px] text-white">公告</span>
        全场满 99 元包邮 ｜ 会员专属积分翻倍，购物更优惠！
      </div>
      <div v-if="!auth.token" class="flex items-center gap-3">
        <RouterLink to="/login" class="hover:text-[#1677ff]">登录</RouterLink>
        <span class="text-slate-200">|</span>
        <RouterLink to="/register" class="hover:text-[#1677ff]">注册</RouterLink>
      </div>
      <div v-else class="flex items-center gap-3">
        <span>Hi，{{ auth.user?.nickname || auth.user?.username }}</span>
        <span class="text-slate-200">|</span>
        <RouterLink to="/account/addresses" class="hover:text-[#1677ff]">收货地址</RouterLink>
      </div>
    </div>

    <!-- 主头部 -->
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-8 px-6">
      <RouterLink to="/" class="flex items-center gap-2">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-[#1677ff]">
          <Package class="h-5 w-5 text-white" />
        </span>
        <span>
          <span class="block text-xl font-bold leading-5 text-slate-800">CubeShop</span>
          <span class="block text-[11px] text-slate-400">品质好物 · 购物无</span>
        </span>
      </RouterLink>

      <!-- 搜索 -->
      <div class="flex h-10 max-w-xl flex-1 items-center rounded-full border-2 border-[#1677ff] pl-4">
        <input
          v-model="keyword" type="text" placeholder="搜索商品"
          class="h-full flex-1 bg-transparent text-sm outline-none"
          @keyup.enter="search"
        />
        <button class="flex h-full w-16 items-center justify-center rounded-r-full bg-[#1677ff] text-white hover:bg-[#4096ff]" @click="search">
          <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
        </button>
      </div>

      <!-- 购物车 / 用户 -->
      <div class="flex items-center gap-6 text-slate-600">
        <button class="relative flex flex-col items-center text-xs hover:text-[#1677ff]" @click="goCart">
          <span class="relative">
            <ShoppingCart class="h-5 w-5" />
            <span
              v-if="cartCount > 0"
              class="absolute -right-2 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-[#ff4d4f] px-0.5 text-[10px] text-white"
            >{{ cartCount > 99 ? '99+' : cartCount }}</span>
          </span>
          购物车
        </button>

        <template v-if="auth.token">
          <div class="relative">
            <button class="flex flex-col items-center text-xs hover:text-[#1677ff]" @click="userMenuOpen = !userMenuOpen">
              <SquareUser class="h-5 w-5" />
              {{ auth.user?.nickname || auth.user?.username || '我的' }}
            </button>
            <div
              v-if="userMenuOpen"
              class="absolute right-0 top-10 z-10 w-32 rounded-lg border border-slate-100 bg-white py-1 text-xs shadow-lg"
              @click="userMenuOpen = false"
            >
              <RouterLink to="/account/addresses" class="flex items-center gap-1.5 px-3 py-2 text-slate-600 hover:bg-slate-50">
                <UserRound class="h-3.5 w-3.5" /> 收货地址
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

    <!-- 分类导航 -->
    <nav class="border-t border-slate-100">
      <div class="mx-auto flex h-11 max-w-6xl items-center gap-6 px-6 text-sm">
        <div class="group relative flex h-full items-center gap-2 bg-[#1677ff] px-4 text-white">
          <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18" /></svg>
          全部商品分类
          <ChevronDown class="h-4 w-4" />
          <!-- 下拉分类 -->
          <div class="invisible absolute left-0 top-11 w-56 rounded-b-lg bg-white py-2 text-slate-700 opacity-0 shadow-lg transition-all group-hover:visible group-hover:opacity-100">
            <div v-for="root in categories" :key="root.id" class="border-b border-slate-50 px-4 py-2 last:border-0">
              <button class="font-medium hover:text-[#1677ff]" @click="goCategory(root.id)">{{ root.name }}</button>
              <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                <button v-for="c in root.children" :key="c.id" class="hover:text-[#1677ff]" @click="goCategory(c.id)">{{ c.name }}</button>
              </div>
            </div>
          </div>
        </div>

        <button class="border-b-2 border-[#1677ff] font-medium text-[#1677ff]" @click="router.push('/')">热销推荐</button>
        <button
          v-for="root in categories" :key="root.id"
          class="hover:text-[#1677ff]"
          @click="goCategory(root.id)"
        >{{ root.name }}</button>
      </div>
    </nav>
  </header>
</template>
