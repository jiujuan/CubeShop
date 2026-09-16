<script setup lang="ts">
import { computed, onMounted, ref, type Component } from 'vue'
import { useRouter } from 'vue-router'
import {
  Apple, ArrowRight, Baby, Car, ChevronRight, Crown, Dumbbell, Flame,
  Headphones, House, LayoutGrid, MessageSquare, Package, RotateCcw, ShieldCheck, Shirt,
  Sparkles, Truck, WalletMinimal,
} from 'lucide-vue-next'
import { getCategories, getHot, type CategoryNode, type ProductBrief } from '@/api/shop'
import ProductCard from '@/components/ProductCard.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 首页（按新版原型：左侧分类栏 + 中间内容区 + 右侧信息栏）
 * 数据与接口逻辑保持不变：仍只调用 /products/hot 与 /products/categories
 */
const router = useRouter()
const auth = useAuthStore()

/** 整宽商品区一屏展示的商品数：一行 6 件 × 2 行 */
const PAGE_SIZE = 12

const hot = ref<ProductBrief[]>([])
const newest = ref<ProductBrief[]>([])
const categories = ref<CategoryNode[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    const [hotRes, catRes] = await Promise.all([getHot(PAGE_SIZE), getCategories()])
    hot.value = hotRes.data.data.hot
    newest.value = hotRes.data.data.newest
    categories.value = catRes.data.data
  } catch {
    /* 单个接口异常不影响整页渲染 */
  } finally {
    loading.value = false
  }
})

/** 整宽商品区：热门商品 / 新品上架 / 人气推荐（同一份数据，纯前端视图切换） */
const activeTab = ref<'hot' | 'new' | 'popular'>('hot')
const tabs = [
  { value: 'hot' as const, label: '热门商品' },
  { value: 'new' as const, label: '新品上架' },
  { value: 'popular' as const, label: '人气推荐' },
]
const displayList = computed<ProductBrief[]>(() => {
  if (activeTab.value === 'hot') return hot.value.slice(0, PAGE_SIZE)
  if (activeTab.value === 'new') return newest.value.slice(0, PAGE_SIZE)
  // 人气推荐：热销 + 新品去重后按销量倒序
  const merged = new Map<number, ProductBrief>()
  for (const p of [...hot.value, ...newest.value]) if (!merged.has(p.id)) merged.set(p.id, p)
  return [...merged.values()].sort((a, b) => b.sales_count - a.sales_count).slice(0, PAGE_SIZE)
})

/** Banner 主图：取热销首位商品图，缺图时降级为图形占位 */
const heroImage = computed(() => hot.value.find((p) => p.main_image)?.main_image ?? '')

/** 分类图标映射（按分类名关键词匹配，未命中给通用图标） */
const ICON_RULES: Array<{ keys: string[]; icon: Component }> = [
  { keys: ['服装', '鞋', '箱包', '服饰'], icon: Shirt },
  { keys: ['数码', '手机', '电脑', '电子'], icon: Headphones },
  { keys: ['家居', '家装', '家具'], icon: House },
  { keys: ['美妆', '个护', '护肤', '彩妆'], icon: Sparkles },
  { keys: ['运动', '户外'], icon: Dumbbell },
  { keys: ['母婴', '玩具', '童'], icon: Baby },
  { keys: ['食品', '生鲜', '水果', '零食'], icon: Apple },
  { keys: ['汽车', '车品'], icon: Car },
]
function iconFor(name: string): Component {
  return ICON_RULES.find((r) => r.keys.some((k) => name.includes(k)))?.icon ?? Package
}
function subNames(root: CategoryNode) {
  return root.children.slice(0, 3).map((c) => c.name).join(' ') || '精选好物'
}
function goCategory(id: number) {
  router.push(`/category/${id}`)
}

/** 保障条（静态服务承诺展示） */
const features: Array<{ icon: Component; title: string; desc: string }> = [
  { icon: ShieldCheck, title: '正品保障', desc: '品牌直供 假一赔十' },
  { icon: Truck, title: '快递发货', desc: '仓储直发 极速送达' },
  { icon: RotateCcw, title: '售后无忧', desc: '7 天无理由退换' },
  { icon: Crown, title: '会员专享', desc: '积分抵现 更多福利' },
]

