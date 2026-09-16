# CubeShop 收银台与支付渠道配置 技术方案 v1.0

> 目标：前端收银台支持 **微信支付 / 支付宝 / 余额支付 / 线下转账** 四种方式；后台可配置各渠道的接口地址、回调地址、商户号、密钥/证书等参数，并可在沙箱环境完成端到端联调。
>
> 适用版本：Laravel 13 + PHP 8.3（后端）、Vue 3 + Vite（web / admin）。

---

## 1. 结论先行：五个关键设计决策

| # | 决策 | 理由 |
|---|---|---|
| 1 | **新建 `payment_channels` 表存渠道配置，不塞进 `system_configs`** | `system_configs.config_value` 是 `varchar(255)`，装不下商户私钥/证书；且 KV 结构无法做「敏感字段加密」「密钥回显脱敏」「连接测试」。`system_configs` 只保留业务开关（超时分钟、是否开启余额支付等） |
| 2 | **引入渠道适配层 `PaymentGateway` 接口，各渠道独立实现** | 现在 `PaymentService` 里硬编码 `['wechat','alipay']` 与 HMAC 模拟，每加一个渠道就要改主流程。抽象成接口后，余额/线下转账/沙箱 Mock 同样是「网关」，主流程零改动 |
| 3 | **微信支付没有官方沙箱，不能与支付宝同等对待** | 支付宝有正式沙箱环境（独立网关 + 沙箱买家账号）；微信支付 V2 时代的 sandbox 收单接口已下线，**V3 无沙箱**。因此沙箱分两层：L1 自研 Mock 网关（本地/CI 零依赖）、L2 渠道沙箱（仅支付宝）+ 微信用「1 分钱真实支付验收」 |
| 4 | **线下转账状态进 `payments` 表，不新建平行表** | 线下转账本质上是一笔支付单，只是入账方式不同。在 `payments` 上扩展 `reviewing`（待核账）状态与凭证字段，支付管理页可统一查看，避免「支付单 / 转账单」两套列表 |
| 5 | **收银台与结果页拆成两个路由** | 现状 `PayView.vue`（`/orders/:id/pay`）把渠道选择、轮询、成功态全塞在一页，无法承载 4 种支付方式与「处理中/待核账」态。拆为 `/orders/:id/pay`（收银台）→ `/pay/result/:payment_no`（结果页）→ `/orders/:id`（订单详情） |
| 6 | **余额充值走「充值单 → 收银台 → 入账」，资金流仍统一落在 `payments`** | 充值不是订单（无库存、无收货、不应进订单统计），需要独立的 `balance_recharges` 单据；但**钱必须有且只有一本账**——把 `payments.order_id` 改为可空并增加 `biz_type/biz_no`，充值支付同样生成支付单，回调入口、支付管理页、查单补偿、对账链路全部复用，不出现第二套资金流 |

### 1.1 已确认的业务决策（2026-09-16）

| 项 | 结论 | 落地位置 |
|---|---|---|
| 微信支付方式 | **Native 扫码（PC 网站）**，不做 JSAPI / 小程序 | §6.2 微信行、§9.1 收银台二维码；无需 openid 获取流程 |
| 余额充值 | **需要充值入口**（在线支付 + 线下转账两种方式） | §4.1 `balance_recharges`、§6.5 充值流程、§9.4 充值页 |
| 线下收款账户 | **单账户**（一套开户行 / 户名 / 账号） | §4.3 `payment.offline_receipt` 单对象 JSON，后台单表单维护 |
| 证书 / 密钥录入 | **文本域 + 支持上传 .pem 自动读入** | §5.1 配置抽屉 |
| 支付成功页 | **不展示推荐商品** | §9.2 结果页仅保留「查看订单详情 / 返回首页」 |

---

## 2. 现状盘点

### 2.1 已有能力（可复用）

| 能力 | 位置 | 说明 |
|---|---|---|
| 支付单 / 支付日志 | `payments` + `payment_logs` 表 | 已有 `pending/success/failed/closed` 状态与 `create/callback/notify/close` 事件日志 |
| 支付主流程 | `PaymentService::createPayment()` | 同订单待支付单复用、金额校验、幂等、事务内确认扣减库存、支付成功派发 `OrderPaid` 事件 |
| 回调入口 | `POST /payments/callback/{channel}` | 现有 HMAC-SHA256 验签 + 幂等 + 金额一致性校验，逻辑正确可保留 |
| 沙箱模拟 | `POST /payments/sandbox/{paymentNo}` | HMAC 自签后走真实回调链路，可作为 L1 Mock 网关的基础 |
| 订单状态机 | `Order::STATUS_TRANSITIONS` + `OrderService::transitionTo()` | `pending_payment → paid → shipped → completed / refunding`，并写 `order_logs` |
| 系统配置 | `system_configs` + `ConfigService`（带缓存） | 已有 `order.timeout_minutes` 等业务开关 |
| 后台支付管理页 | `admin/src/views/order/PaymentView.vue` | 已有列表/详情/关闭/导出，本方案在其上扩展「核账」与「渠道配置」入口 |
| 图片上传 | `POST /admin/upload`（`UploadController`） | 需扩展为支持凭证类图片且开放给前台（见 §5.4） |

### 2.2 缺口

1. 无渠道配置存储与管理界面，密钥硬编码在 `config/payments.php` 和环境变量里。
2. `createPayment()` 硬编码只支持 `wechat` / `alipay`，且 `buildPayParams()` 永远返回 `mode: 'sandbox'`。
3. **无用户余额账户**（`SysUser` 无 `balance` 字段），余额支付与**充值入口**需从零建。
4. 无线下转账凭证与核账流程。
5. 收银台单页混合渠道选择与结果展示，无「处理中 / 待核账」态。
6. 无主动查单补偿（回调丢失时订单会一直挂起），无定时关单任务（`routes/console.php` 中未注册调度）。

---

## 3. 总体架构

### 3.1 分层

