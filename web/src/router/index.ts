import { createRouter, createWebHistory } from 'vue-router'

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
      redirect: '/',
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

router.afterEach((to) => {
  document.title = (to.meta.title as string) ?? 'CubeShop'
})

export default router
