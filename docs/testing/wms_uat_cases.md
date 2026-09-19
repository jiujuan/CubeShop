# CubeShop WMS 联调与异常演练用例（Stage P7 / 菜鸟）

> 阶段目标：把 P0～P6 的菜鸟 WMS 集成在沙箱跑通完整业务闭环，做 9 项异常演练与性能基线，
> 产出联调报告、监控 SOP、运营手册与上线检查单。
>
> 自动化落点：
> - 正向链路 → `backend/tests/Feature/WmsUatForwardFlowTest.php`（TC-UAT-01～04）
> - 异常演练 → `backend/tests/Feature/WmsExceptionDrillTest.php`（DR-01～09）
> - 性能基线 → `backend/tests/Feature/WmsPerfBaselineTest.php`
> - 端到端冒烟 → `docs/testing/smoke_test.sh` 第 5i～5m 段
>
> 沙箱真实联调需菜鸟沙箱/生产账号（回调公网 HTTPS 可达）到位后，把 helper 里的
> `wmsUatGatewaySuccess()` 换成真实网关即可整体重跑——用例与断言无需改动。

---

## 0. 数据集（Step 1）

| 维度 | 内容 |
|---|---|
| 商品 | 3 类：标准品、需 manual 映射品、多 SKU 组合品（覆盖 `sku_mapping_mode=same/manual`） |
| 收货地址 | 2 个：华南（广东省/深圳市）、华北（北京市/朝阳区），回传时用不同承运商（圆通 / 顺丰） |
| 多包裹 | 1 个场景：单订单 2 个物流包裹，验证 `shipping_packages` 落档与首包裹取主运单 |
| 仓库 | 1 个启用仓（联调仓 `WH_UAT_*`）+ 1 个停用仓做 fail-closed 对照 |
| 凭证 | `app_key=UAT_KEY` / `app_secret=UAT_SECRET` / `callback_token` 随机，`api_env=sandbox` |

数据以 `tests/Helpers.php` 中的公共 helper 复现（`wmsUatConfig` / `wmsUatBuyer` /
`wmsUatPlaceAndPay` / `wmsUatConfirmPayload` / `wmsUatReturnConfirmPayload` 等），
正向链路与异常演练共用同一套脚手架，保证演练注入的异常与联调跑的是同一条链路。

---

## 1. 正向链路（Step 2 / F1）

| 用例 | 前置 | 步骤 | 期望 | 实际 | 结论 |
|---|---|---|---|---|---|
| TC-UAT-01 单包裹闭环 | 标准品 + 华南地址 | 下单→支付→推送→回传 confirm→查订单 | 订单 `shipped`、运单号落库、通知发出 | 同期望；`request_id` 与 `duration_ms` 均落 `wms_api_logs` | ✅ |
| TC-UAT-02 多包裹回传 | 多包裹场景 | 回传 2 个 package | 主单取首包裹运单号；`shipping_packages` 全量入档（2 条） | 同期望 | ✅ |
| TC-UAT-03 少件退货 | 已发货单 | 申请退货→审核→推送退货单→回传少件收货 | 库存按实收回加；差异记录；退款原额完成；重复回传不多加 | 同期望（残次 `CC` 不回库存分支也覆盖） | ✅ |
| TC-UAT-04 全链路留痕 | 以上三轮 | 每步记录 `request_id` 与耗时 | 每次调用都有 `request_id` 与 `duration_ms`，且 `request_id` 不重复 | 同期望 | ✅ |

> 联调发现项（已在 P7 修复）：
> 1. **实发数量归集**：多包裹回传时 `DeliveryOrderConfirmHandler` 按 `platform_sku_code` 归集实发数量，
>    漏接时导致 `shipped_qty` 错乱 → 已修正为回传带 `items` 时按编码归集、未带时默认全量。
> 2. **回调同步层留痕**：`WmsCallbackService::receive()` 原先未记录 `request_id` / `duration_ms`，
>    导致「对方回什么、耗时多久」无法检索 → 补 `000092_add_duration_ms_to_wms_api_logs_table`
>    迁移 + 同步层全路径落 `request_id` 与耗时。

---

## 2. 异常演练（Step 3 / F2）

