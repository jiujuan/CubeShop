import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * 登录后的落地入口（也用于「无工作台权限时的兜底跳转」）。
 *
 * 各角色权限差异较大：客服（cs_agent）只持有 cs.*，**没有 dashboard.view**
 * （工作台含今日销售额等经营数据），若一律跳 /dashboard 会直接看到 403 空页。
 * 故按「首个有权限的入口」依次回退，顺序与 AdminLayout 的菜单分组保持一致；
 * 新增后台角色时在此追加一行即可。
 */
export function landingPath(): string {
  const auth = useAuthStore()

  const candidates: Array<[permission: string, path: string]> = [
    ['dashboard.view', '/dashboard'],
    ['order.view', '/orders'],
    ['product.view', '/products'],
    ['cs.ticket.view', '/cs/tickets'],
    ['cs.faq.manage', '/cs/faq'],
  ]

  return candidates.find(([permission]) => auth.hasPermission(permission))?.[1] ?? '/dashboard'
}

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
        { path: '', redirect: () => ({ path: landingPath() }) },
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
          path: 'category-brands',
          name: 'category-brands',
          component: () => import('@/views/product/CategoryBrandView.vue'),
          meta: { title: '商品管理 / 分类可选品牌', menu: true, icon: 'Layers', permission: 'product.view' },
        },
        {
          path: 'orders',
          name: 'orders',
          component: () => import('@/views/order/OrderView.vue'),
          meta: { title: '订单管理', menu: true, icon: 'ClipboardList', permission: 'order.view' },
        },
        {
          path: 'orders/:id',
          name: 'order-detail',
          component: () => import('@/views/order/OrderDetailView.vue'),
          meta: { title: '订单详情', permission: 'order.view' },
        },
        {
          path: 'batch-ship',
          name: 'batch-ship',
          component: () => import('@/views/order/BatchShipView.vue'),
          meta: { title: '批量发货', menu: true, icon: 'UploadCloud', permission: 'order.ship' },
        },
        {
          path: 'shipping-monitor',
          name: 'shipping-monitor',
          component: () => import('@/views/order/ShippingMonitorView.vue'),
          meta: { title: '物流监控', menu: true, icon: 'MapPinned', permission: 'order.view' },
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
          path: 'balance-recharges',
          name: 'balance-recharges',
          component: () => import('@/views/order/BalanceRechargeView.vue'),
          meta: { title: '余额充值单', menu: true, icon: 'Wallet', permission: 'balance.recharge.view' },
        },
        {
          path: 'payment-channels',
          name: 'payment-channels',
          component: () => import('@/views/system/PaymentChannelView.vue'),
          meta: { title: '支付渠道配置', menu: true, icon: 'CreditCard', permission: 'payment.channel.manage' },
        },
        {
          path: 'shipping-companies',
          name: 'shipping-companies',
          component: () => import('@/views/order/ExpressCompanyView.vue'),
          meta: { title: '快递公司字典', menu: true, icon: 'Truck', permission: 'shipping.manage' },
        },
        {
          path: 'freight-templates',
          name: 'freight-templates',
          component: () => import('@/views/system/FreightTemplateListView.vue'),
          meta: { title: '运费模板', menu: true, icon: 'Calculator', permission: 'shipping.manage' },
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
          path: 'marketing',
          name: 'marketing',
          component: () => import('@/views/operation/MarketingView.vue'),
          meta: { title: '营销管理', menu: true, icon: 'Ticket', permission: 'marketing.manage' },
        },
        // CMS-204：公告已并入内容中心（内容管理 → 「公告」栏目），独立公告管理页摘除。
        // AnnouncementListView.vue 与后端 /api/admin/announcements 一并保留一个版本，
        // 只是不再有入口 —— 直接访问 /announcements 会落到 404 兜底。
        {
          path: 'home-banners',
          name: 'home-banners',
          component: () => import('@/views/operation/HomeBannerListView.vue'),
          meta: { title: '首页广告位', menu: true, icon: 'Images', permission: 'home.manage' },
        },
        {
          // 前台顶部导航编排（商品分类引用 / 自定义链接，位置由 sort 决定）
          path: 'nav',
          name: 'nav',
          component: () => import('@/views/site/NavView.vue'),
          meta: { title: '导航管理', menu: true, icon: 'Menu', permission: 'nav.manage' },
        },
        {
          path: 'wms/fulfillment-orders',
          name: 'wms-fulfillment-orders',
          component: () => import('@/views/wms/FulfillmentOrderListView.vue'),
          meta: { title: '履约中心 / 发货单', menu: true, icon: 'Truck', permission: 'wms.order.view' },
        },
        {
          path: 'wms/return-inbound-orders',
          name: 'wms-return-inbound-orders',
          component: () => import('@/views/wms/ReturnInboundOrderListView.vue'),
          meta: { title: '履约中心 / 退货入库单', menu: true, icon: 'Undo2', permission: 'wms.return.manage' },
        },
        {
          path: 'wms/inventory-diffs',
          name: 'wms-inventory-diffs',
          component: () => import('@/views/wms/WmsInventoryDiffView.vue'),
          meta: { title: '履约中心 / 库存差异', menu: true, icon: 'Diff', permission: 'wms.config.manage' },
        },
        {
          path: 'wms/logs',
          name: 'wms-logs',
          component: () => import('@/views/wms/WmsApiLogView.vue'),
          meta: { title: '履约中心 / WMS 日志', menu: true, icon: 'ScrollText', permission: 'wms.config.manage' },
        },
        {
          path: 'wms/health',
          name: 'wms-health',
          component: () => import('@/views/wms/WmsHealthView.vue'),
          meta: { title: '履约中心 / 健康看板', menu: true, icon: 'Activity', permission: 'wms.config.manage' },
        },
        {
          path: 'wms/warehouses',
          name: 'wms-warehouses',
          component: () => import('@/views/wms/WarehouseListView.vue'),
          meta: { title: '仓库与物流 / WMS 对接', menu: true, icon: 'Warehouse', permission: 'wms.config.manage' },
        },
        {
          path: 'wms/warehouses/:id/config',
          name: 'wms-warehouse-config',
          component: () => import('@/views/wms/WmsConfigView.vue'),
          meta: { title: '仓库与物流 / WMS 对接配置', permission: 'wms.config.manage' },
        },
        {
          path: 'wms/warehouses/:id/mappings',
          name: 'wms-warehouse-mappings',
          component: () => import('@/views/wms/WmsSkuMappingView.vue'),
          meta: { title: '仓库与物流 / SKU 映射', permission: 'wms.config.manage' },
        },
        {
          path: 'cs/tickets',
          name: 'cs-tickets',
          component: () => import('@/views/cs/CsTicketView.vue'),
          meta: { title: '服务工单', menu: true, icon: 'LifeBuoy', permission: 'cs.ticket.view' },
        },
        {
          path: 'cs/faq',
          name: 'cs-faq',
          component: () => import('@/views/cs/CsFaqView.vue'),
          meta: { title: '内容管理', menu: true, icon: 'BookOpen', permission: 'cs.faq.manage' },
        },
        {
          // 文章新增/编辑是独立页面（不再是列表页侧边弹层）：
          // 字段多（正文 + SEO + 关联商品），抽屉放不下，独立页才能两列排布
          path: 'cs/faq/articles/new',
          name: 'cs-faq-article-create',
          component: () => import('@/views/cs/CsFaqArticleEditView.vue'),
          meta: { title: '内容管理 / 新增文章', permission: 'cs.faq.manage' },
        },
        {
          path: 'cs/faq/articles/:id/edit',
          name: 'cs-faq-article-edit',
          component: () => import('@/views/cs/CsFaqArticleEditView.vue'),
          meta: { title: '内容管理 / 编辑文章', permission: 'cs.faq.manage' },
        },
        {
          path: 'cs/quick-replies',
          name: 'cs-quick-replies',
          component: () => import('@/views/cs/CsQuickReplyView.vue'),
          meta: { title: '快捷回复', menu: true, icon: 'MessageSquare', permission: 'cs.faq.manage' },
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
          // 媒体库（图片资产治理 P2）：浏览/复用/替换/回收候选清单
          path: 'media',
          name: 'media',
          component: () => import('@/views/system/MediaLibraryView.vue'),
          meta: { title: '媒体库', menu: true, icon: 'Images', permission: 'media.view' },
        },
        {
          // 搜索配置（V1.2 站内搜索 S1-09）：引擎/开关/热搜词/重建索引
          // 超管专属（search.manage）：切引擎会整体改变全站检索行为，与 media.manage 同体例
          path: 'search-config',
          name: 'search-config',
          component: () => import('@/views/system/SearchConfigView.vue'),
          meta: { title: '搜索配置', menu: true, icon: 'Search', permission: 'search.manage' },
        },
        {
          path: 'operation-logs',
          name: 'operation-logs',
          component: () => import('@/views/system/OperationLogView.vue'),
          meta: { title: '操作日志', menu: true, icon: 'FileClock', permission: 'log.view' },
        },
        {
          path: 'auth-logs',
          name: 'auth-logs',
          component: () => import('@/views/system/AuthLogView.vue'),
          meta: { title: '认证日志', menu: true, icon: 'KeyRound', permission: 'log.auth.view' },
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

  // 工作台权限未写进路由 meta（接口层 dashboard.view 校验），这里补一道兜底：
  // 无权限时不展示空页，直接落到自己的首个可用入口（如客服 → /cs/tickets）
  if (auth.token && to.name === 'dashboard' && !auth.hasPermission('dashboard.view')) {
    const landing = landingPath()
    if (landing !== to.path) {
      return { path: landing }
    }
  }

  if (auth.token && to.name === 'login') {
    return { path: landingPath() }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · CubeShop` : 'CubeShop 管理端'
})

export default router