/** 三个专题入口（跳转对应分类/搜索，不改动既有接口） */
const promos = [
  { title: '手机数码专场', desc: '爆款直降 最高减 500 元', emoji: '📱', bg: 'bg-[#eef5ff]', accent: 'text-[#1668dc]', btn: 'bg-[#1677ff]', to: '/search?keyword=数码' },
  { title: '家居生活好物', desc: '品质家居 舒适生活', emoji: '🛏️', bg: 'bg-[#eefaf1]', accent: 'text-[#12a150]', btn: 'bg-[#22c55e]', to: '/search?keyword=家居' },
  { title: '美妆个护精选', desc: '大牌正品 低至 5 折', emoji: '💄', bg: 'bg-[#fff0f2]', accent: 'text-[#e8395c]', btn: 'bg-[#ff4d6a]', to: '/search?keyword=美妆' },
]

/** 右侧「我的订单」四宫格入口 */
const orderEntries = [
  { label: '待付款', icon: WalletMinimal, to: '/orders?tab=pending_payment' },
  { label: '待发货', icon: Package, to: '/orders?tab=pending_ship' },
  { label: '待收货', icon: Truck, to: '/orders?tab=pending_receive' },
  { label: '待评价', icon: MessageSquare, to: '/orders?tab=pending_review' },
]
/** 最新公告（静态展示位，只展示最新 2 条，点击进消息通知） */
const notices = [
  { text: '夏日大促！全场满 99 元包邮', date: '2024-09-14' },
  { text: '会员专享：积分翻倍活动开启', date: '2024-09-12' },
  { text: '关于快递发货时间调整的通知', date: '2024-09-10' },
  { text: '新品上架：精选数码配件', date: '2024-09-08' },
]

/** 底部大促卡片 */
const bigPromos = [
  {
    tag: '新品速递', tagClass: 'bg-white/80 text-[#1677ff]', bg: 'bg-[#eef5ff]',
    title: '智能手表 运动健康新体验', desc: '多功能监测 ｜ 超长续航',
    price: '299.00', origin: '399.00', emoji: '⌚', to: '/search?sort=newest',
  },
  {
    tag: '限时特惠', tagClass: 'bg-white/80 text-[#ff7a00]', bg: 'bg-[#fff6ee]',
    title: '电热水壶 省时高效', desc: '1.8L 大容量 ｜ 304 不锈钢',
    price: '89.00', origin: '129.00', emoji: '🫖', to: '/search?sort=sales_desc',
  },
]
</script>