```
┌───────────────────────── 前端 web ─────────────────────────┐
│ /checkout → /orders/:id/pay(收银台) → /pay/result/:no(结果页) → /orders/:id │
└────────────────────────────┬───────────────────────────────┘
                             │ REST
┌────────────────────────────┴───────────────────────────────┐
│ Controller 层                                                │
│   PaymentController(前台)  Admin\PaymentChannelController     │
│   Admin\PaymentController(核账/关闭/查询)                     │
└────────────────────────────┬───────────────────────────────┘
                             │
┌────────────────────────────┴───────────────────────────────┐
│ PaymentService（编排层：建单 → 调网关 → 落日志 → 驱动订单状态机） │
└────────────────────────────┬───────────────────────────────┘
                             │ PaymentGateway 接口
   ┌───────────┬───────────┬─┴────────┬───────────┬───────────┐
   │WechatGw   │AlipayGw   │BalanceGw │OfflineGw  │MockGw(L1) │
   │(V3 验签)  │(RSA2 验签)│(余额账户) │(凭证+核账) │(HMAC 模拟)│
   └───────────┴───────────┴──────────┴───────────┴───────────┘
                             │
┌────────────────────────────┴───────────────────────────────┐
│ 数据层：payments / payment_logs / payment_channels /          │
│         user_balances / user_balance_logs / order_logs        │
└─────────────────────────────────────────────────────────────┘
```

### 3.2 核心接口定义

```php
namespace App\Services\Payment\Contracts;

interface PaymentGateway
{
    /** 渠道标识：wechat / alipay / balance / offline / mock */
    public function channel(): string;

    /**
     * 发起支付：返回给前端的调起参数
     * @return PayParams 见 §6.3（不同渠道形态不同：二维码 / 表单 / 直接完成 / 待上传凭证）
     */
    public function create(Payment $payment, Order $order, array $config): PayParams;

    /**
     * 解析并验签渠道回调，返回标准化结果
     * @return CallbackResult{ok, channel_trade_no, amount, status, raw}
     */
    public function verifyCallback(Request $request, array $config): CallbackResult;

    /** 主动查单（回调丢失补偿、结果页轮询兜底） */
    public function query(Payment $payment, array $config): QueryResult;

    /** 原路退款（余额/线下退回余额或人工处理，见 §7.5） */
    public function refund(Payment $payment, string $amount, string $reason, array $config): RefundResult;

    /** 配置连通性自检（后台「测试连接」按钮） */
    public function testConnection(array $config): TestResult;
}
```

**关键约束**：网关实现**不得直接改订单状态**，只返回标准化结果；订单状态流转仍由 `PaymentService` 调用 `OrderService::transitionTo()` 统一驱动 —— 保持现有「order_logs 唯一写入点」的审计约定。

---

## 4. 数据模型

### 4.1 新增表

#### （1）`payment_channels` —— 渠道配置

```sql
CREATE TABLE payment_channels (
  id            BIGSERIAL PRIMARY KEY,
  channel       VARCHAR(32)  NOT NULL UNIQUE,   -- wechat/alipay/balance/offline/mock
  name          VARCHAR(64)  NOT NULL,          -- 展示名：微信支付
  enabled       BOOLEAN      NOT NULL DEFAULT false,  -- 是否对前台开放
  sandbox       BOOLEAN      NOT NULL DEFAULT true,   -- 是否走渠道沙箱
  sort          INTEGER      NOT NULL DEFAULT 0,
  config        JSONB        NOT NULL DEFAULT '{}',   -- 加密后的渠道参数（见 §5.3）
  notify_url    VARCHAR(255) NOT NULL,          -- 回调地址（后台可改，展示为只读 + 复制）
  return_url    VARCHAR(255) NULL,              -- 支付后前端回跳地址
  remark        VARCHAR(255) NULL,
  updated_by    BIGINT       NULL,
  timestamps
);
```

#### （2）`user_balances` —— 余额账户（每用户一行）

```sql
CREATE TABLE user_balances (
  user_id         BIGINT PRIMARY KEY,
  balance         DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- 可用余额
  frozen          DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- 冻结（预留，退款/提现中）
  total_recharge  DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- 累计充值
  total_consume   DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- 累计消费
  version         INTEGER      NOT NULL DEFAULT 0,      -- 乐观锁
  timestamps
);
```

#### （3）`user_balance_logs` —— 余额流水（只增不改）

```sql
CREATE TABLE user_balance_logs (
  id             BIGSERIAL PRIMARY KEY,
  user_id        BIGINT       NOT NULL,
  type           VARCHAR(32)  NOT NULL,  -- recharge/consume/refund/admin_adjust
  amount         DECIMAL(12,2) NOT NULL,  -- 正=入账，负=出账
  balance_before DECIMAL(12,2) NOT NULL,
  balance_after  DECIMAL(12,2) NOT NULL,
  related_type   VARCHAR(32)  NULL,      -- order/payment/refund/offline_transfer
  related_id     BIGINT       NULL,
  remark         VARCHAR(255) NULL,
  created_by     BIGINT       NULL,      -- 后台调整时记录管理员
  created_at     TIMESTAMP    NULL,
  INDEX (user_id, created_at), INDEX (related_type, related_id)
);
```

#### （4）`balance_recharges` —— 余额充值单

> 充值不是订单：无库存、无收货地址、不应进订单统计与报表，因此独立成单；资金流仍走 `payments`（见 §4.2）。

```sql
CREATE TABLE balance_recharges (
  id             BIGSERIAL PRIMARY KEY,
  recharge_no    VARCHAR(64)  NOT NULL UNIQUE,   -- RC20260916000001
  user_id        BIGINT       NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,         -- 充值本金
  gift_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- 赠送金额（赠送规则命中时）
  channel        VARCHAR(32)  NOT NULL,          -- wechat/alipay/offline/mock
  status         VARCHAR(32)  NOT NULL,          -- pending/reviewing/success/failed/closed
  payment_id     BIGINT       NULL,              -- 关联支付单（成功/失败后回填）
  -- 线下转账充值专用（在线充值为空）
  payer_name     VARCHAR(64)  NULL,
  payer_account  VARCHAR(128) NULL,
  transfer_no    VARCHAR(128) NULL,
  transferred_at TIMESTAMP    NULL,
  voucher_url    VARCHAR(255) NULL,
  review_remark  VARCHAR(255) NULL,
  reviewed_by    BIGINT       NULL,
  reviewed_at    TIMESTAMP    NULL,
  paid_at        TIMESTAMP    NULL,              -- 入账时间
  expired_at     TIMESTAMP    NULL,              -- 支付超时时间（同订单规则）
  timestamps,
  INDEX (user_id, status), INDEX (status, created_at)
);
```

