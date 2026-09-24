# CubeShop 短信渠道设计与实现方案 v1.0

> 状态：设计稿（待评审）
> 范围：后台可配置的短信渠道层 + 阿里云 SMS 适配器 + Mock 测试渠道；腾讯云为二期预留
> 关联：`docs/design/CubeShop_Search_Design_v1.0.md`（后台专属配置页模式）、WMS 适配器体系（凭证完备度判定）

---

## 1. 背景与目标

V1.0 无短信网关：注册、重置密码的验证码以图形验证码降级（`AuthController` 注释明示「V1.0 无短信网关」）。
本设计补齐短信能力，目标按优先级：

1. **渠道层**：统一 `SmsChannel` 抽象，多服务商适配器可插拔，第一步阿里云，第二步腾讯云；
2. **后台可配置**：admin 界面管理服务商凭证（加密入库）、切换启用渠道、Mock 测试发送；
3. **Mock 渠道**：不发真短信、留痕可查，支撑开发/测试环境全链路；
4. **首个消费场景**：注册 / 重置密码的手机验证码（替换图形验证码，可灰度、可回退）。

**明确不做**（范围裁剪）：上行短信（SmsUp）、批量营销短信、国际/港澳台短信、回执订阅（SmsReport）。
通知类短信（订单发货等，仿 `notify.mail_types`）列为二期可选，见 §9。

## 2. 现状盘点（复用先例）

| 先例 | 可复用点 |
|---|---|
| `WmsConfig` | 凭证加密入库模式：`*_enc` 列 + `Crypt::encryptString` + 虚拟属性读写 + `$hidden` + `mask()` 脱敏回显 |
| `WmsAdapterFactory` | 凭证完备度决定 Mock/真实适配器；Mock fallback 记 warning；生产环境 fail-closed |
| `Support\Shipping\ShippingChannelInterface` | 渠道接口极简风格：`query()/available()`，配置禁止硬编码 |
| `NotificationService` + `notify.mail_types` | 「类型开关集合」的配置与读取方式 |
| `SearchConfigView.vue`（admin S1-09） | 后台专属配置页：引擎卡片 / 凭证表单 / 测试动作的页面范式 |
| `RateLimiter::for('search-suggest')` | .env 可配的接口限流先例 |

## 3. 总体架构

```
业务方（AuthController 验证码 / 后台测试发送 / 未来通知 Job）
        │
        ▼
  SmsService（门面：总开关判断 → 渠道解析 → 发送 → 落日志）
        │
        ├── SmsChannelFactory::make(SmsConfig)
        │       ├── provider=mock          → MockSmsChannel
        │       ├── provider=aliyun        → 凭证齐备 → AliyunSmsChannel
        │       │                            凭证缺失 → Mock 兜底 + warning（生产 fail-closed 抛错）
        │       └── provider=tencent       → 二期，抛「适配器将在二期提供」
        │
        ├── SmsChannel（接口）
        │       send(phone, templateCode, params): SmsResult
        │       available(): bool
        │
        └── SmsLog（每次发送落一行，手机号脱敏）
```

目录结构（对齐 WMS 分层）：

```
backend/app/Services/Sms/
├── Contracts/SmsChannel.php        # 渠道接口
├── Dto/SmsResult.php               # ok|fail + provider_message_id + error_code/error_msg + raw
├── SmsChannelFactory.php
├── SmsService.php                  # 门面：开关/解析/发送/日志
├── SmsCodeService.php              # 验证码：生成/校验/防爆破/限流
├── SmsLogService.php               # 发送记录写入与查询（脱敏）
└── Adapters/
    ├── MockSmsChannel.php
    ├── Aliyun/AliyunSmsChannel.php # 业务适配：组装 SendSms 参数、错误码映射
    └── Aliyun/AliyunV3Signer.php   # 纯签名器（独立类，便于向量对拍测试）
```

## 4. 关键设计决策

### D1 配置存储：独立 `sms_configs` 表（凭证加密入库）

短信凭证（AccessKey Secret）不能进 `system_configs`（该表无加密列、值全明文回显）。
沿用 WmsConfig / PaymentChannel 两条既有先例，**一张表、每服务商一行**：

```
sms_configs
├── id
├── provider            varchar(32)   mock | aliyun | tencent（字典 App\Support\SmsProvider）
├── name                varchar(64)   展示名（如「阿里云短信」）
├── access_key_id       varchar(128)  明文列（ID 非机密，WmsConfig 同款处理）
├── access_key_secret_enc  text       Crypt::encryptString 密文，虚拟属性 access_key_secret 读写
├── sign_name           varchar(64)   短信签名（如「CubeShop」）
├── region              varchar(32)   默认 cn-hangzhou；endpoint 固定 dysmsapi.aliyuncs.com 不入库
├── extra               json          服务商扩展参数（二期腾讯云放 SdkAppId/AppId，避免二次迁移）
├── is_enabled          tinyint       启用标志，服务层保证全局最多一行启用
├── remark              varchar(255)
└── timestamps
```