<template>
  <div class="min-h-screen bg-[#f7f8fa]">
    <ShopHeader />

    <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-4">
      <div class="flex items-start gap-4">
        <!-- ============ 左：全部商品分类 ============ -->
        <aside class="hidden w-[188px] shrink-0 lg:block">
          <nav class="overflow-hidden rounded-xl border border-slate-100 bg-white py-1.5 shadow-sm">
            <button
              v-for="root in categories" :key="root.id"
              class="group flex w-full items-center gap-2.5 px-3 py-2.5 text-left transition-colors hover:bg-[#f5faff]"
              @click="goCategory(root.id)"
            >
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#f0f7ff] text-[#1677ff]">
                <component :is="iconFor(root.name)" class="h-4 w-4" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-[13px] font-medium text-slate-700 group-hover:text-[#1677ff]">{{ root.name }}</span>
                <span class="mt-0.5 block truncate text-[11px] text-slate-400">{{ subNames(root) }}</span>
              </span>
              <ChevronRight class="h-3.5 w-3.5 shrink-0 text-slate-300" />
            </button>
            <button
              class="mt-1 flex w-full items-center justify-center gap-1.5 border-t border-slate-100 px-3 pt-2.5 pb-1 text-xs text-slate-400 hover:text-[#1677ff]"
              @click="router.push('/search')"
            >
              <LayoutGrid class="h-3.5 w-3.5" /> 查看更多分类
            </button>
          </nav>
        </aside>

        <!-- ============ 中：内容主区 ============ -->
        <div class="min-w-0 flex-1 space-y-3.5">
          <!-- 主 Banner -->
          <section class="relative overflow-hidden rounded-xl bg-gradient-to-r from-[#e9f3ff] via-[#e6f1ff] to-[#f2f9ff]">
            <div class="flex items-center gap-4 px-8 py-7">
              <div class="min-w-0 flex-1">
                <div class="text-xs text-slate-400">品质好物 · 惊喜价格</div>
                <h1 class="mt-2 text-[30px] font-bold leading-tight text-[#1668dc]">
                  夏日焕新<span class="ml-2">精选好物</span>
                </h1>
                <p class="mt-2.5 text-xs text-slate-500">
                  全场低至 5 折起 <span class="mx-2 text-slate-300">|</span> 会员专享更多优惠
                </p>
                <button
                  class="mt-5 flex items-center gap-1.5 rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white shadow-sm transition-colors hover:bg-[#4096ff]"
                  @click="router.push('/search?sort=sales_desc')"
                >
                  立即抢购 <ArrowRight class="h-4 w-4" />
                </button>
              </div>

              <!-- 主图位 -->
              <div class="relative hidden h-[132px] w-[300px] shrink-0 items-center justify-center md:flex">
                <div class="absolute bottom-3 left-1/2 h-16 w-56 -translate-x-1/2 rounded-[50%] bg-[#d3e7ff]/70"></div>
                <img
                  v-if="heroImage" :src="heroImage" alt=""
                  class="relative h-[124px] w-[124px] rounded-2xl object-cover shadow-lg"
                />
                <div v-else class="relative flex select-none items-end gap-3 text-5xl">
                  <span>🎒</span><span class="mb-2 text-3xl">🧴</span><span class="text-3xl">🪴</span><span class="mb-1 text-2xl">☕</span>
                </div>
              </div>
            </div>
            <!-- 轮播指示点 -->
            <div class="absolute bottom-3.5 left-1/2 flex -translate-x-1/2 gap-1.5">
              <span class="h-1.5 w-4 rounded-full bg-[#1677ff]"></span>
              <span class="h-1.5 w-1.5 rounded-full bg-white"></span>
              <span class="h-1.5 w-1.5 rounded-full bg-white"></span>
            </div>
          </section>

          <!-- 服务保障条 -->
          <section class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div
              v-for="f in features" :key="f.title"
              class="flex items-center gap-2.5 rounded-lg border border-slate-100 bg-white px-3.5 py-3"
            >
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#eaf4ff] text-[#1677ff]">
                <component :is="f.icon" class="h-[18px] w-[18px]" />
              </span>
              <span class="min-w-0">
                <span class="block truncate text-[13px] font-medium text-slate-700">{{ f.title }}</span>
                <span class="mt-0.5 block truncate text-[11px] text-slate-400">{{ f.desc }}</span>
              </span>
            </div>
          </section>

          <!-- 三个专题入口 -->
          <section class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <button
              v-for="p in promos" :key="p.title"
              class="flex items-center justify-between gap-2 overflow-hidden rounded-lg px-4 py-3.5 text-left transition-shadow hover:shadow-md"
              :class="p.bg"
              @click="router.push(p.to)"
            >
              <span class="min-w-0">
                <span class="block truncate text-[15px] font-bold" :class="p.accent">{{ p.title }}</span>
                <span class="mt-1 block truncate text-[11px] text-slate-400">{{ p.desc }}</span>
                <span
                  class="mt-2.5 inline-flex items-center gap-1 rounded-full px-3 py-1 text-[11px] text-white"
                  :class="p.btn"
                >立即抢购 <ChevronRight class="h-3 w-3" /></span>
              </span>
              <span class="shrink-0 select-none text-5xl">{{ p.emoji }}</span>
            </button>
          </section>

        </div>

        <!-- ============ 右：信息栏 ============ -->
        <aside class="hidden w-[232px] shrink-0 space-y-3.5 xl:block">
          <!-- 用户卡：头像 + 个人中心按钮（替换原问候文字）+ 订单快捷入口 -->
          <div class="rounded-xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-center gap-3">
              <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#dbeafe] to-[#eaf4ff] text-lg">
                🧑
              </span>
              <button
                class="min-w-0 flex-1 rounded-md bg-[#1677ff] px-3 py-2 text-[13px] text-white transition-colors hover:bg-[#4096ff]"
                @click="router.push(auth.token ? '/account' : '/login')"
              >{{ auth.token ? '个人中心' : '立即登录' }}</button>
            </div>

            <!-- 我的订单快捷入口 -->
            <div class="mt-3 flex items-center justify-between">
              <span class="text-[13px] font-bold text-slate-800">我的订单</span>
              <RouterLink to="/orders" class="flex items-center text-[11px] text-slate-400 hover:text-[#1677ff]">
                查看全部 <ChevronRight class="h-3 w-3" />
              </RouterLink>
            </div>
            <div class="mt-2 grid grid-cols-4 gap-1">
              <RouterLink
                v-for="o in orderEntries" :key="o.label" :to="o.to"
                class="flex flex-col items-center gap-1.5 rounded-md py-1.5 text-[11px] text-slate-500 transition-colors hover:bg-[#f5faff] hover:text-[#1677ff]"
              >
                <component :is="o.icon" class="h-[18px] w-[18px]" />
                {{ o.label }}
              </RouterLink>
            </div>
          </div>

          <!-- 最新公告 -->
          <div class="rounded-xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="mb-2.5 flex items-center justify-between">
              <span class="text-[13px] font-bold text-slate-800">最新公告</span>
              <RouterLink to="/notifications" class="flex items-center text-[11px] text-slate-400 hover:text-[#1677ff]">
                更多 <ChevronRight class="h-3 w-3" />
              </RouterLink>
            </div>
            <ul class="space-y-2.5">
              <li v-for="n in notices.slice(0, 2)" :key="n.text">
                <RouterLink to="/notifications" class="group flex gap-1.5">
                  <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-[#1677ff]"></span>
                  <span class="min-w-0">
                    <span class="block truncate text-xs text-slate-600 group-hover:text-[#1677ff]">{{ n.text }}</span>
                    <span class="mt-0.5 block text-[10px] text-slate-400">{{ n.date }}</span>
                  </span>
                </RouterLink>
              </li>
            </ul>
          </div>

          <!-- 新用户福利（单行紧凑卡，高度约原设计一半） -->
          <div class="relative flex items-center gap-1.5 overflow-hidden rounded-xl bg-gradient-to-br from-[#eaf4ff] to-[#f5fbff] px-3 py-2.5">
            <span class="shrink-0 select-none text-xl leading-none">🎁</span>
            <div class="min-w-0 flex-1">
              <div class="truncate text-[13px] font-bold text-slate-700">新用户专享福利</div>
              <div class="mt-0.5 truncate text-[10px] text-slate-500">注册即送 100 元优惠券</div>
            </div>
            <button
              class="shrink-0 whitespace-nowrap rounded-full bg-[#1677ff] px-2 py-1 text-[11px] text-white transition-colors hover:bg-[#4096ff]"
              @click="router.push('/register')"
            >立即领取</button>
          </div>
        </aside>
      </div>

      <!-- ============ 整宽：热销推荐（横跨左中右，一行 6 件 × 2 行大图） ============ -->
      <section class="mt-4 pb-4">
        <div class="mb-3 flex items-center justify-between">
          <div class="flex min-w-0 items-center gap-2">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#fff1f0]">
              <Flame class="h-3.5 w-3.5 text-[#ff4d4f]" />
            </span>
            <h3 class="shrink-0 text-[15px] font-bold text-slate-800">热销推荐</h3>
            <div class="ml-1.5 flex items-center gap-1">
              <button
                v-for="t in tabs" :key="t.value"
                class="rounded-md px-2 py-0.5 text-xs transition-colors"
                :class="activeTab === t.value ? 'bg-[#eaf4ff] font-medium text-[#1677ff]' : 'text-slate-500 hover:text-[#1677ff]'"
                @click="activeTab = t.value"
              >{{ t.label }}</button>
            </div>
          </div>
          <RouterLink
            to="/search?sort=sales_desc"
            class="flex shrink-0 items-center text-xs text-slate-400 hover:text-[#1677ff]"
          >查看更多 <ChevronRight class="h-3.5 w-3.5" /></RouterLink>
        </div>

        <LoadingSpinner v-if="loading" />
        <template v-else>
          <div class="grid grid-cols-2 gap-3.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
            <ProductCard v-for="p in displayList" :key="p.id" :product="p" layout="home" :tag="activeTab === 'new' ? 'new' : 'hot'" />
          </div>
          <div v-if="!displayList.length" class="rounded-lg border border-slate-100 bg-white py-10 text-center text-sm text-slate-400">
            暂时无数据
          </div>
        </template>
      </section>

      <!-- ============ 整宽：大促双卡（横跨左中右，一行 2 张，高度为原设计一半） ============ -->
      <section class="mt-3.5 grid grid-cols-1 gap-3.5 pb-4 md:grid-cols-2">
        <button
          v-for="b in bigPromos" :key="b.title"
          class="flex items-center gap-3 overflow-hidden rounded-lg px-4 py-[18px] text-left transition-shadow hover:shadow-md"
          :class="b.bg"
          @click="router.push(b.to)"
        >
          <span class="shrink-0 select-none text-4xl">{{ b.emoji }}</span>
          <span class="min-w-0 flex-1">
            <span class="flex items-center gap-1.5">
              <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px]" :class="b.tagClass">{{ b.tag }}</span>
              <span class="truncate text-[14px] font-bold text-slate-800">{{ b.title }}</span>
            </span>
            <span class="mt-1 block truncate text-[11px] text-slate-400">{{ b.desc }}</span>
          </span>
          <span class="flex shrink-0 items-baseline gap-2">
            <b class="text-[16px] font-bold text-[#ff4d4f]"><span class="text-[11px]">¥</span>{{ b.price }}</b>
            <s class="text-[11px] text-slate-400">¥{{ b.origin }}</s>
          </span>
          <span class="shrink-0 whitespace-nowrap rounded-full border border-[#1677ff] px-3 py-1 text-[11px] text-[#1677ff]">立即抢购</span>
        </button>
      </section>
    </main>

    <ShopFooter />
  </div>
</template>