状态流转：`pending → success | failed | closed`；线下转账 `reviewing → success | failed`（驳回后用户可重新提交凭证或更换渠道）。

### 4.2 改造表

#### `payments` —— 增加列 + `order_id` 改可空（迁移 `add_offline_fields_to_payments_table`）

| 列 | 类型 | 说明 |
|---|---|---|
| `biz_type` | varchar(32) not null default 'order' | 业务类型：`order` 订单支付 / `recharge` 余额充值 |
| `biz_no` | varchar(64) null | 业务单号（订单号或充值单号），用于不依赖 order_id 的反查 |
| `order_id` | **改可空** | 充值单无订单，必须可空；`order()` 关联保持有效，仅 `biz_type=order` 时非空 |
| `payer_name` | varchar(64) null | 线下转账：付款人姓名 |
| `payer_account` | varchar(128) null | 线下转账：付款账号/银行 |
| `transfer_no` | varchar(128) null | 线下转账：银行流水号 |
| `transferred_at` | timestamp null | 线下转账：转账时间 |
| `voucher_url` | varchar(255) null | 线下转账：凭证图片 |
| `review_remark` | varchar(255) null | 核账备注（驳回原因） |
| `reviewed_by` | bigint null | 核账人 |
| `reviewed_at` | timestamp null | 核账时间 |

#### 枚举扩展（无 DB 约束，代码常量层）

- `Payment::STATUS_REVIEWING = 'reviewing'`（线下转账待核账）
- `Payment::CHANNEL_BALANCE = 'balance'`、`CHANNEL_OFFLINE = 'offline'`、`CHANNEL_MOCK = 'mock'`
- `Payment::BIZ_TYPE_ORDER = 'order'`、`BIZ_TYPE_RECHARGE = 'recharge'`（配合 `biz_no`）
- `Payment::STATUS_LABELS` 增加 `reviewing => '待核账'`
- `PaymentLog::EVENT_LABELS` 增加 `review / query / refund`
- 支付状态机：`pending → success | failed | closed`，`reviewing → success | failed`（核账驳回即 failed）

> `order_id` 改可空的影响面：`PaymentService` 中「确认扣减库存」「订单状态流转」两段只在 `biz_type === order` 时执行；`biz_type === recharge` 时支付成功后改为调用 `BalanceService::credit()` 入账。现有查询均按 `order_id` 过滤，不受影响。

### 4.3 `system_configs` 新增业务开关

| key | 默认 | 说明 |
|---|---|---|
| `payment.default_channel` | `wechat` | 收银台默认选中渠道 |
| `payment.balance_enabled` | `1` | 余额支付总开关（与 `payment_channels.enabled` 与逻辑） |
| `payment.offline_enabled` | `1` | 线下转账总开关 |
| `payment.offline_receipt` | 单对象 JSON | **单收款账户**：`{"bank_name":"…","account_name":"…","account_no":"…","qrcode_url":"…"}`，收银台与充值页展示 |
| `payment.query_max_attempts` | `10` | 主动查单最大次数 |
| `payment.result_poll_seconds` | `2` | 前端轮询间隔 |
| `payment.recharge_enabled` | `1` | 余额充值总开关 |
| `payment.recharge_amounts` | `50,100,200,500` | 充值页固定面额（逗号分隔），同时允许自定义金额 |
| `payment.recharge_min_amount` | `10.00` | 自定义充值最小金额 |
| `payment.recharge_max_single` | `5000.00` | 单笔充值上限（风控） |
| `payment.recharge_max_daily` | `20000.00` | 单日累计充值上限（风控） |
| `payment.recharge_gift_rules` | `[]` | 赠送规则 JSON：`[{"amount":100,"gift":10}]`，命中最高档；空数组表示不送 |
| `payment.recharge_timeout_minutes` | `30` | 充值单支付超时（与订单超时同理，超时自动 `closed`） |

---

## 5. 后台支付渠道配置

### 5.1 页面：`/payment-channels`（系统分组，权限 `payment.channel.manage`）

```
┌──────────────────────────────────────────────────────────────┐
│ 支付渠道配置                                    [+ 启用渠道]  │
├──────────────────────────────────────────────────────────────┤
│ ● 微信支付    wechat  [已启用] [沙箱]  排序 10      [配置][停用] │
│ ● 支付宝      alipay  [已启用] [正式]  排序 20      [配置][停用] │
│ ● 余额支付    balance [已启用] —       排序 30      [配置][停用] │
│ ● 线下转账    offline [已启用] —       排序 40      [配置][停用] │
│ ○ 本地模拟    mock    [未启用] —       排序 99      [配置][启用] │
└──────────────────────────────────────────────────────────────┘
```

配置抽屉（以微信为例，分组 + 字段级说明 + 敏感字段掩码）：

- **基础**：启用开关、渠道名称、排序、沙箱模式开关
- **商户参数**：`app_id`、`mch_id`、`sub_mch_id`（服务商模式可选）
- **密钥/证书**：`api_v3_key`、`merchant_private_key`（文本域，上传 .pem 自动读入）、`merchant_cert_serial_no`、`wechatpay_public_key`（平台证书/公钥，上传或粘贴）
- **回调**：`notify_url`（只读展示 `https://{domain}/api/payments/callback/wechat`，带「复制」）、`return_url`
- **操作**：`[测试连接]` `[保存]`（敏感字段留空 = 不修改）

支付宝配置：`app_id`、`private_key`（应用私钥 RSA2 2048）、`alipay_public_key`、`gateway`（沙箱/正式切换自动填充）、`sign_type=RSA2`、`notify_url`、`return_url`。

