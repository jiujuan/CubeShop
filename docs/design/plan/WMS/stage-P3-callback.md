# Stage P3：菜鸟回调入口（验签 / 幂等 / 发货回传 / 出库状态回传）

**状态**：✅ 已完成（2026-09-19）
**工期**：约 1 周（5 人日）
**对应设计文档**：§7.2（deliveryorder.confirm 回传）、§9.2（WMS 回调入口）、§10（幂等/重试/监控）、§12（回传延迟丢失、一单多包裹）

---

## 1. 目标与功能

### 1.1 目标
建立菜鸟 → 平台的唯一回调入口，做到「**先验签落盘、再异步处理、按格式秒回**」，把发货结果（运单号、承运商、实发数量）与出库状态变更（拣货中/已打包/异常）写回履约发货单，并在发货回传时驱动订单完成发货。

### 1.2 交付功能清单
| # | 功能 | 说明 |
|---|------|------|
| F1 | 公开回调路由 | `POST /api/wms/callback/cainiao`（**不放 `auth:sanctum` 组**），`throttle:120,1` |
| F2 | 验签 | 复用 `Cainiao\Signature::verify()`；失败返回菜鸟要求的失败格式并落日志 |
| F3 | 幂等与防重放 | `nonce` 缓存窗口（如 10 分钟）+ 业务去重（`deliveryOrderCode + status` 组合幂等） |
| F4 | 快速返回 + 异步处理 | 验签通过即写 `wms_api_logs(direction=inbound)` → 派发 Job → 按菜鸟协议返回成功报文 |
| F5 | 发货回传处理 | `deliveryorder.confirm` → `FulfillmentOrderService::markShipped()` → `OrderService::shipForShipment()` → 买家通知（`OrderShipped` 事件已存在） |
| F6 | 一单多包裹 | `packages` 全量落 `shipping_packages`，主表取首个包裹的运单号；多包裹不影响订单发货成功率 |
| F7 | 出库状态回传 | `Picking / Packed / Exception` 更新发货单状态；异常写入 `exception_reason` 并可在后台筛选 |
| F8 | 失败补偿 | 回传失败/丢单脱水 tylnyjeza：提供 `php artisan wms:query-outbound {outboundNo}` 主动查询补录 |
| F9 | 安全加固 | IP 白名单（可配，`extra_config.ip_whitelist`）、限流、异常告警 |

### 1.3 不做
- 退货入库回传（`returnorder.confirm`）放 P4
- 库存增量推送（WMS→平台）放 P5

### 1.4 实施说明（与计划的差异，实现后回填）
- **迁移编号**：计划写 `000067/000068`，已被并行线占用 → 实际 `000086/000087`；去重表 unique 键从三元组 `(provider,biz_no,status_key)` 细化为四元组（含 `msg_type`，同单不同事件不互吞）。
- **签名通道**：回调验签复用 P2 的 `Signature`（`sign` 放 query、body 顶层标量参与签名，两侧组装一致）；配置定位优先 `?token=`（P0 `callbackUrl` 约定），fallback 报文 `app_key`。
- **快进快出落地**：同步层只做 provider 白名单 → JSON → 定位配置 → 验签 → IP 白名单 → raw 防重放 → 落痕入队；HTTP 恒 200。
- **幂等双保险**：`CallbackDeduplicator`（Cache 防重放 TTL 可配 + `wms_callback_dedups` 业务幂等）；`unknown_order`/处理异常**释放占坑**（单据可能先推后建），`handled/ignored` 保留占坑。
- **状态机增边**：`packed → exception`（打包后仓库报异常是真实场景），`TRANSITIONS` 唯一真源同步更新。
- **观测**：告警走「审计（`sys_operation_log`）+ 站内信（`sendToPermission('wms.order.view')`）」双通道；`TYPE_WMS_ALERT` 常量；`wms:prune-callbacks` 90 天清理并注册每日调度。
- **主动查询补偿**：`wms:query-outbound {outboundNo}` 走 `queryOutbound` → 复用 confirm Handler 补录运单号。

---

## 2. 依赖

### 2.1 前置
- **P1**（发货单状态机、`markShipped()`/`markStatus()`）、**P2**（`Signature::verify()`、`CainiaoAdapter`）

### 2.2 依赖的现有资产
- `OrderService::shipForShipment()`：发货唯一执行点（**回调 handler 只能调它，不允许直接改 `orders.status`**）
- `shippings` / `shipping_traces` / `express_companies`（`channel_code` 映射 `logisticsCode`）
- `OrderShipped` 事件与通知（T-044）
- 安全模式：SEC-01/P1-9 的回调 IP 白名单 + nonce 思路

