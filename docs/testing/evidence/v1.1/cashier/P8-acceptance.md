# 收银台与支付渠道方案 · P8 测试与验收（阶段出口报告）

**日期**：2026-09-16
**阶段**：P8 测试与验收（收银台方案 §8 / §11）
**方案文档**：[CubeShop_Cashier_Payment_Design_v1.0.md](../../../design/CubeShop_Cashier_Payment_Design_v1.0.md)

## 1. 结论

收银台与支付渠道方案 **P1 ~ P8 主体实现完成**：数据层、适配层、真实渠道网关、后台配置、前台收银台、余额充值、可靠性调度全部落地，并通过双库全量回归、前端全量用例、生产构建与真实 HTTP 冒烟。

> **唯一未执行项**：支付宝沙箱联调（L2）与微信 1 分钱验收（L3）依赖真实沙箱/商户凭证，本环境不可执行，留待运维联调。L1 本地 Mock 网关已覆盖全部业务逻辑（四种支付方式 + 充值 + 核账 + 查单补偿 + 超时关单）。

## 2. 验证矩阵（阶段出口）

| 项 | 命令 | 结果 |
|----|------|------|
| 后端全量（SQLite） | `php artisan test` | ✅ **416 passed**（1366 assertions） |
| 后端全量（PostgreSQL） | `php artisan test -c phpunit.pgsql.xml` | ✅ **416 passed**（1366 assertions） |
| 前端全量（Vitest） | `npm run test`（web） | ✅ **83 passed**（10 files） |
| 前端类型 + 构建 | `npm run build` | ✅ 通过（`vue-tsc -b` + `vite build`） |
| 真实 HTTP 冒烟 | `bash docs/testing/smoke_test.sh`（真实 PG 开发库 + `artisan serve`） | ✅ **PASS 32 / FAIL 0** |
| 调度校验 | `php artisan schedule:list` | ✅ `payments:cancel-timeout`、`payments:sync-pending` 每分钟 |

## 3. 本轮（P8）新增用例

| 文件 | 数量 | 覆盖 |
|------|------|------|
| `backend/tests/Unit/BalanceRechargeServiceTest.php` | 11 | 赠送规则最高档 / 空与非法 JSON 不送 / 建单与 PayParams / 最小金额 / 单笔上限 / 单日累计 / 非法金额 / 不支持余额 / 开关关闭 / 单日累计口径 / 记录按用户隔离 |
| `backend/tests/Unit/NoGeneratorServiceTest.php`（扩充） | +2 | RC 前缀格式 / `generateRechargeNo()` |
| `web/tests/cashier.test.ts` | 6 | 收银台渠道渲染 / 余额不足置灰与「去充值」/ 线下收款账户展开 / 结果页充值成功文案与按钮 / 待核账与备注 / 处理中轮询 |

### 累计支付域用例（P1~P8）

- 单元：`PaymentGatewayTest`、`PaymentServiceTest`、`PaymentServiceCloseTest`、`RealChannelGatewayTest`、`BalanceRechargeServiceTest`、`NoGeneratorServiceTest`
- 集成：`PaymentAdminApiTest`、`PaymentChannelAdminTest`、`AdminBalanceRechargeTest`、`OrderLogAdminApiTest`、`CashierApiTest`、`RechargeApiTest`、`PaymentReliabilityTest`
- 前端：`cashier.test.ts`、`recharge.test.ts`（+ 回归 `account-center.test.ts` 等）

## 4. 冒烟新增用例（真实 HTTP）

`docs/testing/smoke_test.sh` 新增 **5h. 余额充值（收银台 P6）** 共 7 项，全部通过：

余额查询 → `scene=recharge` 渠道列表 → 发起充值 → 沙箱支付回调 → 到账 ¥100.00 → 充值记录 → 余额流水。

累计冒烟 **32/32 PASS**（含一期 25 项 + 收银台 7 项）。

## 5. 外部联调待办（不阻塞交付）

| 项 | 说明 | 前置 |
|----|------|------|
| 支付宝沙箱联调（L2） | 真实 RSA2 验签 / 回调 / 查单链路 | 沙箱应用 + 沙箱买家账号（后台渠道配置 `sandbox=true`） |
| 微信 1 分钱验收（L3） | 微信 V3 Native 真实下单/回调 | 真实商户号 + 商户私钥/证书（后台渠道配置齐全） |

配置路径：后台「支付渠道配置」页 → 对应渠道填入商户参数并关闭沙箱 → 收银台即可走真实网关（`PaymentGatewayFactory` 按 `sandbox` + 配置完整性自动切换）。

## 6. 关联提交

- `feat(payment): P8 测试与验收（单元/前端补齐 + 冒烟扩展 + 验收报告）`（见 `git log`）
- 系列提交：P1 `337e3e5` / P2 同批 / P3 `c5c2912` / P4 `d663871` / P5 `24c2e93` / P6 `45e8b69` / P7 `d6b14d8`