`[测试连接]` 行为：
- 微信：用配置证书对微信「证书下载」接口发一次 GET（`/v3/certificates`），成功即配置有效；沙箱模式下跳过并提示「微信无官方沙箱，将使用本地 Mock」。
- 支付宝：调用 `alipay.trade.query` 或 `alipay.system.oauth.token`，沙箱走沙箱网关。
- 余额/线下：无需外部连通，返回「无需连接测试」。

### 5.2 接口

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| GET | `/admin/payment-channels` | `payment.channel.manage` | 列表，**敏感字段全部脱敏** |
| GET | `/admin/payment-channels/{channel}` | 同上 | 详情（敏感字段返回 `***` + `has_xxx` 布尔位） |
| PUT | `/admin/payment-channels/{channel}` | 同上 | 更新；敏感字段为空表示不覆盖 |
| POST | `/admin/payment-channels/{channel}/toggle` | 同上 | 启用/停用 |
| POST | `/admin/payment-channels/{channel}/test` | 同上 | 连接测试 |
| GET | `/admin/payment-channels/{channel}/logs` | 同上 | 该渠道配置变更历史（复用 `sys_operation_log` module=payment_channel） |

#### 充值单管理（配合 §6.5）

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| GET | `/admin/balance-recharges` | `balance.recharge.view` | 充值单列表：单号/用户/本金/赠送/渠道/状态/时间，支持状态与渠道筛选、导出 |
| GET | `/admin/balance-recharges/{id}` | 同上 | 详情：含关联支付单、凭证、余额流水号 |
| POST | `/admin/balance-recharges/{id}/review` | `payment.offline.review` | 线下充值核账通过/驳回（驳回必填原因） |

前台只暴露一个精简接口（便于收银台首屏）：

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/payments/channels?scene=order\|recharge` | 返回**已启用**渠道列表：code/name/icon/sort/balance（该用户余额，仅当渠道为 balance 且已登录）/收款账户（仅 offline）。`scene=recharge` 时**过滤掉 balance 渠道**并附带面额、赠送规则、限额 |

### 5.3 密钥安全（硬约束）

1. **存储加密**：`config` JSON 中的敏感键（`api_v3_key`、`merchant_private_key`、`alipay_public_key`、`private_key`、`wechatpay_public_key`）在写入时用 `Crypt::encryptString()`（Laravel `APP_KEY`，AES-256-CBC + HMAC）加密，非敏感键（`app_id`、`mch_id`、`gateway`）明文存储。
2. **永不回传明文**：列表/详情接口统一走 `maskSecret()`，返回 `sk_live_****abcd` 形式；只回传 `has_private_key: true/false` 让前端判断「是否已配置」。
3. **编辑留空不覆盖**：前端拿到的是掩码，若原样提交需被识别为「未修改」——约定：值为 `***` 前缀或以 `__UNCHANGED__` 占位时跳过更新。
4. **日志脱敏**：`sys_operation_log` 记录配置变更时，只记「哪些字段变了」（字段名列表），不记值；`payment_logs.request_data` 记录回调原始报文前先剔除 `sign`、`signature` 等签名字段之外的证书/密钥类字段。
5. **环境隔离**：`payment_channels` 只存业务配置；`PAY_SIGN_SECRET`（Mock 网关 HMAC 密钥）继续走环境变量，生产必须设置强随机值。
6. **权限最小化**：`payment.channel.manage` 默认**仅超管**，不给运营。

### 5.4 上传接口扩展

线下转账凭证需要前台上传，现状 `POST /admin/upload` 仅限后台且只接受 `image ≤ 5MB`。方案：新增前台接口 `POST /user/upload-voucher`（需登录，`image|mimes:jpg,jpeg,png,webp`，≤ 3MB，存 `storage/app/public/vouchers/{user_id}/`），限制单用户每天 ≤ 20 张，返回 URL 供提交支付时携带。不复用后台接口以免越权与目录混淆。

---

## 6. 支付流程设计

### 6.1 完整链路

```
[结算页 /checkout] 提交订单
      │  POST /orders  →  order_id、order_no（30 分钟超时）
      ▼
[收银台 /orders/:id/pay]
      │  GET /orders/{id}           拉取金额、倒计时
      │  GET /payments/channels     拉取可用渠道（含余额、收款账户）
      │  POST /payments {order_no, channel, extra?}
      ▼
  ┌───────────┬───────────┬────────────┬──────────────┐
  │ wechat    │ alipay    │ balance    │ offline      │
  │ Native 扫码│ 表单跳转  │ 直接扣款    │ 上传凭证      │
  │ 轮询       │ 回跳+轮询 │ 同步返回    │ → reviewing   │
  └───────────┴───────────┴────────────┴──────────────┘
      ▼
[结果页 /pay/result/:payment_no]
      │  success → 成功卡 + [查看订单详情] [继续购物]（不展示推荐商品）
      │  reviewing → 待核账卡 + 说明「预计 1 个工作日内完成核账」
      │  failed/closed → 失败卡 + [重新支付] [联系客服]
      │  pending（处理中）→ 继续轮询，超时 150s 后提示「以订单状态为准」
      ▼
[订单详情 /orders/:id]
```

**余额充值链路**（§6.5 详述）：

```
[账户中心 /account?tab=balance] → [充值页 /balance/recharge]
      │  POST /user/balance/recharges {amount, channel, extra?} → recharge_no
      ▼
  在线充值（wechat/alipay）→ 复用上面同一条支付链路与结果页
  线下充值（offline）      → reviewing → 后台核账 → 入账
      ▼
[余额入账] user_balances.balance += amount + gift；user_balance_logs(recharge)
      ▼
