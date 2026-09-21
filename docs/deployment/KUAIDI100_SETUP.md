# CubeShop 接入快递100 —— 配置、注意事项与 Mock 测试手册

> 适用版本：V1.1 三期（实时查询 + 智能单号识别）
> 设计文档：`docs/design/CubeShop_Kuaidi100_Phase1_v1.0.md`
> 最后核对：2026-09-22

---

## 0. 一分钟速查

| 你要做的事 | 位置 |
|---|---|
| 填密钥 | `backend/.env` → `SHIPPING_CHANNEL` / `SHIPPING_CHANNEL_KEY` / `SHIPPING_CHANNEL_CUSTOMER` |
| 建字段 | `php artisan migrate`（迁移 `000110` 给 `shippings` 加 `phone`） |
| 核字典 | 管理端「订单 → 快递公司」，`channel_code` 须为快递100 编码 |
| 核调度 | `backend/routes/console.php:25` —— 固定 30 分钟，**不要改** |
| 本地演示 | `SHIPPING_CHANNEL=mock` |
| 跑测试 | `php -d memory_limit=1G vendor/bin/pest`（后端） / `npx vitest run`（admin） |

**不填密钥 = 自动降级**，发货与运单展示完全不受影响，只是查不到轨迹。

---

## 1. 前置条件（商务侧）

在快递100 开放平台（<https://api.kuaidi100.com>）完成：

1. 注册企业账号并**实名认证**；
2. 购买「实时快递查询」套餐（智能单号识别**随查询套餐赠送**，无需单独购买）；
3. 在企业管理后台取得两个值：

| 值 | 说明 | 对应本项目配置 |
|---|---|---|
| `customer` | 公司编号（形如 `ABCD1234...`） | `SHIPPING_CHANNEL_CUSTOMER` |
| `key` | 授权 key（签名用，**不要泄露到前端**） | `SHIPPING_CHANNEL_KEY` |

⚠️ **这两个值是签名凭证，等同数据库密码。** 只出现在服务端 `.env`，禁止提交到仓库、禁止下发给前端或写入日志。

---

## 2. 需要配置的文件清单

### 2.1 必须改的文件（只有 1 个）

**`backend/.env`**（生产环境在服务器上配置，模板见 `backend/.env.example:62-80`）

```dotenv
# 渠道选择：留空=降级跳过；mock=本地演示；kuaidi100=快递100
SHIPPING_CHANNEL=kuaidi100

# 快递100 授权凭证（企业管理后台获取）
SHIPPING_CHANNEL_KEY=你的key
SHIPPING_CHANNEL_CUSTOMER=你的customer
```

改完必须清配置缓存：

```bash
cd backend
php artisan config:clear     # 开发
php artisan config:cache     # 生产（改动后重新执行）
```

### 2.2 可选：在管理后台切换渠道（无需改 .env）

管理端 **订单 → 物流监控** 顶部会显示当前渠道、是否可查询、密钥是否已配置，
有 `shipping.manage` 权限时可直接切换：

| 选项 | 含义 |
|---|---|
| 跟随环境配置（.env） | 默认值，以 `SHIPPING_CHANNEL` 为准 |
| 快递100 | 强制用快递100（需 .env 已配 key/customer） |
| 本地演示（Mock） | 不发真实请求，确定性生成演示轨迹 |
| 关闭轨迹查询 | 强制降级，覆盖 .env 中已配置的渠道 |

渠道值存在 `system_configs.shipping.channel`，每次启动由 `AppServiceProvider` 覆盖
`config('services.shipping.channel')`，切换后立即生效。

⚠️ **密钥不提供后台填写入口**：`key` / `customer` 属凭证，仍需在 `.env` 维护
（`SHIPPING_CHANNEL_KEY` / `SHIPPING_CHANNEL_CUSTOMER`），与 `PAY_SIGN_SECRET` 同款处理。
后台只回显「已配置 / 未配置」，不返回明文。

