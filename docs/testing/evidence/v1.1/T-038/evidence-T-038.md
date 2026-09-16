# T-038 [WEB] 领券中心与我的优惠券页 — 证据

日期：2026-09-16 ｜ 提交：见 `commit-T-038.txt`

## 交付物

| 文件 | 说明 |
|------|------|
| `web/src/api/coupon.ts` | 券 API 客户端（getCouponCenter / receiveCoupon / getMyCoupons）+ 类型对齐后端响应 |
| `web/src/utils/coupon.ts` | 券面额/门槛/范围文案工具（percent→折数、min_spend→满减/无门槛） |
| `web/src/views/CouponCenterView.vue` | 领券中心：券形卡片（虚线分界+上下缺口）、面额/门槛/范围/有效期、剩余量进度条、三态按钮（立即领取/已领取/已抢光）、领取成功刷新+Toast、未登录提示+跳登录、空态 |
| `web/src/views/MyCouponsView.vue` | 我的券：全部/未使用/已使用/已过期 Tab + 分页；未使用「去使用」、已使用「查看订单」跳转；临近过期（≤3 天）标红；过期券灰化 |
| `web/src/router/index.ts` | 新增 `/coupons/center`（登录可选）、`/coupons/mine`（requiresAuth） |
| `web/src/views/AccountCenterView.vue` | 个人中心「我的服务」新增 我的优惠券 / 领券中心 入口 |
| `web/src/components/ShopHeader.vue` | 顶栏用户菜单新增 我的优惠券 / 领券中心 快捷项 |
| `web/src/views/DetailView.vue` | 详情页「领券」小标：命中本商品（scope=all / product / category 且可领）的在领券，最多 3 张，点击跳领券中心；接口失败静默降级 |
| `backend/app/Services/Marketing/CouponService.php` | `receivableCoupons()` 增加 `scope_refs` 返回字段（详情页命中过滤用，纯增量） |

## 测试结果

### 前端 Vitest（`vitest-T-038.txt`）

- **12 文件 / 101 用例全绿**（含新增 `coupon-center.test.ts` 9 例、`my-coupons.test.ts` 9 例）。
- 覆盖任务书要求：① 券卡片三态渲染（可领/已领/领完/折扣折数文案）；② 领取成功 → 接口调用 + Toast + 列表刷新同步状态；③ 未登录领取跳登录（不调接口）；④ 我的券 Tab 切换请求参数（unused/expired/null）；⑤ 到期提示渲染（即将过期标红 class）；⑥ 空态。
- 备注：全量并发时 `account-center.test.ts` 出现过 1 次偶发超时（`account-tab-security` 未找到），单独重跑两轮均 7/7 通过、全量复跑 101/101 通过，与本次改动无关（该文件自 T-027 后未再变更逻辑，仅 entries 数组增项）。

### 构建校验（`build-T-038.txt`）

`vue-tsc -b && vite build` 通过。期间修复类型：`couponValueText` 入参 `type` 放宽为 `CouponType | null`（我的券历史数据可能券模板已删）。

### 后端双库回归（`pest-T-038.txt` / `pest-pgsql-T-038.txt`）

因 `CouponService::receivableCoupons()` 增加 `scope_refs` 字段（增量，无形状断言受影响）：

- SQLite：`554 passed (1972 assertions)` / 77.43s
- PostgreSQL（phpunit.pgsql.xml）：`554 passed (1972 assertions)` / 142.50s

## 真浏览器验收（agent-browser，web 5173→3000 端口修正后）

账号 `t038buyer`（API 注册，id=150）；数据：admin 创建券 9「新人立减券 ¥10/满50/限领1」、券 10「会员折扣券 9折/满100/封顶30/限领2」。

1. **领券中心**（`screenshot-T-038-center.png`）：3 张券卡片渲染，面额/门槛/范围/有效期/进度条正确（券10 显示「已领 2/200」为领取后刷新结果）。
2. **领取成功**：点击券 10 领取 → Toast + 列表刷新；`GET /me/coupons` 返回 user_coupon 29（unused）。
3. **限领置灰**：点击券 9（限领 1）领取后卡片按钮变「已领取」置灰（`screenshot-T-038-center-received.png`，DOM 断言 `coupon-received-9` 可见）。
4. **我的优惠券**（`screenshot-T-038-my-coupons.png`）：4 Tab 渲染、2 张持有券（会员折扣券 + 新人立减券）。
5. **详情页领券小标**（`screenshot-T-038-detail-entry.png`）：`/product/1` 显示「领券 9折·满100可用 / ¥20·无门槛 / 更多 ›」。

## 遗留

- 计划中「集成（人工）：领券 → 我的券可见 → **结算页可用**」的结算页环节依赖 T-039（结算页用券），随 T-039 联动补验。
- admin 券 8「d」为开发库历史脏数据（T-033 联调遗留），不影响验收。