[账户中心 / 充值记录]
```

### 6.2 各渠道流程细节

| 渠道 | 发起 | 结果确认 | 失败/异常 |
|---|---|---|---|
| **微信（Native 扫码）** | 后端调 `WechatGw::create()` 拿 `code_url` → 前端渲染二维码（用现有 `QrCode` 占位替换为真实二维码渲染，建议 `qrcode.vue` 组件或后端生成 SVG） | 前端 2s 轮询 `GET /payments/{no}`；**另由服务端主动查单补偿**（见 §7.2） | 超时关单后支付 → 原路退款 |
| **支付宝（PC 网站支付）** | 后端返回 `form_html`（自动提交表单）或 `pay_url`，前端跳转 | 前端回跳 `return_url` → 结果页轮询兜底；回调优先 | 同上 |
| **余额** | `BalanceGw::create()` 在**同一事务内**完成：行锁 `user_balances` → 校验余额 ≥ 应付 → 扣减 → 写 `user_balance_logs(consume)` → 支付单直接置 `success` → 订单 `paid` | 同步返回，无需轮询 | 余额不足返回 `40010 余额不足`；并发支付用行锁 + 版本号防超扣 |
| **线下转账** | 用户填付款人/账号/流水号/转账时间 + 上传凭证 → `POST /payments {channel:'offline', extra:{...}}` → 支付单 `reviewing`，**不驱动订单状态** | 后台 `[核账]`：通过 → `success` + 订单 `paid` + 写 `order_logs`；驳回 → `failed` + 凭证与备注保留，用户可重新提交 | 驳回后订单仍 `pending_payment`，可换渠道重付 |

### 6.3 `PayParams` 标准化返回（前端按 `type` 分支渲染）

```ts
type PayParams =
  | { type: 'qrcode';   code_url: string; expire_at: string }        // 微信 Native
  | { type: 'redirect'; pay_url: string } | { type: 'form'; form_html: string } // 支付宝
  | { type: 'direct';   paid_at: string }                            // 余额，同步完成
  | { type: 'voucher';  receipt: OfflineReceipt }                    // 线下转账，展示收款账户
  | { type: 'mock';     sandbox_pay_url: string }                    // L1 Mock
