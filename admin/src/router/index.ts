import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/login/LoginView.vue'),
      meta: { title: '登录' },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/login/RegisterView.vue'),
      meta: { title: '注册' },
    },
    {
      path: '/',
      component: () => import('@/layouts/AdminLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        { path: '', redirect: '/dashboard' },
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('@/views/dashboard/IndexView.vue'),
          meta: { title: '工作台', menu: true, icon: 'LayoutDashboard' },
        },
        {
          path: 'products',
          name: 'product-list',
          component: () => import('@/views/product/ProductListView.vue'),
          meta: { title: '商品管理 / 商品列表', menu: true, icon: 'Package', permission: 'product.view' },
        },
        {
          path: 'products/new',
          name: 'product-create',
          component: () => import('@/views/product/ProductEditView.vue'),
          meta: { title: '商品管理 / 新建商品', permission: 'product.create' },
        },
        {
          path: 'products/:id/edit',
          name: 'product-edit',
          component: () => import('@/views/product/ProductEditView.vue'),
          meta: { title: '商品管理 / 编辑商品', permission: 'product.update' },
        },
        {
          path: 'products/:id',
          name: 'product-view',
          component: () => import('@/views/product/ProductDetailView.vue'),
          meta: { title: '商品管理 / 商品详情', permission: 'product.view' },
        },
        {
          path: 'categories',
          name: 'categories',
          component: () => import('@/views/product/CategoryView.vue'),
          meta: { title: '分类管理', menu: true, icon: 'FolderTree', permission: 'category.manage' },
        },
        {
          path: 'brands',
          name: 'brands',
          component: () => import('@/views/product/BrandView.vue'),
          meta: { title: '商品管理 / 品牌管理', menu: true, icon: 'Tags', permission: 'product.view' },
        },
        {
          path: 'attributes',
          name: 'attributes',
          component: () => import('@/views/product/AttributeView.vue'),
          meta: { title: '商品管理 / 属性库', menu: true, icon: 'ListTree', permission: 'product.view' },
        },
        {
          path: 'category-attributes',
          name: 'category-attributes',
          component: () => import('@/views/product/CategoryAttributeView.vue'),
          meta: { title: '商品管理 / 分类属性模板', menu: true, icon: 'LayoutList', permission: 'product.view' },
        },
        {
          path: 'orders',
          name: 'orders',
          component: () => import('@/views/order/OrderView.vue'),
          meta: { title: '订单管理', menu: true, icon: 'ClipboardList', permission: 'order.view' },
        },
        {
          path: 'refunds',
          name: 'refunds',
          component: () => import('@/views/refund/RefundView.vue'),
          meta: { title: '退款处理', menu: true, icon: 'RotateCcw', permission: 'refund.view' },
        },
        {
          path: 'payments',
          name: 'payments',
          component: () => import('@/views/order/PaymentView.vue'),
          meta: { title: '支付管理', menu: true, icon: 'CreditCard', permission: 'payment.view' },
        },
        {
          path: 'payment-logs',
          name: 'payment-logs',
          component: () => import('@/views/order/PaymentLogView.vue'),
          meta: { title: '支付日志', menu: true, icon: 'ScrollText', permission: 'payment.view' },
        },
        {
          path: 'order-logs',
          name: 'order-logs',
          component: () => import('@/views/order/OrderLogView.vue'),
          meta: { title: '订单流水', menu: true, icon: 'History', permission: 'order.log' },
        },
        {
          path: 'reports',
          name: 'reports',
          component: () => import('@/views/operation/ReportCenterView.vue'),
          meta: { title: '报表中心', menu: true, icon: 'BarChart3', permission: 'report.view' },
        },
        {
          path: 'reviews',
          name: 'reviews',
          component: () => import('@/views/operation/ReviewView.vue'),
          meta: { title: '评价管理', menu: true, icon: 'MessageSquare', permission: 'review.manage' },
        },
        {
          path: 'users',
          name: 'users',
          component: () => import('@/views/user/UserListView.vue'),
          meta: { title: '用户管理', menu: true, icon: 'Users', permission: 'user.manage' },
        },
        {
          path: 'configs',
          name: 'configs',
          component: () => import('@/views/system/ConfigView.vue'),
          meta: { title: '系统配置', menu: true, icon: 'Settings', permission: 'config.manage' },
        },
        {
          path: 'operation-logs',
          name: 'operation-logs',
          component: () => import('@/views/system/OperationLogView.vue'),
          meta: { title: '操作日志', menu: true, icon: 'FileClock', permission: 'log.view' },
        },
        {
          path: 'accounts',
          name: 'accounts',
          component: () => import('@/views/system/AccountView.vue'),
          meta: { title: '管理员账号', menu: true, icon: 'UserCog', permission: 'account.manage' },
        },
        {
          path: 'roles',
          name: 'roles',
          component: () => import('@/views/system/RoleView.vue'),
          meta: { title: '角色权限', menu: true, icon: 'ShieldCheck', permission: 'role.manage' },
        },
        {
          path: 'profile',
          name: 'profile',
          component: () => import('@/views/user/ProfileView.vue'),
          meta: { title: '个人中心' },
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/error/NotFoundView.vue'),
    },
  ],
})

/** 路由守卫：未登录跳转 /login；已登录访问登录页跳工作台 */
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && !auth.token) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // 已登录但内存中无用户信息（刷新页面场景）：拉取一次
  if (auth.token && !auth.user) {
    try {
      await auth.fetchUser()
    } catch {
      // 拉取失败（Token 失效或服务异常）：先清登录态再跳登录页，
      // 否则 login 页守卫会因 token 仍存在而弹回工作台，形成重定向死循环
      auth.clearToken()
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }

  // 页面级权限校验
  if (to.meta.permission && !auth.hasPermission(to.meta.permission as string)) {
    return { name: 'not-found' }
  }

  if (auth.token && to.name === 'login') {
    return { path: '/dashboard' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · CubeShop` : 'CubeShop 管理端'
})

export default router
