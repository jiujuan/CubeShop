<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ConfigController;
use App\Http\Controllers\Admin\OperationLogController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RefundController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Storefront\ProductController as StorefrontProductController;
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
    Route::get('/{id}', [StorefrontProductController::class, 'show']);
});

// 认证：注册 / 登录 / 验证码 / 重置密码（带限流）
Route::middleware('throttle:auth')->group(function () {
    Route::post('/auth/captcha', [AuthController::class, 'captcha']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

/*
|--------------------------------------------------------------------------
| 认证后接口
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/password', [AuthController::class, 'changePassword']);

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
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{id}/refund', [OrderController::class, 'refund']);

    // 支付（API 文档 7 / Roadmap P5）
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{paymentNo}', [PaymentController::class, 'show']);

    // 收货地址（API 文档 3.3 ~ 3.7）
    Route::get('/user/addresses', [AddressController::class, 'index']);
    Route::post('/user/addresses', [AddressController::class, 'store']);
    Route::put('/user/addresses/{id}', [AddressController::class, 'update']);
    Route::post('/user/addresses/{id}/default', [AddressController::class, 'setDefault']);
    Route::delete('/user/addresses/{id}', [AddressController::class, 'destroy']);

    // 后台管理：需要登录 + 对应权限码（API 文档 8）
    Route::prefix('admin')->group(function () {
        // 系统配置 config.manage
        Route::get('/configs', [ConfigController::class, 'index'])->middleware('permission:config.manage');
        Route::put('/configs', [ConfigController::class, 'update'])->middleware('permission:config.manage');

        // 操作日志 log.view
        Route::get('/operation-logs', [OperationLogController::class, 'index'])->middleware('permission:log.view');

        // 图片上传（登录即可，商品相关权限在业务层校验）
        Route::post('/upload', [UploadController::class, 'store']);

        // 分类管理 category.manage（API 文档 8.2）
        Route::get('/categories', [CategoryController::class, 'index'])->middleware('permission:category.manage');
        Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:category.manage');
        Route::put('/categories/{id}', [CategoryController::class, 'update'])->middleware('permission:category.manage');
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->middleware('permission:category.manage');

        // 商品管理 product.*（API 文档 8.1）
        Route::get('/products', [ProductController::class, 'index'])->middleware('permission:product.view');
        Route::post('/products', [ProductController::class, 'store'])->middleware('permission:product.create');
        Route::post('/products/batch', [ProductController::class, 'batch'])->middleware('permission:product.update');
        Route::get('/products/{id}', [ProductController::class, 'show'])->middleware('permission:product.view');
        Route::put('/products/{id}', [ProductController::class, 'update'])->middleware('permission:product.update');
        Route::post('/products/{id}/status', [ProductController::class, 'updateStatus'])->middleware('permission:product.update');

        // 退款处理 refund.*（API 文档 8.4 / Roadmap P5）
        Route::get('/refunds', [RefundController::class, 'index'])->middleware('permission:refund.view');
        Route::post('/refunds/{id}/process', [RefundController::class, 'process'])->middleware('permission:refund.process');
    });
});

// 支付回调 / 沙箱模拟渠道（无需用户 Token，验签保护；API 文档 7.2）
Route::post('/payments/callback/{channel}', [PaymentController::class, 'callback']);
Route::post('/payments/sandbox/{paymentNo}', [PaymentController::class, 'sandbox']);