### 2.3 一般不动、但要知道的文件

| 文件 | 作用 | 何时才动 |
|---|---|---|
| `backend/config/services.php:48-64` | 定义 shipping 配置段，所有值都走 `env()` 且有默认值 | 需要指向内网代理时改 `query_url` / `autonumber_url` |
| `backend/app/Providers/AppServiceProvider.php:21-27` | `match` 注册渠道：`mock` / `kuaidi100` / 其他→`NullChannel` | 新增第三方渠道时 |
| `backend/routes/console.php:25` | `shipping:pull-traces` 每 30 分钟 + `withoutOverlapping` | **别动**，见 §5.1 |
| `backend/database/migrations/2026_09_22_000110_add_phone_to_shippings_table.php` | 给 `shippings` 加 `phone` | 已随 `php artisan migrate` 执行 |
| `backend/database/seeders/ExpressCompanySeeder.php` | 预填 8 家 `channel_code` + `carrier_codes` | 新增快递公司时 |
| `backend/database/migrations/2026_09_22_000113_add_carrier_codes_to_express_companies.php` | 给 `express_companies` 加 `carrier_codes` 并回填存量 | 已随 `php artisan migrate` 执行 |
| `backend/app/Support/CarrierCode.php` | 编码解析真源（正查 / 反查） | 编码口径调整时 |

### 2.3 完整配置项表

```php
// backend/config/services.php
'shipping' => [
    'channel'                => env('SHIPPING_CHANNEL'),            // 渠道标识
    'key'                    => env('SHIPPING_CHANNEL_KEY'),        // 快递100 key
    'customer'               => env('SHIPPING_CHANNEL_CUSTOMER'),   // 快递100 customer
    'query_url'              => env('SHIPPING_QUERY_URL', 'https://poll.kuaidi100.com/poll/query.do'),
    'autonumber_url'         => env('SHIPPING_AUTONUMBER_URL', 'https://www.kuaidi100.com/autonumber/auto'),
    'autonumber_enabled'     => (bool) env('SHIPPING_AUTONUMBER_ENABLED', true),
    'autonumber_batch_limit' => (int) env('SHIPPING_AUTONUMBER_BATCH_LIMIT', 100),
    'timeout'                => (int) env('SHIPPING_TIMEOUT', 8),
    'pull_window_days'       => (int) env('SHIPPING_PULL_WINDOW_DAYS', 30),
    'batch_size'             => (int) env('SHIPPING_BATCH_SIZE', 50),
    'batch_delay_ms'         => (int) env('SHIPPING_BATCH_DELAY_MS', 200),
    'max_failures'           => (int) env('SHIPPING_MAX_FAILURES', 5),
],
```

| 配置项 | 默认 | 说明 |
|---|---|---|
| `SHIPPING_QUERY_URL` | 官方地址 | 一般不动；内网出网受限可指向代理 |
| `SHIPPING_AUTONUMBER_URL` | 官方地址 | 同上 |
| `SHIPPING_AUTONUMBER_ENABLED` | `true` | 关掉即完全不调识别接口 |
| `SHIPPING_AUTONUMBER_BATCH_LIMIT` | `100` | 批量导入最多识别多少行（单次导入上限 500 行，超出跳过识别） |
| `SHIPPING_TIMEOUT` | `8` | 单次请求超时秒数（连接超时取 `min(3, timeout)`） |
| `SHIPPING_PULL_WINDOW_DAYS` | `30` | 只拉取 30 天内发货的运单 |
| `SHIPPING_BATCH_SIZE` | `50` | `chunkById` 批大小 |
| `SHIPPING_BATCH_DELAY_MS` | `200` | 批间延时，防触发限流 |
| `SHIPPING_MAX_FAILURES` | `5` | 连续失败几次后把运单置 `trace_status=failed` |

---

## 3. CubeShop 侧要做的事（检查表）

按顺序执行，每步都有验证命令。

### 步骤 1：执行迁移

