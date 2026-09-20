import { createRouter, createWebHistory } from 'vue-router'
import { resetSeo } from '@/composables/useSeo'
import { useAuthStore } from '@/stores/auth'
import { setTitleBase } from '@/stores/site'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('@/views/HomeView.vue'),
      meta: { title: 'CubeShop - 品质好物 · 购物无忧' },
    },
    {
      path: '/category/:id',
      name: 'category',
      component: () => import('@/views/BrowseView.vue'),
      meta: { title: '商品分类 · CubeShop' },
    },
    {
      path: '/search',
      name: 'search',
      component: () => import('@/views/BrowseView.vue'),
      meta: { title: '搜索结果 · CubeShop' },
    },
    {
      path: '/product/:id',
      name: 'product-detail',
      component: () => import('@/views/DetailView.vue'),
      meta: { title: '商品详情 · CubeShop' },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { title: '登录 · CubeShop' },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { title: '注册 · CubeShop' },
    },
    {
      path: '/cart',
      name: 'cart',
      component: () => import('@/views/CartView.vue'),
      meta: { title: '购物车 · CubeShop' },
    },
    {
      path: '/checkout',
      name: 'checkout',
      component: () => import('@/views/CheckoutView.vue'),
      meta: { title: '确认订单 · CubeShop', requiresAuth: true },
    },
    {
      path: '/orders',
      name: 'orders',
      component: () => import('@/views/OrderListView.vue'),
      meta: { title: '我的订单 · CubeShop', requiresAuth: true },
    },
    {
      path: '/orders/:id',
      name: 'order-detail',
      component: () => import('@/views/OrderDetailView.vue'),
      meta: { title: '订单详情 · CubeShop', requiresAuth: true },
    },
    {
      path: '/orders/:id/pay',
      name: 'order-pay',
      component: () => import('@/views/PayView.vue'),
      meta: { title: '收银台 · CubeShop', requiresAuth: true },
    },
    {
      path: '/orders/:id/refund',
      name: 'order-refund',
      component: () => import('@/views/RefundApplyView.vue'),
      meta: { title: '申请退款 · CubeShop', requiresAuth: true },
    },
    {
      path: '/orders/:id/cancel',
      name: 'order-cancel',
      component: () => import('@/views/OrderCancelView.vue'),
      meta: { title: '取消订单 · CubeShop', requiresAuth: true },
    },
    {
      path: '/pay/result/:payment_no',
      name: 'pay-result',
      component: () => import('@/views/PayResultView.vue'),
      meta: { title: '支付结果 · CubeShop', requiresAuth: true },
    },
    {
      path: '/balance/recharge',
      name: 'balance-recharge',
      component: () => import('@/views/BalanceRechargeView.vue'),
      meta: { title: '余额充值 · CubeShop', requiresAuth: true },
    },
    {
      path: '/coupons/center',
      name: 'coupon-center',
      component: () => import('@/views/CouponCenterView.vue'),
      meta: { title: '领券中心 · CubeShop' },
    },
    {
      path: '/coupons/mine',
      name: 'my-coupons',
      component: () => import('@/views/MyCouponsView.vue'),
      meta: { title: '我的优惠券 · CubeShop', requiresAuth: true },
    },
    {
      path: '/account',
      name: 'account',
      component: () => import('@/views/AccountCenterView.vue'),
      meta: { title: '个人中心 · CubeShop', requiresAuth: true },
    },
    {
      path: '/account/favorites',
      name: 'favorites',
      component: () => import('@/views/FavoriteView.vue'),
      meta: { title: '我的收藏 · CubeShop', requiresAuth: true },
    },
    {
      path: '/account/histories',
      name: 'histories',
      component: () => import('@/views/HistoryView.vue'),
      meta: { title: '浏览足迹 · CubeShop', requiresAuth: true },
    },
    {
      path: '/account/addresses',
      name: 'addresses',
      component: () => import('@/views/AddressView.vue'),
      meta: { title: '收货地址 · CubeShop', requiresAuth: true },
    },
    {
      path: '/notifications',
      name: 'notifications',
      component: () => import('@/views/NotificationsView.vue'),
      meta: { title: '消息通知 · CubeShop', requiresAuth: true },
    },
    {
      path: '/announcements',
      name: 'announcements',
      component: () => import('@/views/AnnouncementListView.vue'),
      meta: { title: '公告 · CubeShop' },
    },
    {
      path: '/announcements/:id',
      name: 'announcement-detail',
      component: () => import('@/views/AnnouncementDetailView.vue'),
      meta: { title: '公告详情 · CubeShop' },
    },
    {
      path: '/service-center',
      name: 'service-center',
      component: () => import('@/views/ServiceCenterView.vue'),
      meta: { title: '服务中心 · CubeShop', requiresAuth: true },
    },
    {
      path: '/service-center/faq',
      name: 'faq-category',
      component: () => import('@/views/FaqCategoryView.vue'),
      // 决策 D4：帮助中心解除登录（公开内容，与 /cms/* 接口一致）
      meta: { title: '帮助中心 · CubeShop' },
    },
    {
      path: '/service-center/faq/list',
      name: 'faq-list',
      component: () => import('@/views/FaqListView.vue'),
      meta: { title: '帮助中心 · CubeShop' },
    },
    {
      path: '/service-center/faq/:id',
      name: 'faq-detail',
      component: () => import('@/views/FaqDetailView.vue'),
      meta: { title: '帮助中心 · CubeShop' },
    },
    {
      path: '/service-center/tickets',
      name: 'ticket-list',
      component: () => import('@/views/TicketListView.vue'),
      meta: { title: '我的工单 · CubeShop', requiresAuth: true },
    },
    {
      path: '/service-center/tickets/new',
      name: 'ticket-create',
      component: () => import('@/views/TicketCreateView.vue'),
      meta: { title: '提交工单 · CubeShop', requiresAuth: true },
    },
    {
      path: '/service-center/tickets/:id',
      name: 'ticket-detail',
      component: () => import('@/views/TicketDetailView.vue'),
      meta: { title: '工单详情 · CubeShop', requiresAuth: true },
    },
    {
      // CMS 站点单页（关于我们 / 联系我们…），模板由后端 CmsPageTemplate 决定；
      // 公开可访问（决策 D4），必须放在 catch-all 之前。标题在页面加载后按页面名重设。
      path: '/p/:slug',
      name: 'page',
      component: () => import('@/views/PageView.vue'),
      meta: { title: '页面 · CubeShop' },
    },
    {
      // CMS 新闻中心：列表（公开，决策 D4 同口径）。必须放在 catch-all 之前
      path: '/news',
      name: 'news-list',
      component: () => import('@/views/NewsListView.vue'),
      meta: { title: '新闻中心 · CubeShop' },
    },
    {
      // 后期增强：标签专题页。必须放在 /news/:id 之前，避免被详情路由吞掉
      path: '/news/tag/:tag',
      name: 'news-tag',
      component: () => import('@/views/NewsTagView.vue'),
      meta: { title: '新闻专题 · CubeShop' },
    },
    {
      // CMS 新闻中心：详情（公开；图文/列表两形态由后端栏目 list_style 决定）
      // {id} 为 slug 或数字 id（后期增强 §7：slug 语义化 URL，id 兜底）
      path: '/news/:id',
      name: 'news-detail',
      component: () => import('@/views/NewsDetailView.vue'),
      meta: { title: '新闻详情 · CubeShop' },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
  scrollBehavior() {
    return { top: 0 }
  },
})

/** 未登录访问受保护页 → 跳登录并带 redirect；已登录访问登录/注册页 → 回首页 */
router.beforeEach((to) => {
  const auth = useAuthStore()
  if (auth.token && (to.name === 'login' || to.name === 'register')) {
    return { path: '/' }
  }
  if (to.meta.requiresAuth && !auth.token) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }
  return true
})

router.afterEach((to) => {
  // meta.title 里的品牌名统一写作占位符 CubeShop，运行时按后台配置的站点名替换
  setTitleBase((to.meta.title as string) ?? undefined)
  // CMS-202：清掉上一页写的 meta，避免 description/keywords 残留在新页面上；
  // 页面拿到自己的数据后会用 applySeo() 写入（见 composables/useSeo.ts）
  resetSeo()
})

export default router