```

### 6.4 `POST /payments` 统一入参与校验

```jsonc
{
  "order_no": "SO20260916000001",
  "channel": "wechat",           // 必须来自 GET /payments/channels 返回（防伪造未启用渠道）
  "extra": {                     // 仅 offline 需要
    "payer_name": "张三",
    "payer_account": "6222 **** 1234",
    "transfer_no": "20260916001",
    "transferred_at": "2026-09-16 10:30:00",
    "voucher_url": "/storage/vouchers/1/xxx.png"
  }
}
```

校验顺序（沿用现有风格，抛 `BusinessException`）：
1. 订单存在且属于当前用户 → 40404
2. 订单 `pending_payment` → 40009
3. 渠道已启用（读 `payment_channels` + `system_configs` 总开关）→ 40010
4. 未超时（`created_at + order.timeout_minutes`）→ 40009（超时由调度任务统一取消，此为兜底）
5. 渠道专属校验（余额是否足够 / 凭证字段是否完整）

### 6.5 余额充值流程

**入口**：账户中心「我的余额」→ 充值页 `/balance/recharge`（也可从收银台「余额不足」处快捷跳转）。

**表单**：固定面额按钮（读 `payment.recharge_amounts`）+ 自定义金额输入；展示赠送规则命中提示（如「充 ¥100 送 ¥10」）；渠道仅展示 `wechat / alipay / offline`（**充值不支持余额支付**，避免无意义自付），受 `payment.recharge_enabled` 与渠道 `enabled` 双重开关控制。

**下单**：`POST /user/balance/recharges`

```jsonc
{ "amount": "100.00", "channel": "wechat", "extra": { /* offline 时同 §6.4 */ } }
```

服务端处理（`BalanceRechargeService::create()`）：

1. 校验：登录态、金额 ∈ [`recharge_min_amount`, `recharge_max_single`]、单日累计 ≤ `recharge_max_daily` → 超限 40010
2. 命中赠送规则 → 计算 `gift_amount`
3. 建 `balance_recharges`（`status=pending`，`expired_at = now + recharge_timeout_minutes`）
4. 建 `payments`（`biz_type=recharge`、`biz_no=recharge_no`、`order_id=null`），回填 `recharge.payment_id`
5. 调渠道网关 `create()` → 返回与订单支付**完全相同**的 `PayParams`，前端复用同一套收银台组件与结果页

**入账**（`BalanceService::credit()`，唯一入账口）：

- 在线充值：`payments.status=success` → 事务内 `user_balances` 行锁 → `balance += amount + gift`、`total_recharge += amount`、`version++` → 写 `user_balance_logs(type=recharge, related_type=recharge, related_id)` → `balance_recharges.status=success, paid_at=now`
- 线下充值：核账通过（§5.2 `POST /admin/balance-recharges/{id}/review`）→ 同上入账
- **幂等**：以 `related_type=recharge AND related_id` 唯一性兜底（入账前先查是否已存在该充值单流水），防止回调重发重复入账

**超时**：调度任务扫描 `pending 且 expired_at < now` → `balance_recharges.status=closed` + 关支付单（与订单超时关单同一命令，按 `biz_type` 分流）。

**退款与余额关系**：订单退款统一退回余额（`user_balance_logs(type=refund)`），**充值本金不支持直接提现**（一期无提现功能）；若后续需要原路退回，只退本金不退赠送金额。

---

## 7. 回调、对账与异常

### 7.1 回调统一入口

保留 `POST /payments/callback/{channel}`（无需登录，限流 60/min + IP 白名单可选），由渠道适配器完成验签：

| 渠道 | 验签方式 | 返回给渠道 |
|---|---|---|
| 微信 V3 | 校验 `Wechatpay-Signature` 等请求头（平台证书/微信支付公钥），`AES-256-GCM` 解密 `resource` | `{"code":"SUCCESS"}` / `{"code":"FAIL","message":...}`，HTTP 200 |
| 支付宝 | 对回调参数按 `sign_type=RSA2` 用支付宝公钥验签，并**二次校验 `total_amount` 与 `seller_id`/`app_id`** | `success` / `fail` 字符串 |
| 余额 | 无回调（同步完成） | — |
| 线下 | 无回调（人工核账触发） | — |
| Mock | 现有 HMAC-SHA256 | JSON |

统一后仍走 `PaymentService::handleCallback()` 的既有三步：**验签 → 金额一致性 → 幂等**，最后事务更新 + 订单状态机。

### 7.2 主动查单补偿（新增，解决「回调丢失」）

- **触发点 A**：结果页轮询超过 15s 仍 `pending` 时，前端调 `POST /payments/{no}/sync`（限流 10/min）触发服务端主动查单，命中成功则立即驱动状态机。
- **触发点 B**：调度任务 `payments:sync-pending`（每分钟），扫描 `status=pending 且 created_at 在 2~30 分钟内` 的支付单，调 `WechatGw/AlipayGw::query()`，最多 `payment.query_max_attempts` 次；超过阈值标记 `failed` 并记日志。
- 查单结果统一写 `payment_logs(event=query)`，便于在支付管理页看到「渠道实际已支付但回调未到」的证据链。

### 7.3 超时关单（新增调度，覆盖订单与充值单）

`routes/console.php` 注册：

```php
Schedule::command('payments:cancel-timeout')->everyMinute()->withoutOverlapping();
```

逻辑（按 `biz_type` 分流）：
- `order`：扫描 `orders.status=pending_payment 且 created_at < now - order.timeout_minutes` → `PaymentService::closePendingForOrder()` 关支付单 → `OrderService::transitionTo(cancelled, '支付超时', 'system')` → 释放锁定库存（复用现有库存回滚）→ 写 `order_logs(operator_type=system)`。
- `recharge`：扫描 `balance_recharges.status=pending 且 expired_at < now` → 关支付单 → `status=closed`（不涉及库存与订单状态）。

### 7.4 并发与幂等

- 支付单更新统一用 `whereKey()->where('status', pending)->update()` 条件更新，`affected === 0` 即视为并发已处理（现有实现已如此，保留）。
- 余额扣减用 `lockForUpdate()` 行锁 + `version` 乐观锁双保险。
- **余额入账幂等**：以 `user_balance_logs(related_type=recharge, related_id=充值单ID)` 是否已存在为判据，回调重复到达不重复加钱。
- 同一订单存在 `pending` 支付单时复用（现有逻辑）；若用户切换渠道（如微信 → 支付宝），**关闭旧 pending 单再建新单**，避免两笔同时可付。

### 7.5 退款与余额回退

- 微信/支付宝：**原路退回**（渠道 `refund()` 接口），失败转人工。
- 余额支付：退回 `user_balances`，写 `user_balance_logs(refund)`。
- 线下转账核账后退款：**不支持原路**（银行转账不可逆），由客服线下处理并在后台标记，退款单 `status=success` 时备注「线下已转」。
- **充值本金不退现**：一期无提现功能，充值金额只能通过消费使用；若后续开放原路退回，**赠送金额不退**（仅退本金）。

---

## 8. 沙箱与测试方案

### 8.1 三层测试环境（重要）

| 层 | 名称 | 用途 | 依赖 |
|---|---|---|---|
| **L1** | 本地 Mock 网关（channel=`mock`） | 本地开发、CI、自动化测试：完全离线，`POST /payments/sandbox/{no}` 自签回调 | 零外部依赖（现有 HMAC 机制即可） |
| **L2** | 支付宝沙箱 | 验证真实 RSA2 验签 / 回调 / 查单全链路是否接对 | 需沙箱应用与沙箱买家账号 |
| **L3** | 微信 1 分钱验收 | 验证微信 V3 证书验签与回调（**微信无沙箱，这是唯一真实验证途径**） | 需真实商户号 + 证书 |

> **必须明确的认知**：很多团队以为微信也有沙箱。微信支付 V2 时代确有 `sandboxnew` 收单接口，但已下线；**V3 接口没有沙箱环境**。因此微信只能靠 L1 Mock 覆盖逻辑、L3 小额真实支付覆盖链路。不要在设计里承诺「微信沙箱」。

### 8.2 L1 Mock 网关（改造现有机制）

- 将现有 `sandboxNotify()` 提升为 `MockGateway implements PaymentGateway`：
  - `create()` 返回 `{type:'mock', sandbox_pay_url}`（与现状兼容）
  - `verifyCallback()` 沿用 HMAC-SHA256(`payment_no|channel_trade_no|amount|status`)
  - `query()` 直接返回本地支付单状态
  - `refund()` 直接标记成功
- 仅在 `app.env ∈ {local, testing}` 或 `payment_channels(mock).enabled=true` 时对前台开放；生产环境 `enabled` 强制为 `false`（控制器层硬校验，防止误开）。

### 8.3 L2 支付宝沙箱配置步骤（可直接照做）

1. 登录开放平台 → **控制台 → 开发服务 → 沙箱环境**（`open.alipay.com`）。
2. 取得：沙箱 `APPID`（一般 `202100…`）、**沙箱网关** `https://openapi-sandbox.dl.alipaydev.com/gateway.do`、沙箱买家账号与支付密码（在沙箱后台可给账号充值余额用于付款）。
3. 本地生成 RSA2（2048 位）密钥对：
   ```bash
   openssl genrsa -out app_private_key.pem 2048
   openssl rsa -in app_private_key.pem -pubout -out app_public_key.pem
   ```
4. 将 **应用公钥** 粘贴到沙箱应用的「接口加签方式」，换取 **支付宝公钥**（注意：不是应用公钥）。
5. 后台 → 支付渠道配置 → 支付宝 → 打开「沙箱模式」：网关自动填入沙箱地址，填 `app_id` / 应用私钥 / 支付宝公钥，保存 → 点「测试连接」。
6. 收银台选支付宝 → 跳转沙箱收银台 → 用**沙箱买家账号**登录付款 → 回调 → 订单转 `paid`。

### 8.4 L3 微信验证替代方案

1. 后台配置真实商户参数（沙箱开关置灰并提示「微信支付无官方沙箱」）。
2. 用 1 分钱商品下单，在预发布环境完成一次真实扫码支付，验证：签名头校验、`resource` 解密、回调入账、主动查单、退款。
3. 日常回归全部走 L1 Mock，不依赖微信。