```bash
cd backend && php artisan migrate
```

验证：

```bash
php artisan tinker --execute="echo \Illuminate\Support\Facades\Schema::hasColumn('shippings','phone') ? 'OK' : 'MISSING';"
```

### 步骤 2：确认快递公司字典的渠道编码

发运单的公司编码有**两套口径**，别混：

| 字段 | 含义 | 谁用 |
|---|---|---|
| `code` | 平台内部编码（`SF`/`ZTO`/…） | 发货录入、运单落库、`shippings.company_code` |
| `channel_code` | **快递100 编码**（`shunfeng`/…），单列兼容保留 | `CarrierCode` 正查的主要来源 |
| `carrier_codes` | **多渠道映射 JSON**，如 `{"kuaidi100":"shunfeng","cainiao":"SF","jd_cloud":"JD"}` | `CarrierCode` 正查优先级最高；入站归一反查 |

`ExpressCompanySeeder` 已为 8 家同时预填 `channel_code` 与 `carrier_codes.kuaidi100`：

| 内部 code | 名称 | 快递100 编码 | 手机号必填 |
|---|---|---|---|
| `SF` | 顺丰速运 | `shunfeng` | ✅ |
| `ZTO` | 中通快递 | `zhongtong` | ✅ |
| `YTO` | 圆通速递 | `yuantong` | — |
| `YD` | 韵达快递 | `yunda` | — |
| `STO` | 申通快递 | `shentong` | — |
| `JD` | 京东物流 | `jd` | — |
| `EMS` | 中国邮政 EMS | `ems` | — |
| `JT` | 极兔速递 | `jtexpress` | — |

新增/修正入口：管理端 **订单 → 快递公司**（`admin/src/views/order/ExpressCompanyView.vue`），表单里按渠道分列三行输入 —— **快递100 / 菜鸟奇门 / 京东云仓**，映射到 `carrier_codes`；`channel_code` 作为兼容回显保留（编辑时会写回同值）。

```bash
php artisan db:seed --class=ExpressCompanySeeder   # 首次或字典缺失时
```

⚠️ **字典变更后要清缓存。** `CarrierCode` 用进程内静态缓存 `$forward`/`$reverse`（`Kuaidi100Channel` 委托它取码），长驻进程（队列 worker / 定时任务）不会自动感知：

```bash
php artisan queue:restart    # 队列
# 或通过代码：\App\Support\CarrierCode::flushCache();
```

> 为什么不是把 `channel_code` 直接扩成多列？保留单列可让**升级后行为不变**（`carrier_codes` 未配时仍回落 `channel_code`），迁移 000113 已把存量 8 家回填进 `carrier_codes.kuaidi100`。详见 `docs/design/CubeShop_Logistics_Layering_and_CarrierCode_v1.0.md`。

### 步骤 3：确认手机号来源

`shippings.phone` 的取值链路（`Shipping::resolvePhone()`）：

1. 优先取 `shippings.phone` —— 发货时从 `orders.address_snapshot.contact_phone` 固化；
2. 为空则回落到 `orders.address_snapshot.contact_phone`；
3. 再为空 → 顺丰/中通查询直接失败。

发货时**自动**带出，无需人工录入。三条发货路径（admin 单条、批量 Excel、WMS 回调）都汇聚到 `OrderService::shipForShipment()`，历史运单靠回落兜底，不需要写回填脚本。

虚拟号处理：`138****1234-5678` 会自动截取 `-` 后的 `5678`（`Kuaidi100Channel::normalizePhone()`）。

### 步骤 4：配置密钥并验证连通

```bash
cd backend
php artisan tinker --execute="
\$c = app(\App\Support\Shipping\ShippingChannelInterface::class);
echo get_class(\$c), PHP_EOL;
var_dump(\$c->available());
"
```

期望输出 `App\Support\Shipping\Kuaidi100Channel` 与 `bool(true)`。若输出 `NullChannel`，说明 `SHIPPING_CHANNEL` 没读到（检查是否 `config:clear` 过）。