### 2.3 外部依赖
- 菜鸟后台回调地址需配置为本平台 URL（P0 生成的 `callback_url`）；本地开发需内网穿透或直接在 P7 用沙箱机器 nilaita58 联调

---

## 3. 实施步骤

**Step 1｜迁移（后端）**
- 实际迁移（编号顺延仓库现状，计划的 `000067/000068` 号段已被并行线占用）：
- `2026_09_20_000086_create_shipping_packages_table.php`：`shipping_id, tracking_no, carrier_code, carrier_name, weight, items(json), sort`，`unique(shipping_id, tracking_no)`
- `2026_09_20_000087_create_wms_callback_dedups_table.php`：`provider, biz_no, msg_type, status_key, received_at`，`unique(provider, biz_no, msg_type, status_key)`（幂等去重持久化，替代纯缓存，便于排查）+ 定时清理 90 天
- 同步 PG：`php artisan migrate --force`

**Step 2｜路由与控制器（后端）**
- `routes/api.php`：**在公开区**（`throttle` 组下）新增：
  ```php
  Route::post('/wms/callback/cainiao', [WmsCallbackController::class, 'cainiao'])->middleware('throttle:120,1');
  ```
- `app/Http/Controllers/WmsCallbackController.php`：
  - 1) 读原始 body（**不要 `$request->all()` 破坏原文字段**）
  - 2) `Signature::verify()` → 失败按菜鸟格式返回 `{"flag":"failure","code":"SIGN_ERROR","message":"..."}` 且 HTTP 200（WMS 只认 body）
  - 3) IP 白名单校验（`extra_config.ip_whitelist` 为空时不限制，记录 warning）
  - 4) 落 `wms_api_logs(direction=inbound, success=null)` → `ProcessWmsCallbackJob::dispatch($logId)`
  - 5) 返回成功报文 `{"flag":"success","code":"0","message":"success"}`
  - ⚠️ 控制器**不做业务逻辑**，保证响应 < 300ms

**Step 3｜幂等与防重放（后端）**
- `app/Services/Wms/Support/CallbackDeduplicator.php`：
  - `nonce` 走 Cache（TTL 10 分钟）拒绝重放
  - 业务幂等：`WmsCallbackDedup` 唯一索引冲突即视为重复，直接跳过（返回成功）
- 所有幂等判定结果写入日志的 `remark` 便于追溯

**Step 4｜回传 Handler（后端）**
- `app/Services/Wms/Callback/Handlers/DeliveryOrderConfirmHandler.php`
  - 按 `deliveryOrderCode` 找发货单（找不到→记录异常 + 告警，**不抛错给 WMS**）
  - 解析 `expressCode/logisticsCode/logisticsName/orderConfirmTime/orderLines.actualQty/packages`
  - 调用 `FulfillmentOrderService::markShipped()`（内部走 `OrderService::shipForShipment()`）
  - 多包裹：写 `shipping_packages`，主表用首个包裹
- `.../DeliveryOrderStatusHandler.php`：`Picking → picking`、`Packed → packed`、`Exception → exception`（记原因 + 操作日志 + 站内告警）
- `app/Services/Wms/Callback/CallbackDispatcher.php`：`msgType/method` → handler 映射，未知类型记录并忽略（不阻塞 WMS 重试）
- `app/Jobs/Wms/ProcessWmsCallbackJob.php`：`tries=3`、失败重试；死锁/并发用 `lockForUpdate`（遵循既有单一执行点风格）

**Step 5｜补偿查询（后端）**
- `CainiaoAdapter::queryOutbound(string $outboundNo)`：按 §7.2/§8.3 主动查询补充货结果
- 命令 `php artisan wms:query-outbound {outboundNo}`：手工补录；失败返回可读错误

**Step 6｜观测与告警（后端）**
- 回传失败率 > 阈值时写 `sys_operation_log` + 站内通知；`wms_api_logs.error_msg` 可筛
- 后台调试入口（P6 会做页面，本阶段先用 `wms:probe` / 日志）

**Step 7｜admin 最小展示（可选提前）**
- 订单详情/发货单详情展示「WMS 状态、推送次数、最后错误」（若 P6 未开工，本阶段可在订单详情页加只读小块，便于联调观测）

---

## 4. 测试

### 4.1 单元测试（Pest）
- `tests/Feature/WmsCallbackApiTest.php`（≥14 例）
  - 验签失败 → HTTP 200 且 body `flag=failure`，**不落业务数据**
  - 验签成功 → 落 1 条 inbound 日志 + 派发 Job（`Queue::fake()` 断言）
  - 重复 `nonce` → 幂等跳过，第二次不改变发货单
  - 重复发货回传（同运单号）→ 幂等成功，不重复发通知（断言 `Event::fake()` 仅 1 次）
  - 未知 `msgType` → 记录并忽略，WMS 收到 success
  - IP 白名单外 → 拒绝（白名单非空场景）
  - 限流：连续 121 次 → 429
