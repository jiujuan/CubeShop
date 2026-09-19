# CubeShop WMS 运营手册与上线检查单（Stage P7 / F5·F6）

---

## 一、后台运营操作

入口：管理后台 → 侧栏「仓库与物流」（履约中心）。

### 1. 仓库与 WMS 配置
- **路径**：仓库管理 → 某仓库 → WMS 配置。
- **关键字段**：
  - `provider`：菜鸟（`cainiao`）；
  - `enabled`：总开关，关掉后该仓订单不进 WMS 履约链路；
  - `auto_push`：下单支付后是否自动推送出库单（关 → 单据停 `created`，人工可推）；
  - `auto_push_return`：退货是否自动推送退货入库单；
  - `push_retry_times`：推送重试上限（超出转 `push_failed`，别设太大以免打爆网关）；
  - `sku_mapping_mode`：`same`（平台 SKU 编码直用）或 `manual`（需配 `WmsSkuMapping`）；
  - `api_env`：沙箱/生产（仅决定网关地址，凭证齐备才走真链路）；
  - `app_key` / `app_secret` / `callback_token`：脱敏展示，编辑即密文落库。
- **连通性自测**：配置页「测试」按钮 → 等价于 `php artisan wms:probe`。

### 2. SKU 货品编码映射（manual 模式必填）
- **路径**：仓库 → SKU 映射 → 批量导入。
- 每行：`sku_code`（平台）、`wms_sku_code`（菜鸟货品编码）、`barcode`、状态。
- **缺映射的后果**：建单转 `exception` + 原因「未配置 WMS 货品编码」；补映射后后台「重推」即恢复（P7 已修：重推重新解析映射）。

### 3. 发货单管理
- 列表（权限 `wms.order.view`）、详情（含推送日志流水）、**重推**（幂等，已推送返回 409 不重复外呼）、**取消**（出库前可取消，落审计）。
- 批量：`batch-push` 对勾选的单据批量重推。

### 4. 退货入库单
- 列表（权限 `wms.return.manage`）、详情、人工收货（含超收 `exception→received/pending_push` 边）。

### 5. WMS 调用日志
- 列表 + 详情（最近 5 条流水），报文出口再过 `PayloadMasker` 脱敏 + 超长截断；`app_key` 有意不掩。

### 6. 关闭自动推送（降级）
- 把仓库配置 `auto_push=false` → 现有及后续单据停 `created`，由运营在波次内批量 `batch-push`。

---

## 二、生产上线检查单（切换前置）

| # | 检查项 | 确认方式 | 状态 |
|---|---|---|---|
| 1 | 生产 `app_key` / `app_secret` 已录入且 `wms:probe` 通过 | 后台测试按钮 / `wms:probe` | ☐ |
| 2 | 回调地址公网可达且为 **HTTPS** | 外网 curl `https://<域名>/api/wms/callback/cainiao` | ☐ |
| 3 | 回调 IP **白名单**已配（或关闭 IP 校验并依赖签名） | 核对 `wms_configs` 与菜鸟后台 | ☐ |
| 4 | `callback_token` 与菜鸟后台回调 URL 中的 `token` 一致 | 比对双方 | ☐ |
| 5 | 常驻队列 worker 已起（`wms` 队列优先） | `queue:work --queue=wms,default` 进程存活 | ☐ |
| 6 | SKU 映射（manual 模式）已全量导入 | 映射列表核对在售商品 | ☐ |
| 7 | `wms_api_logs` 保留策略 / 备份已定（含脱敏） | 确认日志保留期与归档 | ☐ |
| 8 | 密钥轮换计划（app_secret 90 天一轮） | 写入运维日历 | ☐ |
| 9 | 告警通道（审计 + 站内信）接收人已配（`wms.order.view` 持有者 + operator） | 触发一次测试告警 | ☐ |
| 10 | 沙箱回归 + 本次 P7 联调/演练报告归档 | `docs/testing/wms_uat_cases.md` | ☐ |

> 说明：沙箱真实联调（菜鸟账号到位后）只需把 `tests/Helpers.php` 的 `wmsUatGatewaySuccess()`
> 换成真实网关，所有正向/异常用例与断言无需改动即可整体重跑，是上线前的最后一道闸门。
