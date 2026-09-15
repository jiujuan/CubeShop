import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('@/views/HomeView.vue'),
      meta: { title: 'CubeShop - 品质好物 · 购物无' },
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
      path: '/account/addresses',
      name: 'addresses',
      component: () => import('@/views/AddressView.vue'),
      meta: { title: '收货地址 · CubeShop' },
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

/** 已登录访问登录/注册页 → 回首页 */
router.beforeEach((to) => {
  const auth = useAuthStore()
  if (auth.token && (to.name === 'login' || to.name === 'register')) {
    return { path: '/' }
  }
  return true
})

router.afterEach((to) => {
  document.title = (to.meta.title as string) ?? 'CubeShop'
})

export default router
