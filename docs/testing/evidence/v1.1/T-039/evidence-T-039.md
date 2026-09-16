# T-039 [WEB] 结算页用券与满减金额明细 — 证据

日期：2026-09-16 ｜ 提交：见 `commit-T-039.txt`

## 交付物

| 文件 | 说明 |
|------|------|
| `backend/app/Http/Controllers/Storefront/PromotionController.php` | **新增** `GET /promotions/preview`：行项目口径复用 `buildContext`（缺 category_id 回库补全）+ `PromotionService::displayFor`，无命中返回 null |
| `backend/routes/api.php` | 注册 `Route::get('/promotions/preview', ...)`（auth:sanctum 内） |
| `backend/tests/Feature/PromotionPreviewApiTest.php` | **新增** 4 例：命中最优梯度结构 / 未达门槛与活动结束返回 null / 分类活动回库补全命中 / 未登录 401 |
| `web/src/api/coupon.ts` | 新增 `getAvailableCoupons`、`getPromotionPreview` 与 `AvailableCoupon`/`UnavailableCoupon`/`PromotionDisplay` 类型 |
| `web/src/api/order.ts` | `createOrder` 支持 `user_coupon_id`/`promotion_id`；`CreateOrderResult` 增加 `discount_amount`/`promotion_discount`/`coupon_id`/`amount_details` |
| `web/src/views/CheckoutView.vue` | 用券区（入口行：已选金额+可用张数）、券选择弹层（可用/不可用分组+原因+不使用券选项）、金额明细区（商品→满减→券→运费→应付）、默认最优券、提交后服务端金额核对弹窗、券失效降级「不使用优惠券继续下单」 |

## 测试结果

### 前端 Vitest（`vitest-T-039.txt`）

**13 文件 / 109 用例全绿**（新增 `checkout-coupon.test.ts` 8 例），覆盖任务书全部 6 个测试点：

1. ① 默认选中最优券（discount 最大）并展示在明细区；
2. ② 切换券后明细金额变化（固定数据：130→140→150）；
3. ③ 不可用券分组展示原因（未满使用门槛/已过期）；
4. ④ 无券可领且无满减时隐藏优惠行（与 V1.0 视觉一致）；
5. ⑤ 服务端金额与前端预览不一致 → ConfirmDialog 提示（预览 140 vs 服务端 135），确认后以服务端为准跳支付页；
6. ⑥ 券失效异常态：提示后端冲突文案 + 「不使用优惠券继续下单」降级重试成功（不带 user_coupon_id）；
7. 下单请求携带默认选中的 user_coupon_id（正常路径跳支付页）；
8. 满减与券同时生效明细正确（150−10−10=130）。

### 构建（`build-T-039.txt`）

`vue-tsc -b && vite build` 通过。

### 后端双库回归（`pest-T-039.txt`）

- SQLite：**558 passed (1987 assertions)** / 29.76s
- PostgreSQL：**558 passed (1987 assertions)** / 127.40s
- 554 → 558：新增满减预览接口 4 例。

### 既有测试加固（顺带修复）

- `address-enhance.test.ts`：CheckoutView 新增券接口调用后，原文件缺 `@/api/coupon` mock 导致真实 axios 在 jsdom 拖慢 → 已补 mock。
- `account-center.test.ts`：`@/api/user` mock 补 `getBalance`/`getRecharges`/`getBalanceLogs`（原缺失走真实 axios，全量并发下偶发 1s waitFor 超时，即 T-038 记录的 flaky 根因）+ `gotoSecurity` waitFor 放宽至 3s。修复后全量两连跑均全绿。

## 真浏览器验收（agent-browser，web:3000）

数据：t038buyer 购物车 1×无线蓝牙耳机 ¥199；满减活动「满100减10 / 满300减40」；持有券 9（立减¥10满50）与券 10（9折满100封顶30）。

1. **默认最优券**（`screenshot-T-039-amount-detail.png`）：明细区展示 满减优惠 −¥10.00、优惠券 −¥19.90（自动选中 9 折券：199×0.9=19.9 优于立减 10）、应付 ¥169.10（199−10−19.9，满99包邮）——与手算一致。
2. **券选择弹层**（`screenshot-T-039-coupon-select.png`）：「不使用优惠券」+ 2 张可用券（含各自抵扣预览）。
3. **集成验证**：提交订单 → 跳转 `/orders/37/pay`，支付页金额 **¥169.10** = 结算页预览 = `GET /orders/37` 返回 `pay_amount=169.10`（total 199.00 / freight 0.00 / pending_payment）——「领券→我的券→结算用券→支付页金额一致」全链路打通。

## 口径说明

- 前端预览的满减金额来自 `displayFor`（与下单 `match()` 同源）；券金额来自 `availableFor` 的 `discount`（与下单 `couponDiscount` 同口径）。提交后若服务端 `pay_amount` 与预览差 >0.01，弹窗「优惠金额已按服务端核算」并以其为准继续支付（Vitest ⑤）。
- 「不使用优惠券时隐藏优惠行」已实现（V1.0 视觉一致）；满减行在后端无命中活动时同样隐藏。

## 遗留与说明

- 步骤 5「商品详情与购物车行项目展示满减/可用券提示标签」：详情页领券小标已由 T-038 交付；**购物车行项目级标签未做**（购物车页顶部活动提示条可并入 T-041 或后续打磨），测试要求 6 项均不依赖该条。
- 步骤 4「订单提交后支付页金额一致」已真浏览器验证；「不一致时以服务端为准并提示」由 ConfirmDialog + Vitest ⑤ 覆盖。