### 步骤 5：跑一次真实拉取

```bash
php artisan shipping:pull-traces
# 输出：轨迹拉取完成：成功 X，失败 Y，跳过 Z
```

`Y > 0` 时看日志定位：

```bash
tail -f storage/logs/laravel.log | grep "shipping trace pull"
```

---

## 4. 数据是怎么流转的

```
定时任务（每 30 分钟）
  └─ PullShippingTraces
       └─ TracePullService::pull()
            ├─ channel->available() == false → skipped（降级）
            ├─ channel->query(company_code, tracking_no, resolvePhone())
            │    ├─ ExpressCompany.channel_code 转换（SF → shunfeng）
            │    ├─ phone 必填校验（顺丰/顺丰快运/中通）
            │    ├─ sign = strtoupper(md5(param + key + customer))
            │    └─ POST https://poll.kuaidi100.com/poll/query.do
            ├─ 失败 → pull_fail_count++（达上限置 failed）
            └─ 成功 → 轨迹去重落库 + 推导 trace_status + 签收回填 delivered_at
```

前端展示：用户端 `web/src/components/ShippingCard.vue`（未配置渠道时降级跳 `https://www.kuaidi100.com/chaxun`）；
管理端「物流监控」页（`admin/src/views/order/ShippingMonitorView.vue`）可查看/切换当前渠道、单条重试，
并能在「轨迹」弹层看到**与用户端完全同口径**的轨迹时间线。

---

## 5. 注意事项（硬约束）

### 5.1 ⚠️ 拉取间隔不得小于 30 分钟

快递100 官方警告：**同一运单查询频率若高于半小时会锁单**。

项目已按此设计 —— `routes/console.php:25` 是 `everyThirtyMinutes()->withoutOverlapping()`，**天然合规，不要调高频率**。

若因排查问题手动连跑多次导致锁单：锁单期间返回业务失败，会累计 `pull_fail_count`，连续 5 次把运单置 `failed`。恢复后需：

```bash
php artisan shipping:pull-traces --include-failed
```

### 5.2 ⚠️ 顺丰 / 顺丰快运 / 中通必须传手机号

`Kuaidi100Channel::PHONE_REQUIRED = ['shunfeng', 'shunfengkuaiyun', 'zhongtong']`。

缺失时**直接返回失败，不发请求**（避免浪费调用次数），错误信息：「该快递公司（shunfeng）轨迹查询需手机号，请为运单补全收件人手机号」。

排查：确认该订单 `address_snapshot.contact_phone` 有值。

### 5.3 ⚠️ 签名细节：param 不能 urlencode

```
sign = strtoupper(md5(param + key + customer))
```

`param` 是**未 urlencode 的 JSON 串**。手写调试脚本时最容易在这里踩坑——先 urlencode 再算签名会得到完全不同的结果。

`param` 结构：`{"com":"yuantong","num":"...","phone":"...","resultv2":"1","order":"asc"}`

### 5.4 ⚠️ 行级状态靠文案匹配，顺序即优先级

快递100 的 `state` 是**运单级**的，`data[]` 每行没有状态字段。因此：

- 行级 `stage` 按轨迹文案关键词匹配（`已签收` → delivered，`派件/派送/投递` → delivering，`揽收/已收件` → pickup，其余 → in_transit）；
- 关键词数组**顺序即优先级**（先判签收再判派件），否则「派件已签收」会被误判成派送中；
- 末行文案没命中时，用运单级 `state` 兜底。

新增快递公司时若发现阶段判断不准，改 `Kuaidi100Channel::KEYWORD_STAGES` 补关键词。

### 5.5 ⚠️ 智能识别结果仅供参考，不可自动改写

官方明确不保证 100% 准确。项目定位是**提示与校验**：

