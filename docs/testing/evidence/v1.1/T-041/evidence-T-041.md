# T-041 证据 — 优惠券并发与金额矩阵专项测试

- 时间：2026-09-17
- 任务：Phase2_Tasks.md T-041 [QA]（F06，依赖 T-036）
- 结论：✅ 全部通过 — 金额矩阵 32/32（428 断言）、并发 A~D 全 PASS、数据核对一致、无超发无资损

## 1. 金额矩阵（参数化测试，已固化为 Pest）

文件：`backend/tests/Feature/CouponMatrixQaTest.php`

- 主矩阵 24 例：{无券 / 满减 / 券 / 券+满减} × {单行 / 多行} × {整单取消 / 全额退款 / 部分退款}
- 门槛边界 8 例：{券 / 券+满减} × {单行 / 多行} × {刚好达标 / 差 1 分}
- 金额口径：goods=100.00；测试库未启用满额免运费 → 运费 10.00；满减 min100−12；券 fixed20 min100
- 断言内容（每例）：
  - 应付金额 = goods − 满减 − 券 + 运费（none 110 / promo 98 / coupon 90 / both 78）
  - 行分摊不变量：Σ coupon_share = 券优惠、Σ promotion_share = 满减优惠、Σ payable + freight = pay
  - 退态：取消 → 订单 cancelled + 券返还 unused + used_count=0；全额退 → 退款金额 = pay_amount + 券返还；部分退（10.00）→ 退款金额正确 + 券保持 used（防资损）
  - 门槛边界：刚好达标通过且金额正确；差 1 分 409（40009 未满门槛）+ 无订单 + 券未被占用

结果：**32 passed (428 assertions)**，SQLite。

## 2. 并发场景 A~D（脚本 + 手动触发说明）

脚本：`backend/scripts/coupon-concurrency.php`（curl_multi 真并发，直接 bootstrap 造用户/券/令牌）

启动方式（真并发需多 worker）：

```bash
cd backend && PHP_CLI_SERVER_WORKERS=16 php artisan serve --host=127.0.0.1 --port=8001
php scripts/coupon-concurrency.php http://127.0.0.1:8001
```

结果（16 worker，报告归档 `concurrency-report.json`）：

| 场景 | 设计 | 结果 | 断言 |
|---|---|---|---|
| A | 限量 10，200 用户并发领取 | 成功 10，拒绝 190（已领完），issued_count=10，user_coupons=10 | PASS，无超发 |
| B | 同一用户并发领 10 次（限领 1） | 成功 1，拒绝 9（已达每人限领数量），记录仅 1 条 | PASS |
| C | 同一张券被 2 个订单并发使用 | 1 单成功用券（coupon_id 落库），另 1 单被拒；uc=used 且 used_order_id 唯一 | PASS，无重复核销 |
| D | 库存仅剩 1，2 用户并发领取 | 1 成功 1 拒，issued=5 无负数 | PASS |

说明：
- 场景 A 首跑曾出现 38 个 http_0（built-in server 连接层失败）；脚本对连接层失败**重试一次**后 200 个请求全部到达业务层，重试不影响「无超发」结论（仍受限量原子更新保护）。
- 失败原因分布全部为业务正确拒绝（已领完/已达限领），无 500、无异常错乱。

## 3. 数据核对 SQL

见 `sql-T-041.md`：发放数、核销数、券状态分布、订单金额一致性、无负数/脏数据 5 组核对全部一致（场景 D 两处 issued 差额为脚本造数预置，已解释，非缺陷）。

## 4. V1.0 全量回归

| 套件 | 结果 |
|---|---|
| backend Pest（SQLite 全量，含本任务 32 例） | 590 passed (2415 assertions) |
| backend Pest（PostgreSQL `cubeshop_test`，phpunit.pgsql.xml） | 590 passed (2415 assertions) |
| web Vitest | 109/109（首跑 account-center 1 例负载 flaky，单跑 7/7、重跑全量 109/109 通过） |
| admin Vitest | 82/82 |

（原始输出见 `regression-T-041.txt`）

## 5. 结论

- 优惠券功能未造成超发（A/D）、未造成重复核销（C）、未造成资损（部分退不返券 + 回调金额校验）。
- 金额矩阵全组合通过，行分摊 Σ 恒等于总优惠，退款金额与券状态流转符合设计。
- 未发现缺陷，无需开单。