### 8.5 测试用例清单（实现时必须覆盖）

**单元测试（Pest Unit）**
- `MockGateway::sign/verifyCallback` 正确与篡改报文
- `BalanceGateway`：余额充足扣减、余额不足拒绝、并发两次支付只成功一次、扣减失败回滚
- `BalanceService::credit()`：入账金额与赠送计算、流水 before/after 正确、**同一充值单重复回调只入账一次**
- 赠送规则 `recharge_gift_rules` 命中最高档、未命中不送
- `PaymentChannelConfig` 加解密与脱敏（明文不落库、掩码格式）
- 各网关 `create()` 返回 `PayParams` 类型正确（微信为 `qrcode`、支付宝为 `form/redirect`、余额为 `direct`、线下为 `voucher`）

**集成测试（Pest Feature，真实 HTTP + 中间件 + DB）**
- 未启用渠道发起支付 → 40010；未登录 → 401；他人订单 → 404
- 微信 Mock 全流程：建单 → 回调成功 → 订单 `paid` + 库存确认扣减 + `order_logs` 写入
- 回调验签失败 / 金额不一致 / 重复回调幂等
- 余额支付：扣款 + 流水 + 订单 `paid`；余额不足 40010
- 线下转账：提交 → `reviewing` → 后台核账通过 → `paid`；驳回 → `failed` 且订单回到 `pending_payment`
- 渠道切换时旧 pending 单被关闭
- 主动查单补偿：模拟回调丢失后 `POST /payments/{no}/sync` 命中成功
- 超时关单命令：超时候订单 `cancelled` + 支付单 `closed` + 库存回滚；充值单超时 → `closed` 且不入账
- 权限：运营无 `payment.channel.manage` 时配置接口 403；`payment.offline.review` 控制核账
- **充值链路**：下单 → Mock 支付成功 → 余额 `+= amount + gift`、流水 `recharge` 写入、`recharge.status=success`；`scene=recharge` 渠道列表不含 balance
- **充值风控**：低于 `recharge_min_amount`、高于 `recharge_max_single`、单日累计超 `recharge_max_daily` 均 40010
- **充值核账**：线下充值 `reviewing` → 后台通过 → 入账；驳回 → `failed` 不入账

**前端测试（Vitest）**
- 收银台：渠道列表渲染、默认选中、余额显示与「余额不足」禁用、倒计时到期禁用支付
- 结果页：success / reviewing / failed / pending 四种态渲染与按钮行为（**无推荐商品位**）
- 线下转账表单：必填校验、凭证上传成功回填
- 后台渠道配置：密钥掩码回显、留空不覆盖、测试连接结果展示
- 充值页：面额选择、自定义金额边界、赠送提示、单日限额提示、渠道列表**不含余额支付**

---

## 9. 前端页面设计

### 9.1 收银台 `/orders/:id/pay`（改造 `PayView.vue`）

```
┌────────────────────────────────────────┐
│ 收银台    订单号 SO…    剩余 29:58       │
│ 应付金额 ¥129.00                        │
├────────────────────────────────────────┤
│ 支付方式                                │
│ (•) 微信支付            推荐            │
│ ( ) 支付宝                              │
│ ( ) 余额支付      可用余额 ¥50.00（不足，已置灰）│
│ ( ) 线下转账      需上传转账凭证         │
├────────────────────────────────────────┤
│ [ 立即支付 ¥129.00 ]                    │
└────────────────────────────────────────┘
```

要点：
- 渠道项由 `GET /payments/channels` 驱动（后台改配置即生效，前端不写死）
- 余额渠道显示余额并在不足时置灰 + 提示「余额不足，请更换支付方式」
- 线下转账展开收款账户卡（开户行/户名/账号，带「复制」）+ 凭证表单
- 倒计时到 0 → 禁用支付并提示「订单已超时，请重新下单」
- 微信渠道展示真实二维码（替换现有占位）+ 「请使用微信扫码支付」
- 沙箱环境顶部显示黄色提示条「当前为沙箱环境，不会产生真实扣款」

### 9.2 结果页 `/pay/result/:payment_no`（新增）

四态：成功（`CircleCheckBig` 绿）/ 待核账（`Clock3` 蓝，说明核账时效与凭证信息）/ 失败（`CircleX` 红，附失败原因与「重新支付」）/ 处理中（loading + 持续轮询，150s 超时引导去订单列表）。
成功后按钮：`[查看订单详情]`（主）、`[返回首页]`（次）。**不展示推荐商品**（已确认）。
充值场景复用同一结果页，成功卡文案与按钮随 `biz_type` 切换（`[查看余额]` / `[返回账户中心]`）。

### 9.4 充值页 `/balance/recharge`（新增）

```
┌────────────────────────────────────────┐
│ 余额充值        当前余额 ¥0.00          │
├────────────────────────────────────────┤
│ 充值金额                                │
│ [¥50] [¥100] [¥200] [¥500]   [自定义]   │
│ 充 ¥100 送 ¥10（规则提示）              │
├────────────────────────────────────────┤
│ 支付方式                                │
│ (•) 微信支付  ( ) 支付宝  ( ) 线下转账   │
├────────────────────────────────────────┤
│ [ 立即充值 ]      到账说明与充值协议      │
└────────────────────────────────────────┘
```

- 线下转账时展开单收款账户卡（开户行/户名/账号 + 可选收款二维码）+ 凭证上传表单
- 提交后复用 §9.1 的收银台组件（二维码/表单/凭证），结果跳 `/pay/result/:payment_no`
- 账户中心「我的余额」区块展示余额、「充值」按钮、充值记录与余额流水（分页）

### 9.5 后台新增入口

| 页面 | 路径 | 说明 |
|---|---|---|
| 支付渠道配置 | `/payment-channels` | §5.1 |
| 线下转账核账 | 纳入现有 `PaymentView.vue` | 列表增加「待核账」状态胶囊；详情抽屉增加凭证图片放大、核账通过/驳回（驳回必填原因）、核账记录时间轴 |
| 充值订单管理 | `/balance-recharges`（交易分组） | 列表（单号/用户/本金/赠送/渠道/状态）+ 筛选 + 导出；详情含关联支付单与凭证；线下充值核账 |
| 余额与流水 | `/users/{id}` 详情内 | 用户详情增加区块：可用余额、累计充值/消费、充值/消费/退款流水（只读） |

