<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Admin\AttributeController as AdminAttributeController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryAttributeController;
use App\Http\Controllers\Admin\CategoryBrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\AddressController as AdminAddressController;
use App\Http\Controllers\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\AuthLogController;
use App\Http\Controllers\Admin\ConfigController;
use App\Http\Controllers\Admin\HomeBannerController as AdminHomeBannerController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\NavItemController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OperationLogController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\OrderLogController as AdminOrderLogController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PaymentReconcileController as AdminPaymentReconcileController;
use App\Http\Controllers\Admin\PaymentChannelController as AdminPaymentChannelController;
use App\Http\Controllers\Admin\PaymentLogController as AdminPaymentLogController;
use App\Http\Controllers\Admin\BalanceRechargeController as AdminBalanceRechargeController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Admin\RefundController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Admin\SearchController as AdminSearchController;
use App\Http\Controllers\Admin\SearchSynonymController as AdminSearchSynonymController;
use App\Http\Controllers\Admin\SmsConfigController as AdminSmsConfigController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WmsConfigController;
use App\Http\Controllers\Admin\WmsApiLogController;
use App\Http\Controllers\Admin\WmsConsoleController;
use App\Http\Controllers\Admin\WmsFulfillmentController;
use App\Http\Controllers\Admin\WmsInventoryController;
use App\Http\Controllers\Admin\WmsReturnInboundController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Storefront\PaymentReconcileDashboardController as StorefrontPaymentReconcileDashboardController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\HomeBannerController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\Admin\CsFaqController as AdminCsFaqController;
use App\Http\Controllers\Admin\CsQuickReplyController as AdminCsQuickReplyController;
use App\Http\Controllers\Admin\CsTicketController as AdminCsTicketController;
use App\Http\Controllers\CmsController;
use App\Http\Controllers\CsFaqController;
use App\Http\Controllers\CsTicketController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Payment\RefundNotifyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\Storefront\AttributeController as StorefrontAttributeController;
use App\Http\Controllers\Storefront\CouponController as StorefrontCouponController;
use App\Http\Controllers\Storefront\PromotionController as StorefrontPromotionController;
use App\Http\Controllers\Storefront\NavController as StorefrontNavController;
use App\Http\Controllers\Storefront\NewsController as StorefrontNewsController;
use App\Http\Controllers\Storefront\ProductController as StorefrontProductController;
use App\Http\Controllers\Storefront\SearchController as StorefrontSearchController;
use App\Http\Controllers\Storefront\SiteController as StorefrontSiteController;
use App\Http\Controllers\Wms\WmsCallbackController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 公开接口（无需认证）
|--------------------------------------------------------------------------
*/
Route::get('/health', [HealthController::class, 'index']);

// 前台商品模块（API 文档 4，无需登录）
Route::prefix('products')->group(function () {
    Route::get('/', [StorefrontProductController::class, 'index']);
    Route::get('/categories', [StorefrontProductController::class, 'categories']);
    Route::get('/hot', [StorefrontProductController::class, 'hot']);
    // 首页推荐（P-HomeRecommend，无需登录）；必须在 /{id} 之前声明
    Route::get('/recommended', [StorefrontProductController::class, 'recommended']);
    // V1.1 F01（T-015）：商品评价列表与汇总（匿名可访问）
    Route::get('/{id}/reviews', [ReviewController::class, 'productReviews']);
    Route::get('/{id}', [StorefrontProductController::class, 'show']);
});

// 站内搜索（V1.2 S1-07，无需登录）：/search 为新增富入口，/products 保留原参数语义
// ⚠️ /suggest 与 /hot 必须显式声明，避免将来加 /{keyword} 之类的通配时把它们吃掉
Route::prefix('search')->group(function () {
    Route::get('/', [StorefrontSearchController::class, 'index']);
    // 联想防刷：同 IP 每分钟 30 次（SEARCH_SUGGEST_RATE_LIMIT 可配，见 config/services.php）
    Route::get('/suggest', [StorefrontSearchController::class, 'suggest'])->middleware('throttle:search-suggest');
    Route::get('/hot', [StorefrontSearchController::class, 'hot']);
});

// 前台顶部导航（后台「导航管理」编排，公开无需登录）
Route::get('/nav', [StorefrontNavController::class, 'index']);

// 前台品牌与属性（V1.1 E01 / T-008，无需登录）
Route::get('/attributes', [StorefrontAttributeController::class, 'index']);
Route::get('/brands', [StorefrontAttributeController::class, 'brands']);

// 行政区划（V1.1 E04 / T-028，无需登录，可缓存）
Route::get('/regions', [AddressController::class, 'regions']);
Route::get('/regions/provinces', [AddressController::class, 'provinces']);

// 领券中心（V1.1 二期 F06 / T-033，无需登录；登录后附带个人领取状态）
Route::get('/coupons', [StorefrontCouponController::class, 'center']);

// 公告（P-Announcement，公开无需登录：首页公告位与公告页消费）
Route::get('/announcements', [AnnouncementController::class, 'index']);
Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);

// 首页广告位（P-HomeBanner，公开无需登录：首页轮播图/中部广告/底部广告消费）
Route::get('/banners', [HomeBannerController::class, 'index']);

// 站点基础信息（P-SiteConfig，公开无需登录：前端顶栏/登录页/页脚/文档标题消费）
Route::get('/site/config', [StorefrontSiteController::class, 'show']);

// 运费预估（T-053 Stage 3，公开无需登录：详情页/购物车运费预估；登录后传 address_id 可按省精确计算）
Route::post('/freight/estimate', [OrderController::class, 'freightPreview'])->middleware('throttle:60,1');

// WMS 回传入口（WMS 计划 P3 / F1~F4，公开无认证：验签 + IP 白名单 + 防重放由服务层负责；
// 命名限流 wms-callback 120 次/分钟防洪水；响应恒 HTTP 200，语义在 flag 字段）
Route::post('/wms/callback/{provider}', [WmsCallbackController::class, 'handle'])
    ->middleware('throttle:wms-callback');

// 认证：注册 / 登录 / 验证码 / 重置密码（带限流）
// 验证码图片单独限流（throttle:captcha）：刷新验证码不该占用登录/注册的额度
Route::post('/auth/captcha', [AuthController::class, 'captcha'])->middleware('throttle:captcha');

