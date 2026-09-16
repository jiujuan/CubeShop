# T-040 证据 — 管理后台营销管理页（优惠券 + 满减活动）

- 时间：2026-09-16 ~ 2026-09-17
- 任务：Phase2_Tasks.md T-040 [ADMIN] 管理后台营销管理页（优惠券 CRUD/停发/统计/导出 + 满减活动 CRUD/启停）
- 结论：✅ 完成，admin Vitest 82/82（新增 9 例）、admin build 通过、SQL 核对一致、浏览器验证通过

## 交付物

### 前端（admin，无后端改动）

| 文件 | 说明 |
|---|---|
| `admin/src/api/marketing.ts` (NEW) | 优惠券/满减活动全量 API 封装（含 CSV blob 导出）与类型 |
| `admin/src/components/marketing/CouponPanel.vue` (NEW) | 券列表（类型/面额/门槛/范围/有效期/发放领取核销/核销率/状态/操作）、创建编辑表单（fixed↔percent 联动、scope 选择器、valid_type 相对/绝对切换、已发放锁定 LOCKED_AFTER_ISSUED 白名单）、统计抽屉（8 项指标卡）、停发确认、CSV 导出 |
| `admin/src/components/marketing/PromotionPanel.vue` (NEW) | 满减列表（梯度文案/时间窗/状态）、创建编辑表单含**梯度编辑器**（严格递增本地校验，镜像后端 assertRules）、启停确认 |
| `admin/src/views/operation/MarketingView.vue` (NEW) | 双 Tab 容器（优惠券/满减活动） |
| `admin/src/router/index.ts` | 新增 `/marketing`（菜单，permission `marketing.manage`） |
| `admin/src/layouts/AdminLayout.vue` | 新增「运营管理」菜单组（评价管理 + 营销管理） |

### 测试

- `admin/tests/marketing-admin.test.ts` (NEW, 9 例)：类型切换字段联动 / 已发放锁定（disabled + payload 白名单 `expect.not.objectContaining`）/ 梯度编辑器增删与递增校验 / scope_refs 组装与本地校验 / 统计抽屉 / v-permission 权限移除 + 双 Tab 切换 / 券列表渲染 / 满减列表渲染 / 停发与启停确认
- 结果：**admin Vitest 82/82 通过**（82 = 原 73 + 新 9）
- `npm run build` 通过（修复 2 处 TS：payload 类型断言、未用 import）

## SQL 核对

见 `sql-T-040.md`：券 10 API 统计与 PostgreSQL dev 库原始数据完全一致（issued 3 / received 3 / used 1 / use_rate 0.3333 / order_count 1 / discount_sum 29.90 / order_amount_sum 169.10，订单 37 金额自洽 199.00−29.90=169.10）。

## 浏览器验证（admin @ 5173，admin/Admin@123）

- 截图 `screenshot-T-040-coupon-form.png`：新建优惠券表单（类型/面额/门槛/发放总量/每人限领/适用范围/有效期类型）
- 截图 `screenshot-T-040-stats.png`：券 10「会员折扣券」券效统计抽屉 — 发放总量 200、领取量 3、核销量 1、核销率 33.3%、领取率 1.5%、带来订单数 1、优惠总金额 ¥29.90、订单实付总额 ¥169.10，与 SQL 核对一致
- 「运营管理」菜单组正确显示营销管理入口，双 Tab 切换正常

## 后端说明

T-040 纯 admin 前端任务，复用 T-032 `Admin\CouponController` 与 T-033 `Admin\PromotionController` 已有接口，无后端改动；当前后端已在 T-039 完成双库回归（SQLite + PostgreSQL 各 558 例通过），无需重跑。