- 批量导入：识别结果与填写编码不一致 → 产出 `warnings`，**不阻断发货**（按填写内容照常发货）；
- 单条发货：给出「采用」按钮，由人确认；
- 识别出的公司不在本平台字典内 → 丢弃该候选，不误导。

前端展示必须带「仅供参考」字样，且允许手改（已实现）。

### 5.6 一切失败都降级，不阻断主流程

| 场景 | 行为 |
|---|---|
| 未配置 `SHIPPING_CHANNEL` | `NullChannel`，命令安全跳过并提示 |
| 识别服务未开通（`returnCode=601`） | 只记一条 warning 日志，返回空候选 |
| 识别网络异常 / 超时 | 同上，不阻断发货 |
| 查询网络异常 | 收敛为 `TraceResult::fail()`，计入 `pull_fail_count` |
| 查询成功但 `data` 为空 | **正常态**（尚无轨迹），不计失败 |

⚠️ 识别服务未开通是因为**没买查询套餐**（601）。随查询套餐赠送，买完自动可用。

### 5.7 成本口径待确认

计费单位是「每次查询调用」。有二手信息称快递100 对「40 天内同一运单号多次查询不重复扣费」，但**未获官方书面确认**。

这条规则决定成本量级：

- 若属实：一单 40 天内 ≈ 0.025 元，现有轮询继续用即可；
- 若不属实：30 天 × 每 30 分钟 ≈ 1440 次/单 ≈ 36 元/单，必须改订阅推送模式。

**上线前需向快递100 商务要书面口径。** 这是唯一会影响后续架构走向的未知数。

### 5.8 ⚠️ Mock 渠道的轨迹时间必须是确定性的

`SHIPPING_CHANNEL=mock` 用于本地演示，它**不调用第三方**，按运单号种子生成轨迹。
轨迹时间必须「同一天内多次调用完全相同」——否则 `TracePullService` 的去重键
`occurred_at|context` 每次都不同，每 30 分钟就会插入一批内容相同的重复轨迹，
用户端订单页表现为物流信息「满屏」。

⚠️ 新增/修改任何演示用渠道时，都不要用 `now()` 生成轨迹时间。

已产生的历史重复数据由迁移 `000111` 清理（按「同一天、同一描述」保留最早一条，
跨天同名如两次中转不误删），幂等可重跑。

### 5.9 密钥安全

- `SHIPPING_CHANNEL_KEY` 只存服务端 `.env`，不进仓库（`.env` 已在 `.gitignore`）；
- 不下发给前端、不写入业务日志（失败日志只记 `message`，不记完整请求体）；
- 泄露后到企业管理后台重置。

---

## 6. Mock 与测试怎么测

项目提供**三层 Mock**，覆盖不同目的，互不干扰。

### 6.1 第一层：完全不接（NullChannel 降级）

```dotenv
SHIPPING_CHANNEL=
```

行为：`available()` 返回 `false`，`shipping:pull-traces` 打印「未配置 SHIPPING_CHANNEL，跳过轨迹拉取」并成功退出。

**用途**：本地开发、CI、未购买套餐的演示环境 —— 发货链路与运单展示全部正常，只是没有轨迹。

验证：

```bash
php artisan shipping:pull-traces
# 期望：未配置 SHIPPING_CHANNEL，跳过轨迹拉取（降级模式，发货与单号展示不受影响）
```

### 6.2 第二层：本地演示（MockChannel）

```dotenv
SHIPPING_CHANNEL=mock
```

`MockChannel` 完全不发网络请求，按 `crc32(公司编码+运单号)` **确定性**生成轨迹：

| 运单号 | 返回轨迹 |
|---|---|
| 任意（如 `SF12345678`） | 揽收 → 运输中 → 派送中（3 条） |
| **以 `OK` 结尾**（如 `SF12345678OK`） | 上述 3 条 + **已签收**（4 条），可演示 `delivered_at` 回填 |

城市与快递员姓名也由种子决定，同一单号每次结果一致，便于反复演示。

验证：