- `sms.secret_masked` 虚拟属性：回显 `****abcd` 尾 4 位（WmsConfig 同款）；
- **切换渠道 = 事务内「先关旧行、再开新行」**，原子保证单启用；
- Mock 行凭证留空即可启用（开发/测试用）。

`system_configs` 只放运营开关（`sms.*` 前缀，`ConfigGroup` 登记 `'sms' => '消息通知'`）：

| 键 | 默认 | 说明 |
|---|---|---|
| `sms.enabled` | `0` | 短信总开关：关闭时所有发送直落 sms_logs（status=skipped），业务侧验证码场景回退图形验证码 |
| `sms.code_scenes` | `[]` | 走短信验证码的场景集合（register/reset_password），未列出的场景维持图形验证码——灰度与回退开关 |

### D2 渠道接口（对齐 ShippingChannelInterface 极简风格）

```php
interface SmsChannel
{
    /**
     * @param  string  $phone  国内手机号（单发；批量非本期目标）
     * @param  string  $templateCode  服务商模板标识（阿里云 SMS_xxx；腾讯云二期映射 TemplateId）
     * @param  array<string,string>  $params  模板变量（如 ['code' => '123456']）
     */
    public function send(string $phone, string $templateCode, array $params): SmsResult;

    /** 渠道是否可用（凭证齐备且未禁用；调用方据此跳过或降级） */
    public function available(): bool;
}
```

`SmsResult`：`ok` / `provider_message_id`（阿里云 BizId）/ `error_code` / `error_msg` / `raw`（原始响应，日志用）/ `latency_ms`。
适配器**不得抛异常穿透**——HTTP 异常、超时一律转 `SmsResult::fail()`（WMS 同款约定），由 `SmsService` 决定日志与告警。

### D3 工厂判定矩阵（对齐 WMS P2 语义：凭证完备度为唯一判据）

| provider | 凭证 | 结果 |
|---|---|---|
| mock | 任意 | `MockSmsChannel` |
| aliyun | access_key_id + secret 齐备 | `AliyunSmsChannel` |
| aliyun | 缺任一 | `MockSmsChannel` 兜底 + warning；**生产**环境调用时抛错（fail-closed，绝不产出"假发送成功"） |
| tencent | 任意 | 抛错：「腾讯云适配器将在二期提供」 |
| 其它 | 任意 | 抛错：未知服务商 |

### D4 阿里云适配器：自写 HTTP + V3 签名，不引官方 SDK

- 接口：`POST https://dysmsapi.aliyuncs.com/?Action=SendSms&Version=2017-05-25`，RPC 风格；
- 参数：`PhoneNumbers` / `SignName` / `TemplateCode` / `TemplateParam`（JSON，`JSON_UNESCAPED_UNICODE`——项目踩过中文转义坑，统一习惯）；
- 签名：**V3（ACS3-HMAC-SHA256，请求头式）**，官方当前主推；`AliyunV3Signer` 独立类只做纯函数签名，用固定输入→固定 `Authorization` 头的测试向量对拍；
- 不引 SDK 的理由：项目依赖克制（快递100 同为自写 HTTP），V3 签名仅需 hash/hmac 标准函数，避免 alibabacloud SDK 拖入大量传递依赖；风险（签名实现错误）由向量测试覆盖；
- 错误映射：`Code === 'OK'` → ok；`isv.BUSINESS_LIMIT_CONTROL`（流控）/ `isv.AMOUNT_NOT_ENOUGH`（欠费）/ `isv.SMS_SIGNATURE_ILLEGAL` / `isv.SMS_TEMPLATE_ILLEGAL` 等 → fail + 中文可读 message；
- 超时：connect 3s / total 5s；HTTP 异常、非 200、JSON 解析失败 → `SmsResult::fail('http_error', ...)`。

### D5 发送链路与日志

`SmsService::send(string $phone, string $templateCode, array $params, string $scene = 'general'): SmsResult`

1. `sms.enabled` 关闭 → 落 `status=skipped` 日志，返回 fail（调用方验证码场景回退图形验证码）；
2. 取启用行 → Factory → `channel.send()`；
3. **每次发送落一行 `sms_logs`**：

```
sms_logs：id / sms_config_id / provider / phone_masked(138****8000) / scene /
         template_code / status(sent|failed|skipped) / error_code / error_msg /
         biz_id / latency_ms / created_at
```

- **手机号脱敏入库**；`template_params` 不落库（验证码场景参数含验证码明文，严禁落日志）；
- 失败不影响调用方主流程语义：验证码场景返回 fail 由业务报错；通知类（二期）走队列Job 重试；
- 管理页查询走 `SmsLogService`，列表只回脱敏数据。

### D6 验证码（首个消费场景，灰度可回退）

`SmsCodeService`：

- 生成：`random_int` 6 位数字；cache 键 `sms_code:{scene}:{phone}`，TTL 5 分钟；
- 防爆破：同 key 验证错误累计 ≥5 次锁定 15 分钟；发送限流：同手机号 1 分钟 1 条 / 24 小时 10 条、同 IP 每分钟 `SMS_SEND_RATE_LIMIT`（默认 5，.env 可配，注册 `RateLimiter::for('sms-send')`，对齐 search-suggest 先例）；
- 校验：`verify(scene, phone, code)` 成功即销毁（一次性）；
- AuthController 改造：注册 / 重置密码按 `sms.code_scenes` 判断——启用短信验证码的场景接收 `phone + sms_code` 参数，未启用场景保持图形验证码原逻辑不动；
- 图形验证码链路**保留不删**，作为短信故障时的回退路径。