// 认证：注册 / 登录 / 重置密码（带限流）
Route::middleware('throttle:auth')->group(function () {
    // 短信验证码：查询当前场景用哪种验证码 / 发送短信验证码（发码按条计费，必须限流）
    Route::get('/auth/verify-mode', [AuthController::class, 'verifyMode']);
    Route::post('/auth/send-sms-code', [AuthController::class, 'sendSmsCode'])->middleware('throttle:sms-send');
    // SEC-08：注册单独收紧为 5 次/分钟（auth 组是 10 次/分钟），叠加服务层同 IP 每日上限
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

// 内容中心 CMS（CMS-106）：栏目树、单页与帮助中心文章均为**公开只读**（决策 D4 解除登录）
// —— 站点单页（关于我们/联系我们）与帮助中心都属于"未登录也要能看"的内容
Route::prefix('cs')->group(function () {
    Route::get('/faq/categories', [CsFaqController::class, 'categories']);
    Route::get('/faq/articles', [CsFaqController::class, 'articles']);
    Route::get('/faq/articles/{id}', [CsFaqController::class, 'detail']);
});

Route::prefix('cms')->group(function () {
    // 导航用栏目树（show_in_nav=true）
    Route::get('/nav', [CmsController::class, 'nav']);
    // 指定父下的栏目树（帮助中心侧栏）
    Route::get('/categories', [CmsController::class, 'categories']);
    // 单页内容（/p/{slug}）——对外以 slug 标识，不暴露 id
    Route::get('/pages/{slug}', [CmsController::class, 'page']);
});

// 新闻中心（CMS 新闻中心，公开无需登录：前台 /news 消费，且需被搜索引擎抓取）
Route::prefix('news')->group(function () {
    Route::get('/channels', [StorefrontNewsController::class, 'channels']);
    Route::get('/articles', [StorefrontNewsController::class, 'articles']);
    // 后期增强：标签聚合 / 热门排行 / 商品种草反查（静态路径都排在 /articles/{key} 之前以免被吞）
    Route::get('/tags', [StorefrontNewsController::class, 'tags']);
    Route::get('/hot', [StorefrontNewsController::class, 'hot']);
    Route::get('/by-product/{id}', [StorefrontNewsController::class, 'byProduct']);
    // 详情必须注册在列表之后，避免 /articles/{key} 抢走 /articles；{key} 为 slug 或 id
    Route::get('/articles/{key}', [StorefrontNewsController::class, 'detail']);
});

/*
|--------------------------------------------------------------------------
| 认证后接口
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/password', [AuthController::class, 'changePassword']);
    // SEC-06：Token 有效期配套能力——设备列表 / 踢下线 / 轮换
    Route::get('/auth/devices', [AuthController::class, 'devices']);
    Route::delete('/auth/devices/{id}', [AuthController::class, 'revokeDevice']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // 前台对账看板（A7 增强）：仅运营可见只读汇总；买家账号（无 spatie）被权限中间件拒绝 403
    Route::get('/payment-reconcile/dashboard', [StorefrontPaymentReconcileDashboardController::class, 'summary'])
        ->middleware('permission:payment.reconcile.view');

    // 个人中心（API 文档 3.1 / 3.2 / 11.5）
    Route::get('/user/profile', [ProfileController::class, 'show']);
    Route::put('/user/profile', [ProfileController::class, 'update']);
    Route::post('/user/upload', [ProfileController::class, 'upload']);

    // 购物车（API 文档 5）
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart', [CartController::class, 'store']);
    Route::put('/cart/{id}', [CartController::class, 'update']);
    Route::delete('/cart', [CartController::class, 'clear']);
    Route::delete('/cart/{id}', [CartController::class, 'destroy']);
    Route::get('/cart/count', [CartController::class, 'count']);

    // 订单（API 文档 6 / Roadmap P4）
    Route::get('/orders/by-no/{orderNo}', [OrderController::class, 'showByNo']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:order');
    // 运费实时预览（T-053 Stage 2：结算页选地址后调用，与下单同一套引擎；须在 {id} 路由前）
    Route::post('/orders/freight-preview', [OrderController::class, 'freightPreview']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::get('/orders/{id}/shipping', [OrderController::class, 'shipping']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{id}/confirm', [OrderController::class, 'confirm']);
    Route::post('/orders/{id}/rebuy', [OrderController::class, 'rebuy']);
    Route::post('/orders/{id}/refund', [OrderController::class, 'refund']);

    // 评价（V1.1 F01 / T-015）
    Route::post('/orders/{orderId}/items/{itemId}/review', [OrderController::class, 'review']);
    Route::put('/reviews/{id}', [ReviewController::class, 'update']);
    Route::get('/me/reviews', [ReviewController::class, 'myReviews']);

    // 优惠券（V1.1 二期 F06 / T-033）—— available 需先于 {id} 注册
    Route::get('/coupons/available', [StorefrontCouponController::class, 'available']);
    Route::post('/coupons/{id}/receive', [StorefrontCouponController::class, 'receive'])->middleware('throttle:coupon');
    Route::get('/me/coupons', [StorefrontCouponController::class, 'my']);

    // 满减预览（V1.1 二期 F06 / T-039 结算页实时明细）
    Route::get('/promotions/preview', [StorefrontPromotionController::class, 'preview']);

    // 站内通知（V1.1 F02 / T-018 / T-019）
    Route::get('/me/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/me/notifications', [NotificationController::class, 'index']);
    Route::post('/me/notifications/read', [NotificationController::class, 'markRead']);

    // 收藏与浏览足迹（V1.1 F05 / T-024）
    Route::post('/products/{id}/favorite', [FavoriteController::class, 'store']);
    Route::delete('/products/{id}/favorite', [FavoriteController::class, 'destroy']);
    Route::post('/products/{id}/track', [FavoriteController::class, 'track']);
    Route::get('/me/favorites', [FavoriteController::class, 'index']);
    Route::post('/me/favorites/batch-remove', [FavoriteController::class, 'batchRemove']);
    Route::get('/me/histories', [FavoriteController::class, 'histories']);
    Route::delete('/me/histories', [FavoriteController::class, 'clearHistories']);

    // 支付（API 文档 7 / Roadmap P5）
    Route::post('/payments', [PaymentController::class, 'store']);
    // 收银台渠道列表（必须注册在 /payments/{paymentNo} 之前，避免被捕获）
    Route::get('/payments/channels', [PaymentController::class, 'channels']);
    Route::get('/payments/{paymentNo}', [PaymentController::class, 'show']);
    // 主动查单补偿（结果页轮询兜底，限流 10/min）
    Route::post('/payments/{paymentNo}/sync', [PaymentController::class, 'sync'])->middleware('throttle:sync');

    // 收货地址（API 文档 3.3 ~ 3.7 / V1.1 E04 T-028 解析）
    Route::get('/user/addresses', [AddressController::class, 'index']);
    Route::post('/user/addresses', [AddressController::class, 'store']);
    Route::post('/user/addresses/parse', [AddressController::class, 'parse']);
    Route::put('/user/addresses/{id}', [AddressController::class, 'update']);
    Route::post('/user/addresses/{id}/default', [AddressController::class, 'setDefault']);
    Route::delete('/user/addresses/{id}', [AddressController::class, 'destroy']);

    // 线下转账凭证上传（需登录，限流 30/min）
    Route::post('/user/upload-voucher', [PaymentController::class, 'uploadVoucher'])->middleware('throttle:voucher');

    // 余额与充值（收银台方案 §6.5 / Roadmap P6）
    Route::get('/user/balance', [BalanceController::class, 'show']);
    Route::get('/user/balance/recharges', [BalanceController::class, 'recharges']);
    Route::post('/user/balance/recharges', [BalanceController::class, 'storeRecharge'])->middleware('throttle:recharge');
    Route::get('/user/balance/logs', [BalanceController::class, 'logs']);

    // 客户服务中心（CS-106 工单用户端；CS-107 上传）
    // 注：FAQ 三个读接口已移到上方公开分组（CMS-106 决策 D4），此处只留写操作与工单
    Route::prefix('cs')->group(function () {
        // 帮助中心反馈（写操作，仍需登录）
        Route::post('/faq/articles/{id}/feedback', [CsFaqController::class, 'feedback']);

        // 工单（CS-106）：建单与回复加 10/min 限流
        Route::get('/ticket-types', [CsTicketController::class, 'ticketTypes']);
        Route::post('/tickets', [CsTicketController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/tickets', [CsTicketController::class, 'index']);
        Route::get('/tickets/{id}', [CsTicketController::class, 'show']);
        Route::post('/tickets/{id}/messages', [CsTicketController::class, 'messages'])->middleware('throttle:10,1');
        Route::post('/tickets/{id}/close', [CsTicketController::class, 'close']);

        // 图片上传（CS-107）
        Route::post('/upload-image', [CsTicketController::class, 'uploadImage'])->middleware('throttle:30,1');
    });

    // 后台管理：需要登录 + 对应权限码（API 文档 8）
    Route::prefix('admin')->group(function () {
        // 系统配置 config.manage
        Route::get('/configs', [ConfigController::class, 'index'])->middleware('permission:config.manage');
        Route::put('/configs', [ConfigController::class, 'update'])->middleware('permission:config.manage');
        // 站点 logo 上传（独立于 /admin/upload，落在 uploads/site 目录）
        Route::post('/configs/upload', [ConfigController::class, 'upload'])->middleware('permission:config.manage');

        // 操作日志 log.view
        Route::get('/operation-logs', [OperationLogController::class, 'index'])->middleware('permission:log.view');

        // 认证日志 log.auth.view（登录/注册/登出，含失败明细）
        Route::get('/auth-logs', [AuthLogController::class, 'index'])->middleware('permission:log.auth.view');
        Route::get('/auth-logs/{id}', [AuthLogController::class, 'show'])->middleware('permission:log.auth.view');

        // 图片上传（登录即可，商品相关权限在业务层校验）
        Route::post('/upload', [UploadController::class, 'store']);

        // 站内搜索配置（V1.2 站内搜索 S1-08，权限 search.manage）
        // ⚠️ 超管专属：切引擎 / 改开关会改变全站检索行为，与 media.manage 同体例。
        // 改开关后索引内容可能不再匹配（如 index_taxonomy_names），故保留 /reindex 手动重建入口。
        Route::prefix('search')->middleware('permission:search.manage')->group(function () {
            Route::get('/config', [AdminSearchController::class, 'config']);
            Route::put('/config', [AdminSearchController::class, 'updateConfig']);
            Route::get('/keywords', [AdminSearchController::class, 'keywords']);
            Route::post('/reindex', [AdminSearchController::class, 'reindex']);

            // 同义词（V1.2 S1-10 补做，设计 §4.8）：零结果展开规则的维护
            Route::get('/synonyms', [AdminSearchSynonymController::class, 'index']);
            Route::post('/synonyms', [AdminSearchSynonymController::class, 'store']);
            Route::put('/synonyms/{id}', [AdminSearchSynonymController::class, 'update'])->whereNumber('id');
            Route::delete('/synonyms/{id}', [AdminSearchSynonymController::class, 'destroy'])->whereNumber('id');
        });

        // 媒体库（图片资产治理 P2，权限 media.*）—— replace 必须注册在 {id} 之前
        Route::get('/media', [MediaController::class, 'index'])->middleware('permission:media.view');
        Route::post('/media', [MediaController::class, 'store'])->middleware('permission:media.upload');
        Route::patch('/media/{id}', [MediaController::class, 'update'])->middleware('permission:media.manage')->whereNumber('id');
        Route::post('/media/{id}/replace', [MediaController::class, 'replace'])->middleware('permission:media.manage')->whereNumber('id');
        Route::delete('/media/{id}', [MediaController::class, 'destroy'])->middleware('permission:media.manage')->whereNumber('id');

        // 短信渠道（短信渠道计划 第一期）：读 sms.view / 写 sms.manage
        // 测试发送额外挂 throttle:sms-send：短信按条计费，一个未限流的发送口等于一个可被刷的账单。
        Route::prefix('sms')->group(function () {
            Route::get('/config', [AdminSmsConfigController::class, 'config'])
                ->middleware('permission:sms.view');
            Route::put('/config', [AdminSmsConfigController::class, 'updateSwitches'])
                ->middleware('permission:sms.manage');
            // ↓ 带 {id} 的路由必须排在无 {id} 的之后
            Route::put('/config/{id}', [AdminSmsConfigController::class, 'updateChannel'])
                ->middleware('permission:sms.manage')->whereNumber('id');
            Route::post('/test', [AdminSmsConfigController::class, 'test'])
                ->middleware(['permission:sms.manage', 'throttle:sms-send']);
            Route::get('/logs', [AdminSmsConfigController::class, 'logs'])
                ->middleware('permission:sms.view');
        });


        // 分类管理 category.manage（API 文档 8.2）
        Route::get('/categories', [CategoryController::class, 'index'])->middleware('permission:category.manage');
        Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:category.manage');
        Route::put('/categories/{id}', [CategoryController::class, 'update'])->middleware('permission:category.manage');
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->middleware('permission:category.manage');

        // 前台导航管理 nav.manage
        Route::get('/nav-items', [NavItemController::class, 'index'])->middleware('permission:nav.manage');
        Route::post('/nav-items', [NavItemController::class, 'store'])->middleware('permission:nav.manage');
        Route::put('/nav-items/{id}', [NavItemController::class, 'update'])->middleware('permission:nav.manage');
        Route::delete('/nav-items/{id}', [NavItemController::class, 'destroy'])->middleware('permission:nav.manage');

        // 商品管理 product.*（API 文档 8.1）
        Route::get('/products', [ProductController::class, 'index'])->middleware('permission:product.view');
        Route::post('/products', [ProductController::class, 'store'])->middleware('permission:product.create');
        Route::post('/products/batch', [ProductController::class, 'batch'])->middleware('permission:product.update');
        Route::get('/products/{id}', [ProductController::class, 'show'])->middleware('permission:product.view');
        Route::put('/products/{id}', [ProductController::class, 'update'])->middleware('permission:product.update');
        Route::post('/products/{id}/status', [ProductController::class, 'updateStatus'])->middleware('permission:product.update');
        // V1.1 E01（T-009）：SKU 矩阵预览、批量设置
        Route::post('/products/sku-matrix', [ProductController::class, 'previewSkuMatrix'])->middleware('permission:product.update');
        Route::post('/products/{id}/skus/batch-set', [ProductController::class, 'batchSetSkus'])->middleware('permission:product.update');

        // 品牌管理（V1.1 E01 / T-008）权限 product.update
        Route::get('/brands', [BrandController::class, 'index'])->middleware('permission:product.view');
        Route::post('/brands', [BrandController::class, 'store'])->middleware('permission:product.update');
        Route::put('/brands/{id}', [BrandController::class, 'update'])->middleware('permission:product.update');
        Route::delete('/brands/{id}', [BrandController::class, 'destroy'])->middleware('permission:product.update');

        // 属性库（V1.1 E01 / T-008）权限 product.update
        Route::get('/attributes', [AdminAttributeController::class, 'index'])->middleware('permission:product.view');
        Route::post('/attributes', [AdminAttributeController::class, 'store'])->middleware('permission:product.update');
        Route::get('/attributes/{id}', [AdminAttributeController::class, 'show'])->middleware('permission:product.view');
        Route::put('/attributes/{id}', [AdminAttributeController::class, 'update'])->middleware('permission:product.update');
        Route::delete('/attributes/{id}', [AdminAttributeController::class, 'destroy'])->middleware('permission:product.update');
        Route::get('/attributes/{id}/values', [AdminAttributeController::class, 'values'])->middleware('permission:product.view');
        Route::post('/attributes/{id}/values', [AdminAttributeController::class, 'storeValue'])->middleware('permission:product.update');
        Route::post('/attributes/{id}/values/batch', [AdminAttributeController::class, 'batchValues'])->middleware('permission:product.update');
        Route::put('/attributes/{id}/values/{valueId}', [AdminAttributeController::class, 'updateValue'])->middleware('permission:product.update');
        Route::delete('/attributes/{id}/values/{valueId}', [AdminAttributeController::class, 'destroyValue'])->middleware('permission:product.update');

        // 分类属性模板（V1.1 E01 / T-008）权限 product.update
        Route::get('/categories/{categoryId}/attributes', [CategoryAttributeController::class, 'show'])->middleware('permission:product.view');
        Route::put('/categories/{categoryId}/attributes', [CategoryAttributeController::class, 'update'])->middleware('permission:product.update');

        // 分类可选品牌（分类 ↔ 品牌 多对多，2026-09-19）权限 product.update
        Route::get('/categories/{categoryId}/brands', [CategoryBrandController::class, 'show'])->middleware('permission:product.view');
        Route::put('/categories/{categoryId}/brands', [CategoryBrandController::class, 'update'])->middleware('permission:product.update');

        // 数据概览 dashboard.view（API 文档 8.5 / Roadmap P6）
        Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view');

        // 经营报表 report.view（V1.1 F03 / T-020）
        Route::get('/reports/overview', [AdminReportController::class, 'overview'])->middleware('permission:report.view');
        Route::get('/reports/trend', [AdminReportController::class, 'trend'])->middleware('permission:report.view');
        Route::get('/reports/top-products', [AdminReportController::class, 'topProducts'])->middleware('permission:report.view');
        Route::get('/reports/category-share', [AdminReportController::class, 'categoryShare'])->middleware('permission:report.view');
        Route::get('/reports/users', [AdminReportController::class, 'users'])->middleware('permission:report.view');
        Route::get('/reports/export', [AdminReportController::class, 'export'])->middleware(['permission:report.view', 'throttle:3,1']);

        // 订单管理 order.*（API 文档 8.3 / Roadmap P6）—— export 必须注册在 {id} 之前
        Route::get('/orders', [AdminOrderController::class, 'index'])->middleware('permission:order.view');
        Route::get('/orders/export', [AdminOrderController::class, 'export'])->middleware(['permission:order.export', 'throttle:3,1']);
        Route::get('/orders/batch-ship/template', [\App\Http\Controllers\Admin\BatchShipController::class, 'template'])->middleware('permission:order.ship');
        Route::post('/orders/batch-ship', [\App\Http\Controllers\Admin\BatchShipController::class, 'store'])->middleware('permission:order.ship');
        // 运单号智能识别（V1.1 三期）—— 静态路径，同样须注册在 {id} 之前
        Route::post('/orders/detect-company', [AdminOrderController::class, 'detectCompany'])->middleware('permission:order.ship');

        // 物流管理（V1.1 T-045 / T-047）—— pull 必须注册在 {id} 类路由之外，无冲突
        Route::post('/shippings/{id}/pull', [\App\Http\Controllers\Admin\ShippingController::class, 'pull'])->middleware('permission:order.ship')->whereNumber('id');
        // ⚠️ /shippings/channel 与 /shippings/{id} 必须注册在 {id} 之前，否则被当作 id 匹配
        Route::get('/shippings/channel', [\App\Http\Controllers\Admin\ShippingController::class, 'channel'])->middleware('permission:order.view');
        Route::put('/shippings/channel', [\App\Http\Controllers\Admin\ShippingController::class, 'updateChannel'])->middleware('permission:shipping.manage');
        // 电子面单申请渠道（V1.2，与查询渠道对称）：静态路径，注册在 {id} 之前
        Route::get('/shippings/waybill-channel', [\App\Http\Controllers\Admin\ShippingController::class, 'waybillChannel'])->middleware('permission:order.view');
        Route::put('/shippings/waybill-channel', [\App\Http\Controllers\Admin\ShippingController::class, 'updateWaybillChannel'])->middleware('permission:shipping.manage');
        // 电子面单打印端点（V1.2 出单侧配套）：离线重打，不依赖第三方
        Route::get('/shippings/{id}/waybill', [\App\Http\Controllers\Admin\ShippingController::class, 'waybillPrint'])->middleware('permission:order.view')->whereNumber('id');
        // 补出 / 重打电子面单（V1.2）：历史/无模板运单经当前渠道重新申请，写回模板
        Route::post('/shippings/{id}/waybill/reissue', [\App\Http\Controllers\Admin\ShippingController::class, 'reissueWaybill'])->middleware('permission:shipping.manage')->whereNumber('id');
        Route::get('/shippings/{id}', [\App\Http\Controllers\Admin\ShippingController::class, 'show'])->middleware('permission:order.view')->whereNumber('id');
        Route::get('/shippings', [\App\Http\Controllers\Admin\ShippingController::class, 'index'])->middleware('permission:order.view');

        // 快递公司字典维护（V1.1 T-047，权限 shipping.manage）—— enabled 必须在 {id} 之前
        Route::get('/shipping-companies/enabled', [\App\Http\Controllers\Admin\ExpressCompanyController::class, 'enabled'])->middleware('permission:order.ship');
        Route::get('/shipping-companies', [\App\Http\Controllers\Admin\ExpressCompanyController::class, 'index'])->middleware('permission:shipping.manage');
        Route::post('/shipping-companies', [\App\Http\Controllers\Admin\ExpressCompanyController::class, 'store'])->middleware('permission:shipping.manage');
        Route::put('/shipping-companies/{id}', [\App\Http\Controllers\Admin\ExpressCompanyController::class, 'update'])->middleware('permission:shipping.manage')->whereNumber('id');
        Route::delete('/shipping-companies/{id}', [\App\Http\Controllers\Admin\ExpressCompanyController::class, 'destroy'])->middleware('permission:shipping.manage')->whereNumber('id');

        // 运费模板（T-053 Stage1，权限 shipping.manage）
        Route::get('/freight-templates', [\App\Http\Controllers\Admin\FreightTemplateController::class, 'index'])->middleware('permission:shipping.manage');
        Route::post('/freight-templates', [\App\Http\Controllers\Admin\FreightTemplateController::class, 'store'])->middleware('permission:shipping.manage');
        Route::put('/freight-templates/{id}', [\App\Http\Controllers\Admin\FreightTemplateController::class, 'update'])->middleware('permission:shipping.manage')->whereNumber('id');
        Route::delete('/freight-templates/{id}', [\App\Http\Controllers\Admin\FreightTemplateController::class, 'destroy'])->middleware('permission:shipping.manage')->whereNumber('id');
        // 全局默认模板（未绑定模板的商品行走此模板；须放在 {id} 泛型路由后无碍——方法+数字约束已隔离）
        Route::post('/freight-templates/clear-default', [\App\Http\Controllers\Admin\FreightTemplateController::class, 'clearDefault'])->middleware('permission:shipping.manage');
        Route::post('/freight-templates/{id}/set-default', [\App\Http\Controllers\Admin\FreightTemplateController::class, 'setDefault'])->middleware('permission:shipping.manage')->whereNumber('id');

        Route::get('/orders/{id}', [AdminOrderController::class, 'show'])->middleware('permission:order.view');
        // 订单资金视图（G7：统一资金流水聚合，只读）
        Route::get('/orders/{id}/funds', [AdminOrderController::class, 'funds'])->middleware('permission:order.view')->whereNumber('id');
        Route::post('/orders/{id}/ship', [AdminOrderController::class, 'ship'])->middleware('permission:order.ship');
        Route::post('/orders/{id}/accept', [AdminOrderController::class, 'accept'])->middleware('permission:order.ship');

        // 支付管理 payment.view / payment.manage（API 文档 8.11）—— export 必须注册在 {id} 之前
        Route::get('/payments', [AdminPaymentController::class, 'index'])->middleware('permission:payment.view');
        Route::get('/payments/export', [AdminPaymentController::class, 'export'])->middleware(['permission:payment.view', 'throttle:3,1']);
        Route::get('/payments/{id}', [AdminPaymentController::class, 'show'])->middleware('permission:payment.view');
        Route::post('/payments/{id}/close', [AdminPaymentController::class, 'close'])->middleware('permission:payment.manage');
                Route::post('/payments/{id}/review', [AdminPaymentController::class, 'review'])->middleware('permission:payment.offline.review');

        // 支付渠道日终对账（A7）：运行清单 / 差异工单 / 处置，权限 payment.reconcile.view / handle
        Route::get('/payment-reconciles', [AdminPaymentReconcileController::class, 'runs'])->middleware('permission:payment.reconcile.view');
        Route::get('/payment-reconciles/stats', [AdminPaymentReconcileController::class, 'stats'])->middleware('permission:payment.reconcile.view');
        Route::get('/payment-reconciles/{id}', [AdminPaymentReconcileController::class, 'showRun'])->middleware('permission:payment.reconcile.view');
        Route::get('/payment-reconcile-diffs', [AdminPaymentReconcileController::class, 'diffs'])->middleware('permission:payment.reconcile.view');
        Route::get('/payment-reconcile-diffs/export', [AdminPaymentReconcileController::class, 'export'])->middleware(['permission:payment.reconcile.view', 'throttle:3,1']);
        Route::post('/payment-reconcile-diffs/{id}/resolve', [AdminPaymentReconcileController::class, 'resolve'])->middleware('permission:payment.reconcile.handle');

        // 支付渠道配置 payment.channel.manage（仅超管，§5）
        Route::get('/payment-channels', [AdminPaymentChannelController::class, 'index'])->middleware('permission:payment.channel.manage');
        Route::get('/payment-channels/{channel}', [AdminPaymentChannelController::class, 'show'])->middleware('permission:payment.channel.manage');
        Route::put('/payment-channels/{channel}', [AdminPaymentChannelController::class, 'update'])->middleware('permission:payment.channel.manage');
        Route::post('/payment-channels/{channel}/toggle', [AdminPaymentChannelController::class, 'toggle'])->middleware('permission:payment.channel.manage');
        Route::post('/payment-channels/{channel}/test', [AdminPaymentChannelController::class, 'test'])->middleware('permission:payment.channel.manage');
        Route::get('/payment-channels/{channel}/logs', [AdminPaymentChannelController::class, 'logs'])->middleware('permission:payment.channel.manage');

        // 余额充值单管理 balance.recharge.view（核账需 payment.offline.review，§5.2 / §6.5）
        Route::get('/balance-recharges', [AdminBalanceRechargeController::class, 'index'])->middleware('permission:balance.recharge.view');
        Route::get('/balance-recharges/export', [AdminBalanceRechargeController::class, 'export'])->middleware(['permission:balance.recharge.view', 'throttle:3,1']);
        Route::get('/balance-recharges/{id}', [AdminBalanceRechargeController::class, 'show'])->middleware('permission:balance.recharge.view');
        Route::post('/balance-recharges/{id}/review', [AdminBalanceRechargeController::class, 'review'])->middleware('permission:payment.offline.review');

        // 支付日志 payment.view（API 文档 8.12，只读）
        Route::get('/payment-logs', [AdminPaymentLogController::class, 'index'])->middleware('permission:payment.view');
        Route::get('/payment-logs/{id}', [AdminPaymentLogController::class, 'show'])->middleware('permission:payment.view');

        // 订单状态流水 order.log（API 文档 8.13，只读）
        Route::get('/order-logs', [AdminOrderLogController::class, 'index'])->middleware('permission:order.log');
        Route::get('/orders/{orderId}/logs', [AdminOrderLogController::class, 'orderIndex'])->middleware('permission:order.log');

        // 退款处理 refund.*（API 文档 8.4 / Roadmap P5）
        Route::get('/refunds', [RefundController::class, 'index'])->middleware('permission:refund.view');
        Route::get('/refunds/{id}', [RefundController::class, 'show'])->middleware('permission:refund.view');
        Route::post('/refunds/{id}/process', [RefundController::class, 'process'])->middleware('permission:refund.process');
        Route::post('/refunds/{id}/receive', [RefundController::class, 'receive'])->middleware('permission:refund.process');
        Route::post('/refunds/{id}/retry', [RefundController::class, 'retry'])->middleware('permission:refund.process');
        Route::get('/refunds/{id}/logs', [RefundController::class, 'logs'])->middleware('permission:refund.view');

        // WMS 对接配置（WMS 计划 P0 / §9.1）：仓库档案 + 按仓配置 + SKU 映射
        // 权限码 wms.config.manage（超管 + 运营）；P6 起追加发货单/退货单页面复用 wms.order.* / wms.return.manage
        Route::prefix('wms')->middleware('permission:wms.config.manage')->group(function () {
            Route::get('/warehouses', [WmsConfigController::class, 'warehouses']);
            Route::post('/warehouses', [WmsConfigController::class, 'storeWarehouse']);
            Route::put('/warehouses/{id}', [WmsConfigController::class, 'updateWarehouse']);
            Route::get('/warehouses/{id}/config', [WmsConfigController::class, 'showConfig']);
            Route::put('/warehouses/{id}/config', [WmsConfigController::class, 'saveConfig']);
            Route::post('/warehouses/{id}/config/test', [WmsConfigController::class, 'testConfig']);
            Route::get('/warehouses/{id}/sku-mappings', [WmsConfigController::class, 'skuMappings']);
            // batch 必须注册在 {skuId} 之前，避免被 DELETE 的 {skuId} 通配影响（不同 method，此处仅为语义清晰）
            Route::post('/warehouses/{id}/sku-mappings/batch', [WmsConfigController::class, 'importSkuMappings']);
            Route::delete('/warehouses/{id}/sku-mappings/{skuId}', [WmsConfigController::class, 'destroySkuMapping']);
        });

        // WMS 发货单（WMS 计划 P1 / F8、Step 7）
        // 查看与治理分开授权：wms.order.view 只读，wms.order.manage 才允许重推/取消
        Route::post('/wms/fulfillment-orders/batch-push', [WmsFulfillmentController::class, 'batchPush'])
            ->middleware('permission:wms.order.manage');
        Route::get('/wms/fulfillment-orders', [WmsFulfillmentController::class, 'index'])
            ->middleware('permission:wms.order.view');
        Route::get('/wms/fulfillment-orders/{id}', [WmsFulfillmentController::class, 'show'])
            ->middleware('permission:wms.order.view');
        Route::post('/wms/fulfillment-orders/{id}/push', [WmsFulfillmentController::class, 'push'])
            ->middleware('permission:wms.order.manage');
        Route::post('/wms/fulfillment-orders/{id}/cancel', [WmsFulfillmentController::class, 'cancel'])
            ->middleware('permission:wms.order.manage');

        // WMS 退货入库单（WMS 计划 P4 / F9、Step 8）：权限统一 wms.return.manage
        Route::get('/wms/return-inbound-orders', [WmsReturnInboundController::class, 'index'])
            ->middleware('permission:wms.return.manage');
        Route::get('/wms/return-inbound-orders/{id}', [WmsReturnInboundController::class, 'show'])
            ->middleware('permission:wms.return.manage');
        Route::post('/wms/return-inbound-orders/{id}/push', [WmsReturnInboundController::class, 'push'])
            ->middleware('permission:wms.return.manage');
        Route::post('/wms/return-inbound-orders/{id}/cancel', [WmsReturnInboundController::class, 'cancel'])
            ->middleware('permission:wms.return.manage');
        Route::post('/wms/return-inbound-orders/{id}/manual-received', [WmsReturnInboundController::class, 'manualReceived'])
            ->middleware('permission:wms.return.manage');

        // WMS 库存同步 / 对账 / 健康巡检（WMS 计划 P5 / Step 5）：权限 wms.config.manage
        Route::get('/wms/inventory/snapshots', [WmsInventoryController::class, 'snapshots'])
            ->middleware('permission:wms.config.manage');
        Route::get('/wms/inventory/diffs', [WmsInventoryController::class, 'diffs'])
            ->middleware('permission:wms.config.manage');
        Route::post('/wms/inventory/diffs/{id}/resolve', [WmsInventoryController::class, 'resolve'])
            ->middleware('permission:wms.config.manage');
        Route::post('/wms/inventory/sync', [WmsInventoryController::class, 'sync'])
            ->middleware('permission:wms.config.manage');
        Route::get('/wms/health', [WmsInventoryController::class, 'health'])
            ->middleware('permission:wms.config.manage');

        // 履约中心共用仓库下拉（P6）：只读运营 / 退货管理员也要能筛仓库
        Route::get('/wms/warehouse-options', [WmsConsoleController::class, 'warehouseOptions'])
            ->middleware('role_or_permission:wms.order.view|wms.return.manage|wms.config.manage');

        // WMS 调用日志（WMS 计划 P6 / F5）：权限 wms.config.manage
        Route::get('/wms/logs', [WmsApiLogController::class, 'index'])
            ->middleware('permission:wms.config.manage');
        Route::get('/wms/logs/{id}', [WmsApiLogController::class, 'show'])
            ->middleware('permission:wms.config.manage');

        // 评价管理 review.manage（V1.1 F01 / T-017）
        Route::post('/reviews/audit-mode', [AdminReviewController::class, 'updateAuditMode'])->middleware('permission:config.manage');
        Route::get('/reviews', [AdminReviewController::class, 'index'])->middleware('permission:review.manage');
        Route::get('/reviews/{id}', [AdminReviewController::class, 'show'])->middleware('permission:review.manage');
        Route::post('/reviews/{id}/approve', [AdminReviewController::class, 'approve'])->middleware('permission:review.manage');
        Route::post('/reviews/{id}/reject', [AdminReviewController::class, 'reject'])->middleware('permission:review.manage');
        Route::post('/reviews/{id}/reply', [AdminReviewController::class, 'reply'])->middleware('permission:review.manage');
        Route::post('/reviews/{id}/hidden', [AdminReviewController::class, 'setHidden'])->middleware('permission:review.manage');
        Route::delete('/reviews/{id}', [AdminReviewController::class, 'destroy'])->middleware('permission:review.manage');

        // 客户服务中心后台（CS-108/109 工单管理；CS-110 FAQ 管理；CMS-104/105 升级为内容管理）
        // sort 必须注册在 {id} 之前，避免被 {id} 路由捕获
        Route::get('/cs/faq/categories', [AdminCsFaqController::class, 'categories'])->middleware('permission:cs.faq.manage');
        Route::post('/cs/faq/categories', [AdminCsFaqController::class, 'storeCategory'])->middleware('permission:cs.faq.manage');
        Route::post('/cs/faq/categories/sort', [AdminCsFaqController::class, 'sortCategories'])->middleware('permission:cs.faq.manage');
        // 栏目换父（CMS-104）：防环与子树级联重算在 CmsCategoryService 内
        Route::post('/cs/faq/categories/{id}/move', [AdminCsFaqController::class, 'moveCategory'])->middleware('permission:cs.faq.manage');
        Route::put('/cs/faq/categories/{id}', [AdminCsFaqController::class, 'updateCategory'])->middleware('permission:cs.faq.manage');
        Route::delete('/cs/faq/categories/{id}', [AdminCsFaqController::class, 'destroyCategory'])->middleware('permission:cs.faq.manage');

        Route::get('/cs/faq/articles', [AdminCsFaqController::class, 'articles'])->middleware('permission:cs.faq.manage');
        Route::post('/cs/faq/articles', [AdminCsFaqController::class, 'storeArticle'])->middleware('permission:cs.faq.manage');
        // 单篇详情：后台「新增/编辑文章」是独立页面（不再是列表页侧边弹层），
        // 该页面直接按 id 回源，避免依赖列表页内存里的行数据（刷新/直达不再空白）
        Route::get('/cs/faq/articles/{id}', [AdminCsFaqController::class, 'showArticle'])->middleware('permission:cs.faq.manage');
        Route::get('/cs/faq/articles/{id}/preview', [AdminCsFaqController::class, 'previewArticle'])->middleware('permission:cs.faq.manage');
        Route::post('/cs/faq/articles/{id}/publish', [AdminCsFaqController::class, 'publishArticle'])->middleware('permission:cs.faq.manage');
        Route::post('/cs/faq/articles/{id}/offline', [AdminCsFaqController::class, 'offlineArticle'])->middleware('permission:cs.faq.manage');
        Route::put('/cs/faq/articles/{id}', [AdminCsFaqController::class, 'updateArticle'])->middleware('permission:cs.faq.manage');
        Route::delete('/cs/faq/articles/{id}', [AdminCsFaqController::class, 'destroyArticle'])->middleware('permission:cs.faq.manage');

        // 单页内容（CMS-105）：模板 schema + 字段读写（{id} 是 type=page 的栏目 id）
        // page-templates 必须注册在 {id} 之前，避免被 pages/{id} 捕获
        Route::get('/cs/faq/page-templates', [AdminCsFaqController::class, 'pageTemplates'])->middleware('permission:cs.faq.manage');
        // 区块库（CMS-203）：template=blocks 的单页用它渲染区块选择器与字段表单
        Route::get('/cs/faq/page-blocks', [AdminCsFaqController::class, 'pageBlocks'])->middleware('permission:cs.faq.manage');
        Route::post('/cs/faq/upload', [AdminCsFaqController::class, 'uploadImage'])->middleware('permission:cs.faq.manage');
        Route::get('/cs/faq/pages/{id}', [AdminCsFaqController::class, 'showPage'])->middleware('permission:cs.faq.manage');
        Route::put('/cs/faq/pages/{id}', [AdminCsFaqController::class, 'savePage'])->middleware('permission:cs.faq.manage');

        Route::get('/cs/ticket-types', [AdminCsTicketController::class, 'ticketTypes'])->middleware('permission:cs.ticket.view');
        Route::get('/cs/assignees', [AdminCsTicketController::class, 'assignees'])->middleware('permission:cs.ticket.handle');

        // 客户服务中心 · 快捷回复模板（CS-203 / CS-204）
        // 读取挂 role_or_permission：客服（cs.ticket.handle）可取用下拉，管理员/客服主管（cs.faq.manage）可取用+管理
        // 写入挂 permission:cs.faq.manage：仅可管理角色可维护
        Route::get('/cs/quick-replies', [AdminCsQuickReplyController::class, 'index'])->middleware('role_or_permission:cs.faq.manage|cs.ticket.handle');
        Route::post('/cs/quick-replies', [AdminCsQuickReplyController::class, 'store'])->middleware('permission:cs.faq.manage');
        Route::put('/cs/quick-replies/{id}', [AdminCsQuickReplyController::class, 'update'])->middleware('permission:cs.faq.manage');
        Route::delete('/cs/quick-replies/{id}', [AdminCsQuickReplyController::class, 'destroy'])->middleware('permission:cs.faq.manage');
        Route::get('/cs/tickets', [AdminCsTicketController::class, 'index'])->middleware('permission:cs.ticket.view');
        Route::get('/cs/tickets/{id}', [AdminCsTicketController::class, 'show'])->middleware('permission:cs.ticket.view');
        Route::post('/cs/tickets/{id}/messages', [AdminCsTicketController::class, 'messages'])->middleware('permission:cs.ticket.handle');
        Route::put('/cs/tickets/{id}/status', [AdminCsTicketController::class, 'status'])->middleware('permission:cs.ticket.handle');
        Route::put('/cs/tickets/{id}/assign', [AdminCsTicketController::class, 'assign'])->middleware('permission:cs.ticket.handle');
        Route::put('/cs/tickets/{id}/priority', [AdminCsTicketController::class, 'priority'])->middleware('permission:cs.ticket.handle');
        Route::post('/cs/tickets/batch-assign', [AdminCsTicketController::class, 'batchAssign'])->middleware('permission:cs.ticket.handle');

        // 营销管理 marketing.manage（V1.1 二期 F06 / T-032）—— export 必须注册在 {id} 之前
        Route::get('/coupons', [AdminCouponController::class, 'index'])->middleware('permission:marketing.manage');
        Route::post('/coupons', [AdminCouponController::class, 'store'])->middleware('permission:marketing.manage');
        Route::get('/coupons/{id}/stats', [AdminCouponController::class, 'stats'])->middleware('permission:marketing.manage');
        Route::get('/coupons/{id}/export', [AdminCouponController::class, 'export'])->middleware(['permission:marketing.manage', 'throttle:3,1']);
        Route::put('/coupons/{id}', [AdminCouponController::class, 'update'])->middleware('permission:marketing.manage');

        // 公告管理 announcement.manage（P-Announcement）—— preview 必须注册在 {id} 之前
        Route::get('/announcements', [AdminAnnouncementController::class, 'index'])->middleware('permission:announcement.manage');
        Route::post('/announcements', [AdminAnnouncementController::class, 'store'])->middleware('permission:announcement.manage');
        Route::get('/announcements/{id}/preview', [AdminAnnouncementController::class, 'preview'])->middleware('permission:announcement.manage');
        Route::put('/announcements/{id}', [AdminAnnouncementController::class, 'update'])->middleware('permission:announcement.manage');
        Route::post('/announcements/{id}/publish', [AdminAnnouncementController::class, 'publish'])->middleware('permission:announcement.manage');
        Route::post('/announcements/{id}/offline', [AdminAnnouncementController::class, 'offline'])->middleware('permission:announcement.manage');
        Route::delete('/announcements/{id}', [AdminAnnouncementController::class, 'destroy'])->middleware('permission:announcement.manage');

        // 首页广告位管理 home.manage（P-HomeBanner）
        Route::get('/home-banners', [AdminHomeBannerController::class, 'index'])->middleware('permission:home.manage');
        Route::post('/home-banners', [AdminHomeBannerController::class, 'store'])->middleware('permission:home.manage');
        Route::put('/home-banners/{id}', [AdminHomeBannerController::class, 'update'])->middleware('permission:home.manage')->whereNumber('id');
        Route::post('/home-banners/{id}/toggle', [AdminHomeBannerController::class, 'toggle'])->middleware('permission:home.manage')->whereNumber('id');
        Route::delete('/home-banners/{id}', [AdminHomeBannerController::class, 'destroy'])->middleware('permission:home.manage')->whereNumber('id');
        Route::post('/coupons/{id}/stop', [AdminCouponController::class, 'stop'])->middleware('permission:marketing.manage');

        Route::get('/promotions', [AdminPromotionController::class, 'index'])->middleware('permission:marketing.manage');
        Route::post('/promotions', [AdminPromotionController::class, 'store'])->middleware('permission:marketing.manage');
        Route::put('/promotions/{id}', [AdminPromotionController::class, 'update'])->middleware('permission:marketing.manage');
        Route::post('/promotions/{id}/toggle', [AdminPromotionController::class, 'toggle'])->middleware('permission:marketing.manage');

        // 管理员账号 account.manage（V1.1 F04 / T-022）
        Route::get('/accounts', [AdminAccountController::class, 'index'])->middleware('permission:account.manage');
        Route::post('/accounts', [AdminAccountController::class, 'store'])->middleware('permission:account.manage');
        Route::put('/accounts/{id}', [AdminAccountController::class, 'update'])->middleware('permission:account.manage');
        Route::post('/accounts/{id}/status', [AdminAccountController::class, 'updateStatus'])->middleware('permission:account.manage');
        Route::post('/accounts/{id}/reset-password', [AdminAccountController::class, 'resetPassword'])->middleware('permission:account.manage');

        // 角色与权限 role.manage（V1.1 F04 / T-022）
        Route::get('/roles', [AdminRoleController::class, 'index'])->middleware('permission:role.manage');
        Route::post('/roles', [AdminRoleController::class, 'store'])->middleware('permission:role.manage');
        Route::put('/roles/{id}', [AdminRoleController::class, 'update'])->middleware('permission:role.manage');
        Route::delete('/roles/{id}', [AdminRoleController::class, 'destroy'])->middleware('permission:role.manage');
        Route::get('/permissions', [AdminRoleController::class, 'permissions'])->middleware('permission:role.manage');

        // 用户管理 user.*（API 文档 8.8 / 权限 user.manage）
        Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.manage');
        Route::get('/users/{id}', [UserController::class, 'show'])->middleware('permission:user.manage');
        Route::put('/users/{id}', [UserController::class, 'update'])->middleware('permission:user.manage');
        Route::put('/users/{id}/status', [UserController::class, 'updateStatus'])->middleware('permission:user.manage');
        Route::put('/users/{id}/password', [UserController::class, 'changePassword'])->middleware('permission:user.manage');

        // 收货地址管理（设计文档 CubeShop_Address_Design_v1.0 §5）
        // 查看：address.view（运营可核对）；代改：address.manage（仅超管，禁改默认/归属）
        Route::get('/users/{userId}/addresses', [AdminAddressController::class, 'userIndex'])->middleware('permission:address.view');
        Route::put('/addresses/{id}', [AdminAddressController::class, 'update'])->middleware('permission:address.manage');
    });
});

// 支付回调（无需用户 Token，由各渠道网关验签保护；API 文档 7.2）
Route::post('/payments/callback/{channel}', [PaymentController::class, 'callback']);

// 退款异步通知（Phase 4，无需用户 Token，微信验签保护；API 文档 7.2）
// 仅微信推送；支付宝 / 余额为同步退款不会到达；路由与支付回调按 {channel} 区分。
Route::post('/payments/{channel}/refund-notify', [RefundNotifyController::class, 'handle']);

// 沙箱模拟渠道通知（SEC-01）：**仅非生产环境注册**。
// 生产环境该路由根本不存在（404），即使 PAYMENT_SANDBOX 被误配为 true 也无法调用；
// 服务层 PaymentService::sandboxNotify() 另有一道环境断言兜底。
if (app()->environment(['local', 'testing', 'staging'])) {
    $sandbox = Route::post('/payments/sandbox/{paymentNo}', [PaymentController::class, 'sandbox']);

    // 限流防批量枚举支付单号（测试环境不加，避免用例间限流计数互相干扰）
    if (! app()->environment('testing')) {
        $sandbox->middleware('throttle:60,1');
    }
}