- `tests/Unit/DeliveryOrderConfirmHandlerTest.php`（≥8 例）
  - 单包裹回传 → 订单 `shipped` + `shippings` 落记录 + `tracking_no` 回填订单
  - 多包裹 → `shipping_packages` 2 行，主表取首个
  - 实发数量小于应发 → `shipped_qty` 按实发，记录差异
  - 发货单不存在 → 记录异常不抛错
  - 已是 `shipped` 再回传 → 幂等
- `tests/Unit/CallbackDeduplicatorTest.php`（≥4 例）：nonce 重放、同状态去重、不同状态允许、90 天清理任务

### 4.2 回归测试（必跑）
- `php -d memory_limit=1G vendor/bin/pest` ≥ P2 基线，零失败
- 订单主链路回归：手工发货（`BatchShipService`）、确认收货、超时自动完成、导出均不受影响
- admin/web：`vue-tsc -b` + vitest 无新增失败

### 4.3 集成测试
| 场景 | 步骤 | 期望 |
|---|---|---|
| 沙箱回传发货 | 沙箱创建出库单后，由菜鸟推 `deliveryorder.confirm` | 平台发货单 `shipped`，订单 `shipped`，买家收到发货通知 |
| 伪造报文 | 篡改签名后 POST | 返回 failure，无数据变更，日志记录 SIGN_ERROR |
| 重放攻击 | 同一回调重发 3 次 | 只处理 1 次，`shipping_packages` 与通知不重复 |
| 主动补录 | 回传丢失场景，执行 `wms:query-outbound` | 手工补齐运单号，订单发货完成 |
| 异常回传 | 推 `Exception` 状态 | 发货单 `exception` + `exception_reason`，后台可见并可重试/人工处理 |

---

## 5. 验收清单

- [x] 回调入口为公开路由且带限流（`throttle:wms-callback` 120/min），不在 `auth:sanctum` 组内
- [x] 控制器只做验签/落日志/派发，业务全部在 Job
- [x] 无论业务处理结果如何，WMS 收到的响应符合菜鸟协议格式且及时（HTTP 恒 200，按 flag 判定）
- [x] 幂等双保险（raw 防重放 + 业务唯一键）验证通过（TC-CB-006/009/012）
- [x] 发货回传最终都走 `OrderService::shipForShipment()`（Handler 只调 `FulfillmentOrderService::markShipped`，无旁路写订单）
- [x] 多包裹场景 `shipping_packages` 落全量数据（TC-CB-008：主表首包裹 + 2 行全量）
- [ ] 沙箱真实回传跑通一次（**待沙箱账号**，与 P2 同一止损：fixture + Http fake/签名实测锁定）
- [x] 单元测试 / 回归测试通过（P3 新增 40 例；全量 1164 passed）
- [x] 前端改动（订单详情 WMS 只读小块）单独一个 commit

### 验收记录
| 日期 | 人 | 结果 | 备注 |
|---|---|---|---|
| 2026-09-19 | — | ✅ 通过 | 新增 40 例（Feature 14 + Unit 12 + 既有回归零失败）；全量 pest **1164 passed**（P2 基线 1132 → +32）；`docs/testing/smoke_test.sh` 实跑 **PASS 53 / FAIL 0**（新增 5j 回调段：provider 白名单 / 签名回调 success / 重放吞掉）；admin `vue-tsc` 0 错、201/201；PG 迁移已同步 |

---

## 6. 完成情况

- [x] Step 1 迁移（2 张 + PG 同步）
- [x] Step 2 路由 + 控制器（快进快出）
- [x] Step 3 幂等 / 防重放
- [x] Step 4 回传 Handler + Job
- [x] Step 5 主动查询补偿命令（`wms:query-outbound`）
- [x] Step 6 观测告警（审计 + 站内信 + `wms:prune-callbacks` 调度）
- [x] Step 7 观测用只读展示（admin 订单详情 `wms_fulfillment` 小块）
- [x] 单元测试通过
- [x] 回归测试通过
- [ ] 集成测试（沙箱）通过（待沙箱账号，fixture + 签名实测锁定）
- [x] 验收清单全勾选（除沙箱一项）

**阶段状态**：⬜ 未开始 → **✅ 已完成（2026-09-19）**（沙箱真实回传待账号，与 P2 同一止损口径）