### D7 后台 admin

**权限码**：`sms.view` / `sms.manage`（`RolePermissionSeeder::PERMISSIONS` + 幂等迁移同改，media/search 同款做法）。

**API**（`routes/api.php` admin 组，OperationLogService 记录）：

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/admin/sms/config` | 渠道列表 + 当前启用 + `sms.*` 开关；secret 只回掩码 |
| PUT | `/admin/sms/config/{id}` | 更新某渠道凭证/签名/启用切换；secret 留空 = 不修改（对齐「缺省即不改」约定） |
| POST | `/admin/sms/test` | 测试发送：phone + template_code + params（manage 专属，额外限流防刷） |
| GET | `/admin/sms/logs` | 发送记录分页（脱敏） |

**admin 页面 `SmsConfigView.vue`**（`/sms-config`，参照 SearchConfigView）：

- 渠道卡片：Mock / 阿里云 / 腾讯云（灰置「二期」）；
- 阿里云表单：AccessKey ID、AccessKey Secret（掩码显示、留空不改）、签名 SignName、Region；
- 总开关 `sms.enabled` + 验证码场景多选 `sms.code_scenes`；
- 测试发送面板：输入手机号 + 模板 CODE + 变量，实时回显结果；
- 最近发送记录表（状态 / 错误码 / 耗时 / 时间）。

### D8 二期腾讯云预留

- 接口已抽象：腾讯云 `TemplateId` ↔ `templateCode`，模板参数数组差异由适配器内转换；
- `sms_configs.extra` JSON 列放 `SdkAppId`/`AppId`，**无需二次迁移**；
- 落地内容 = `TencentSmsChannel` + `TencentSmsClient`（TC3-HMAC-SHA256 签名，同 V3 思路）+ 工厂分支 + `SmsProvider` 字典登记。

## 5. 安全要点

1. Secret 加密列 + `$hidden` + 掩码回显，任何接口不出网密文；
2. 测试发送双重限流（路由 throttle + `sms-send` RateLimiter），防被刷短信费；
3. 验证码不入日志、不入缓存明文以外的任何存储；sms_logs 不落模板参数；
4. 生产 fail-closed：凭证缺失时绝不走"假成功" Mock（WMS SEC-01 语义）；
5. 所有后台写操作走 `OperationLogService::record()`。

## 6. 测试策略（Pest）

| 层 | 用例 |
|---|---|
| Factory | 判定矩阵逐格断言（mock/aliyun 凭证齐/缺/tencent/未知；生产 fail-closed 抛错） |
| MockSmsChannel | send 返回 ok、available 语义 |
| AliyunV3Signer | 固定输入 → 固定 Authorization 头（向量对拍） |
| AliyunSmsChannel | `Http::fake()`：Code=OK / isv 业务错误 / HTTP 500 / 超时 → 均转 SmsResult 不抛异常 |
| SmsService | 总开关 skipped 落日志；发送成功/失败均落 sms_logs；手机号脱敏断言 |
| SmsCodeService | 生成/校验/一次性销毁/错误 5 次锁定/发送限流 |
| Admin API | 权限（无 sms.manage 403）、secret 掩码回显、留空不改、启用切换唯一性、测试发送 |
| AuthController | `sms.code_scenes` 启用/未启用场景分别走短信与图形验证码 |

⚠️ 实施前先 `ls backend/database/migrations` 确认当前最大迁移号，勿沿用记忆编号；
测试文件全局函数保持唯一，admin 无权限分支用 operator。

## 7. 实施分期

**第一期（本次）**
1. 迁移：`sms_configs` + `sms_logs` + 权限码幂等迁移；`SmsProvider` 字典；
2. 渠道层：接口 / DTO / Mock / 阿里云（Signer + Channel）/ Factory / SmsService / SmsLogService；
3. 后台：Admin/SmsConfigController + 路由 + admin `api/sms.ts` + `SmsConfigView.vue` + 菜单注册；
4. 验证码：SmsCodeService + AuthController 注册/重置密码改造（灰度开关）；
5. 测试 + 真机验证（Mock 全链路 + 阿里云测试签名/模板 + 后台测试发送）。

**第二期（如有需要）**：腾讯云适配器；通知类短信（`notify.sms_types` 仿 mail_types + 队列 Job `SendSmsNotification`）。

## 8. 待确认项

1. 阿里云账号侧材料：AccessKey（建议 RAM 子账号，仅授予 `AliyunDysmsFullAccess`）、已审核的短信签名、验证码模板 CODE——**开发联调前需准备好**；
2. 验证码场景是否随第一期一起改造 AuthController（可拆为独立小步，渠道层先行）；
3. 短信费用告警阈值（`isv.AMOUNT_NOT_ENOUGH` 出现时是否需要通知管理员——二期顺手做）。