```bash
php artisan shipping:pull-traces
# 用单号尾号 OK 的运单发货，再跑一次，检查 shippings.delivered_at 是否回填
```

### 6.3 第三层：单元测试（Http::fake）

**不发真实请求**，用 Laravel HTTP fake 断言请求与响应。这是验证渠道实现正确性的主力手段。

已有测试：

| 文件 | 覆盖 |
|---|---|
| `backend/tests/Feature/Kuaidi100ChannelTest.php` | TC-KD100-01~09：密钥校验、**签名规范**、编码转换、手机号必填、虚拟号截取、文案→阶段映射、state 兜底、各类失败收敛 |
| `backend/tests/Feature/Kuaidi100AutoNumberTest.php` | 成功候选排序、字典外公司丢弃、601/701/201 降级返回空 |
| `backend/tests/Feature/ShippingPhoneIntegrationTest.php` | 发货链路手机号固化与回落 |
| `backend/tests/Feature/ShippingTracePullTest.php` | 拉取服务的去重、状态推导、失败计数 |
| `backend/tests/Feature/BatchShipApiTest.php` | 批量发货 + 识别 warnings |

#### 写法模板

```php
use Illuminate\Support\Facades\Http;

// 1. 注入配置（不要依赖真实 .env）
beforeEach(function () {
    config([
        'services.shipping.key' => 'TESTKEY',
        'services.shipping.customer' => 'TESTCUSTOMER',
        'services.shipping.query_url' => 'https://poll.kuaidi100.com/poll/query.do',
    ]);
});

// 2. 伪造响应 —— 快递100 成功体
Http::fake(['*' => Http::response([
    'message' => 'ok', 'status' => '200', 'state' => '3',
    'data' => [
        ['context' => '快件已揽收', 'time' => '2026-09-01 09:00:00', 'ftime' => '2026-09-01 09:00:00'],
        ['context' => '快件已签收，感谢使用', 'time' => '2026-09-02 11:00:00', 'ftime' => '2026-09-02 11:00:00'],
    ],
], 200)]);

// 3. 断言发出的请求（签名是重点）
$result = (new Kuaidi100Channel)->query('YTO', 'YT8888888', '13800001111');

Http::assertSent(function ($request) {
    $body = $request->data();
    $param = (string) $body['param'];

    return $body['sign'] === strtoupper(md5($param.'TESTKEY'.'TESTCUSTOMER'))
        && json_decode($param, true)['com'] === 'yuantong';
});

expect($result->success)->toBeTrue()
    ->and($result->toTraceStatus())->toBe('delivered');
```

#### 三个已知坑（踩过）

1. **`Http::fake()` 首个 stub 优先** —— 同一测试里二次调用 `Http::fake()` 不会覆盖前一个。要模拟「多次不同响应」必须用 `Http::sequence()`；
2. **测试里的 FakeChannel 要同步接口签名** —— 接口加了可选 `?string $phone = null` 后，测试中的实现类必须同步，否则报抽象方法不匹配；
3. **全局函数名必须唯一** —— 测试文件里的 `function xxx()` 是全局的，跨文件重名会导致全量运行时 `Cannot redeclare`。用文件特有前缀（如 `kd100Body()`）。

#### 跑测试

```bash
cd backend

# 单文件
php -d memory_limit=1G vendor/bin/pest tests/Feature/Kuaidi100ChannelTest.php

# 物流相关全跑
php -d memory_limit=1G vendor/bin/pest tests/Feature/Kuaidi100ChannelTest.php \
    tests/Feature/Kuaidi100AutoNumberTest.php \
    tests/Feature/ShippingPhoneIntegrationTest.php \
    tests/Feature/ShippingTracePullTest.php \
    tests/Feature/BatchShipApiTest.php

# 全量回归
php -d memory_limit=1G vendor/bin/pest
```

基线：**1484 passed / 0 failed**（SQLite 自动迁移，无需手工建库）。

### 6.4 第四层：管理端前端测试（Vitest + vi.mock）