---

## 10. 权限与日志

| 权限码 | 默认分配 | 控制范围 |
|---|---|---|
| `payment.channel.manage`（新增） | 超管 | 支付渠道配置的查看/编辑/启停/测试连接 |
| `payment.offline.review`（新增） | 运营 + 超管 | 线下转账核账通过/驳回（订单支付 + 余额充值） |
| `balance.recharge.view`（新增） | 运营 + 超管 | 充值单列表/详情/导出（只读，核账走 `payment.offline.review`） |
| `payment.view` / `payment.manage` | 运营 / 超管 | 现有支付单查看与关闭（不变） |

日志要求：
- 渠道配置变更 → `sys_operation_log(module=payment_channel)`，只记变更字段名
- 核账 → `sys_operation_log(module=payment)` + `payment_logs(event=review, 含 before/after)`
- 余额变动 → `user_balance_logs`（不可变，含 before/after 与关联单据）
- 所有回调 → `payment_logs(event=callback/query)`，原始报文剔除签名字段后入库

---

## 11. 实施计划

| 阶段 | 内容 | 预估 | 状态 |
|---|---|---|---|
| P1 数据层 | `payment_channels`、`user_balances`、`user_balance_logs`、`balance_recharges` 迁移 + `payments` 扩展列与 `order_id` 改可空 + 常量与标签扩展 + 权限码迁移（幂等） | 0.5 天 | ✅ 已实现 |
| P2 适配层 | `PaymentGateway` 接口 + `MockGateway`（迁移现有沙箱）+ `BalanceGateway` + `OfflineGateway` + `PaymentService` 重构为编排层（按 `biz_type` 分流） | 1.5 天 | ✅ 已实现 |
| P3 真实渠道 | `WechatGateway`（**Native 扫码**：V3 证书验签/解密/查单/退款）+ `AlipayGateway`（RSA2/沙箱网关/查单/退款）；建议用 `yansongda/pay` | 2 天 | ✅ 已实现 |
| P4 后台 | 支付渠道配置页 + 接口 + 加密/脱敏 + 连接测试（文本域 + .pem 上传）；支付管理页核账；充值订单管理页；用户余额与流水区块 | 2 天 | ✅ 已实现 |
| P5 前台 | 收银台重构（微信 Native 二维码）+ 结果页 + 渠道接口 + 凭证上传 + 轮询/查单补偿 | 1.5 天 | ✅ 已实现 |
| P6 充值 | 充值页 + 充值单/入账服务 + 赠送与限额 + 充值记录与余额流水 | 1 天 | ✅ 已实现 |
| P7 可靠性 | 超时关单命令（订单 + 充值）、主动查单调度、切换渠道关旧单 | 0.5 天 | ✅ 已实现 |
| P8 测试 | 单元 + 集成 + 前端测试；支付宝沙箱联调；微信 1 分钱验收 | 1.5 天 | ✅ 已实现（沙箱/微信联调待真实凭证） |

> **进度**：P1 ~ P8 主体实现完成，通过双库全量回归（后端 416 passed ×2、前端 83 passed）+ 生产构建 + 真实 HTTP 冒烟 32/32。
> 证据：`docs/testing/evidence/v1.1/cashier/`（`P6-recharge.md`、`P7-reliability.md`、`P8-acceptance.md`）。
> **唯一未执行**：支付宝沙箱联调（L2）与微信 1 分钱验收（L3）依赖真实沙箱/商户凭证，L1 Mock 已覆盖全部业务逻辑。

合计约 **10.5 人日**（不含等商户号审批的等待时间）。其中 P1~P2 + P5 是前台可用的最小闭环（约 4 天，四种支付方式 + 沙箱），P3/P4 的真实渠道与后台配置可并行推进。

### 风险与应对

| 风险 | 影响 | 应对 |
|---|---|---|
| 微信无沙箱 | 联调依赖真实商户号 | L1 Mock 覆盖逻辑；提前申请商户号；L3 小额验收 |
| 商户私钥泄露 | 资金风险 | 加密存储 + 掩码回显 + 最小权限 + 只记字段名不记值 |
| 回调丢失导致订单挂起 | 用户体验 | 主动查单补偿 + 结果页同步按钮 + 超时关单 |
| 余额并发超扣 | 资损 | 行锁 + 乐观锁 + 流水对账 |
| 重构 `PaymentService` 影响现有沙箱测试 | 回归风险 | 先迁移 Mock 网关保持行为一致，现有 `PaymentAdminApiTest` / `PaymentServiceCloseTest` 作为回归基线 |

---

## 12. 决策状态

### 12.1 已确认（2026-09-16，见 §1.1）

1. 微信支付走 **Native 扫码**（PC 网站），不做 JSAPI / 小程序 —— 无需 openid 获取流程。
2. **提供余额充值入口**，支持在线支付（微信/支付宝）与线下转账两种方式。
3. 线下收款**单账户**，`payment.offline_receipt` 存单对象 JSON，后台单表单维护。
4. 证书/密钥录入为 **文本域 + 支持上传 .pem 自动读入**。
5. 支付成功页**不展示推荐商品**。

### 12.2 实现时需补充确认（不阻塞开工）

1. **收款账户是否放二维码**：`payment.offline_receipt` 预留了 `qrcode_url`，若提供收款码图片，收银台展示更方便；否则只展示文本 + 复制。
2. **赠送金额的成本归属**：充 100 送 10 的赠送部分是否参与退款折算（当前设定：退款按实付比例折算，赠送不退）。
3. **充值是否需要发票/对账单**：一期不做，若需要应在充值单上补开票字段。
4. **支付宝沙箱账号归属**：需指定一个沙箱应用与买家账号供团队共用，避免各自配置导致联调结果不一致。