| 演练 | 注入方式 | 期望 | 实际 | 结论 |
|---|---|---|---|---|
| DR-01 推送超时 | 网关 `ConnectionException` | Job 重试至 `tries` 上限 → `push_failed`，`last_push_error` 含「网络异常」且后台可见 | 第 1/2 次停在 `pushing`，第 3 次转 `push_failed`，列表可筛 | ✅ |
| DR-02 重复推送 | 已推送单手工二次推送 | 幂等短路，网关零额外调用 | `Http::assertSentCount` 仍为 1；后台重推返回 409 不触发外呼 | ✅ |
| DR-03 回调丢失 | 屏蔽回传（不调回调入口） | 单据卡 `pushed` → `wms:query-outbound` 主动查询补齐发货 | query 返回 SHIPPED → 按 confirm 语义补录运单+订单发货 | ✅ |
| DR-04 重复回调 | 同一报文重发（换 timestamp 绕过防重放） | 业务幂等吞掉，不重复发货/不重复加库存 | 首回调订单 `shipped`，二次回调被去重，无副作用 | ✅ |
| DR-05 验签失败 | 用原文签名后篡改 body | 返回 failure + 留痕 `SIGNATURE_INVALID`，业务零变更 | 订单未发货、无包裹、作业未入队；审计日志可检索 | ✅ |
| DR-06 缺 SKU 映射 | manual 模式未配编码即下单 | 发货单转 `exception` + 明确原因「未配置 WMS 货品编码」；补齐后重推成功且不阻塞其它单 | 首建单 `exception`；补 `WmsSkuMapping` 后重推 → `pushed` | ✅ |
| DR-07 实收差异 | 回传实收 < 应退 | 库存按实收回加 + 差异记录 + 退款原额完成；重复回传不多加 | 同期望 | ✅ |
| DR-08 开关降级 | 关闭 `auto_push` | 单据停 `created` 不自动推送，后台人工可推 | 下单后状态 `created`；后台 push 接口可驱动 | ✅ |
| DR-09 限流 | 连续高频回调洪水 | 超阈值返回 429；阈值内全部受理，不静默丢单 | 超阈值 429；阈值内全部入队处理 | ✅ |

> 联调发现项（已在 P7 修复）：
> 3. **重推不重解析映射**：`PushOutboundJob` 原先用建单时写入的（空）`wms_sku_code`/`barcode` 组装报文，
>    缺映射转异常后即便补了 `WmsSkuMapping` 也永远推不过去。已新增 `refreshItemCodes()`，
>    推送前按最新映射重算每个 item 的编码（缺失仍抛 `BusinessException` → `push_failed`）。

---

## 3. 性能基线（Step 4 / F3）

| 指标 | 目标 | 实测（沙箱 / fake 网关） | 说明 |
|---|---|---|---|
| 批量推送吞吐 | 单仓 ≥200 单/小时 | 见 `WmsPerfBaselineTest`（200 单顺序推送总耗时 + 平均单耗） | 受 `queue:work` 并发与网关限速约束，详见性能报告 |
| 回调响应 P95 | < 300ms | 回调入口为快进快出（仅落队），P95 远低于阈值 | 重活在异步 `ProcessWmsCallbackJob` |
| 日志写入开销 | 不拖慢主流程 | `wms_api_logs` 走同步落库（单条 INSERT），已在同步层合并 `request_id`/`duration_ms` | 高吞吐下建议评估批量/异步，当前量级无瓶颈 |

> 详见 `backend/tests/Feature/WmsPerfBaselineTest.php`：以 `Http::fake` 模拟网关，
> 顺序驱动 200 张发货单推送并记录耗时分布；回调 50 次采样 P95。沙箱无真实网络，
> 数值用于「相对基线」而非绝对 SLA，生产需以真实网关复测。

---

## 4. 验收映射（Step 3 / §5）

- [x] 正向闭环 3 轮（含多包裹、少件退货）端到端跑通（Pest TC-UAT-01～04）
- [x] 9 项异常演练全部有结果与改进项闭环（Pest DR-01～09，含 3 个联调发现项已修复）
- [x] 性能基线脚本产出（Pest `WmsPerfBaselineTest`）
- [x] 监控指标与 SOP 文档（`wms_monitoring_sop.md`）
- [x] 运营手册与上线检查单（`wms_ops_manual.md`）
- [x] 全量回归零失败（后端 Pest + admin/web vitest + 冒烟 5i～5m）
- [x] README 进度表更新，P8（京东）解锁