`admin/tests/shipping-admin.test.ts` 覆盖发货弹窗、批量发货、字典页、物流监控页。识别相关目前只覆盖到「批量导入 warnings 展示」（`TC-047-F12` / `TC-047-F13`）—— 该路径的识别发生在后端，前端只需 mock 响应，所以现有 mock 里**没有** `detectShippingCompany`。

Mock 方式是 `vi.hoisted()` + `vi.mock()` 接管整个 `@/api/order` 模块。现有 mock 覆盖了 `getOrders` / `shipOrder` / `getShippings` / `pullShipping` / `batchShipImport` 等；**要为单条发货的识别按钮补测试时**，按下面模板加：

```ts
const { detectShippingCompanyMock, batchShipImportMock } = vi.hoisted(() => ({
  detectShippingCompanyMock: vi.fn(),
  batchShipImportMock: vi.fn(),
}))

vi.mock('@/api/order', () => ({
  // ... 其余 API 用 vi.fn() 兜底
  detectShippingCompany: detectShippingCompanyMock,
  batchShipImport: batchShipImportMock,
}))
```

断言用 `data-testid`（**不要用 class 选择器**，样式一改就挂）：

| testid | 用途 |
|---|---|
| `ship-company` / `ship-tracking-no` / `ship-tracking-error` | 发货弹窗表单 |
| `ship-next` / `ship-confirm-summary` / `ship-final` | 两步确认 |
| `batch-file-input` / `batch-upload` / `batch-success` / `batch-failed` | 批量发货 |
| `batch-warnings` | 识别不一致提示区 |

识别相关用例：

- `TC-047-F12` — 识别不一致时展示 `batch-warnings`（含「仅供参考」字样），但成功横幅仍在（不阻断）；
- `TC-047-F13` — 无 warnings 时不渲染提示区。

```bash
cd admin
npx vitest run tests/shipping-admin.test.ts   # 单文件
npx vitest run                                # 全量（基线 302 passed）
npx vue-tsc -b                                # 类型检查（基线 0 错）
```

### 6.5 三层 Mock 的选择

| 场景 | 用哪层 | 命令 |
|---|---|---|
| 本地开发、不想被第三方打扰 | NullChannel（留空） | — |
| 演示 / 联调前端展示 | MockChannel | `SHIPPING_CHANNEL=mock` |
| 验证渠道实现正确性 | Http::fake | `vendor/bin/pest` |
| 验证前端交互 | vi.mock | `npx vitest run` |
| 真实验证密钥与编码 | 真实调用 | `php artisan shipping:pull-traces` |

---

## 7. 上线验收清单

- [ ] `php artisan migrate` 已执行，`shippings.phone` 存在
- [ ] `.env` 三项已填，生产执行过 `config:cache`
- [ ] `php artisan tinker` 确认渠道类是 `Kuaidi100Channel` 且 `available()` 为 `true`
- [ ] 8 家常用快递 `channel_code` 已核对（管理端「订单 → 快递公司」）
- [ ] 用**顺丰单号**发一单测试单，确认手机号已固化到 `shippings.phone`
- [ ] `php artisan shipping:pull-traces` 输出成功数 > 0，`failed` 为 0
- [ ] 用户端订单详情能看到轨迹时间线
- [ ] 用尾号 `OK` 的运单（mock 模式）验证签收回填 `delivered_at`
- [ ] 确认调度未被改成小于 30 分钟
- [ ] [待办] 向快递100 商务确认「40 天同号去重」规则

---

## 8. 故障排查对照表

