import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

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
  document.title = (to.meta.title as string) ?? 'CubeShop'
})

export default router