| 现象 | 原因 | 处理 |
|---|---|---|
| 命令输出「未配置 SHIPPING_CHANNEL」 | `SHIPPING_CHANNEL` 为空或配置未刷新 | 填值后 `php artisan config:clear` |
| `available()` 为 false | `key` 或 `customer` 任一为空 | 核对 `.env`，注意前后空格（读取时已 `trim`） |
| 一直返回「业务员未开通」 | 未购买查询套餐 | 购买后自动可用 |
| 顺丰/中通一直失败 | 缺手机号 | 查该单 `address_snapshot.contact_phone` |
| 报文「查询过于频繁」 | 触发锁单 | 停止手动调用，等 30 分钟；恢复后用 `--include-failed` 重试 |
| 签名错误 | `param` 被 urlencode 了，或 key/customer 填反 | 用 §6.3 的单测核对签名算法 |
| 轨迹一直停在「运输中」 | 文案未命中关键词且 state 兜底未生效 | 补 `KEYWORD_STAGES` 关键词 |
| 字典改了不生效 | 进程内静态缓存（`CarrierCode::$forward`/`$reverse`） | `php artisan queue:restart` 或调 `CarrierCode::flushCache()` |
| 仓配回传的编码查不到轨迹 | 仓方回的编码未登记进 `carrier_codes`，落库时保留原值（日志有 warning） | 管理端「订单 → 快递公司」补对应渠道编码，详见 `docs/design/CubeShop_Logistics_Layering_and_CarrierCode_v1.0.md` |
| 识别总是返回空 | 未买查询套餐（601）、key 缺失（701）、单号不合规（201） | 看 `laravel.log` 里「快递100 智能单号识别不可用」的 reason |
| 订单页物流信息重复、满屏 | MockChannel 曾用 `now()` 生成轨迹时间 → 去重键 `occurred_at\|context` 每次都变 | 已修复（改为确定性时间）；历史脏数据跑 `php artisan migrate` 由迁移 `000111` 按「同一天同一描述保留最早一条」清理 |
| 后台切换渠道后没生效 | 配置缓存未失效 | 等 5 分钟（ConfigService 缓存 TTL 300s）或 `php artisan cache:clear` |

---

## 9. 相关源码索引

| 关注点 | 文件 |
|---|---|
| 渠道接口 | `backend/app/Support/Shipping/ShippingChannelInterface.php` |
| 快递100 实时查询 | `backend/app/Support/Shipping/Kuaidi100Channel.php` |
| 快递100 智能识别 | `backend/app/Support/Shipping/Kuaidi100AutoNumber.php` |
| 降级空渠道 | `backend/app/Support/Shipping/NullChannel.php` |
| 演示 Mock | `backend/app/Support/Shipping/MockChannel.php` |
| 轨迹拉取服务 | `backend/app/Services/Shipping/TracePullService.php` |
| 拉取命令 | `backend/app/Console/Commands/PullShippingTraces.php` |
| 手机号固化 | `backend/app/Services/Order/OrderService.php`（`shipForShipment`） |
| 手机号兜底 | `backend/app/Models/Shipping.php`（`resolvePhone`） |
| 批量校验 + 识别 | `backend/app/Services/Order/BatchShipService.php` |
| 识别接口 | `backend/app/Http/Controllers/Admin/OrderController.php`（`detectCompany`） |
| 渠道注册 + DB 覆盖 env | `backend/app/Providers/AppServiceProvider.php` |
| 渠道查看/切换 + 轨迹详情 | `backend/app/Http/Controllers/Admin/ShippingController.php` |
| 配置分组登记 | `backend/app/Support/ConfigGroup.php`（`shipping` → 物流配送） |
| 重复轨迹清理 | `backend/database/migrations/2026_09_22_000111_prune_duplicate_shipping_traces.php` |
| 编码解析真源 | `backend/app/Support/CarrierCode.php` |
| 入站编码归一 | `backend/app/Services/Wms/Callback/Handlers/DeliveryOrderConfirmHandler.php` |
| 调度 | `backend/routes/console.php:25` |
| 前端发货弹窗 | `admin/src/views/order/OrderView.vue` |
| 前端批量发货 | `admin/src/views/order/BatchShipView.vue` |
| 快递公司字典页 | `admin/src/views/order/ExpressCompanyView.vue` |
