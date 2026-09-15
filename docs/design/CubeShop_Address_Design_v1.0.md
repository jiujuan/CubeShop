# CubeShop 收货地址管理设计方案 v1.0

> 状态：评审稿　|　范围：用户端地址簿（已实现，纳入盘点）+ 下单选地址（已实现，纳入盘点）+ 后台管理地址（新增，本文重点）　|　关联文档：API v1.0 §3.3~3.7 / §6.1、Database Design §2.2、Roadmap P3/P4

---

## 1. 结论（先看这里）

1. **后台不设独立的「地址管理」一级菜单**。地址入口收口在「用户管理 → 用户详情」内，以区块形式呈现。理由见 §3 方案对比。
2. **后台定位为「只读查看 + 受限代改」**，明确不提供代用户新增、删除、设默认。收货地址是用户个人数据，用户的增删主权保留在前台；后台只解决客服场景「用户口头反馈地址填错，需要协助修正」这一个真实需求。
3. **权限最小化分层**：新增 `address.view`（查看，默认分配 operator）与 `address.manage`（代改，默认仅 super_admin）。看和改分离，代改默认不放开给运营，需要时再授权。
4. **零数据库迁移**：现有 `user_addresses` 表与 `sys_operation_log` 完全够用，不新增表、不加字段。
5. **快照不可变是铁律**：订单收货信息以下单时刻的 `orders.address_snapshot` 为准，后台代改地址、用户改地址、删地址均不影响历史订单。这条已在前台实现，本文重申并要求后台侧不得提供任何「改历史订单地址」的快捷入口（待发货改址属订单域功能，见 §8 扩展项）。

---

## 2. 现状盘点（P3/P4 已交付）

### 2.1 数据模型（`user_addresses`，Database Design §2.2）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigIncrements | 主键 |
| user_id | unsignedBigInteger | 所属用户，外键 `cascadeOnDelete`，索引 |
| contact_name | string(64) | 收货人 |
| contact_phone | string(20) | 手机号，正则 `^1[3-9]\d{9}$` 校验 |
| province / city / district | string(64) nullable | 省市区 |
| detail_address | string(255) | 详细地址 |
| is_default | boolean | 默认地址，事务内互斥保证唯一 |
| timestamps + softDeletes | — | 软删除，硬删用户时外键级联 |

### 2.2 已实现能力

| 能力 | 位置 | 说明 |
|---|---|---|
| 地址簿 CRUD | `AddressController`（/user/addresses） | 首个地址自动设默认；设默认事务内互斥；软删除；列表默认地址置顶 |
| 手机号脱敏 | `AddressController::format()` | 列表返回 `138****0000` + `contact_phone_full` 仅供编辑回显 |
| 下单选地址 | web `CheckoutView` | 卡片单选，默认地址预选，支持下单前临时跳转新增 |
| 下单快照 | `OrderService::createFromCart` | 按 `address_id` 取地址写入 `orders.address_snapshot`（JSON），后续发货/退款展示全部走快照 |
| 数据归属校验 | 全部用户侧接口 | `where('user_id', $request->user()->id)` 强约束，无法越权操作他人地址 |

### 2.3 缺口

后台无法查看与协助修正用户地址。客服场景（用户来电：地址省市区选错）当前只能让用户自行操作，无服务兜底。

---

## 3. 后台入口方案对比

| 维度 | 方案 A：独立一级菜单「地址管理」 | 方案 B：用户管理详情内嵌地址区块（推荐） |
|---|---|---|
| 业务匹配度 | 差。地址从不脱离用户独立存在，全局地址列表没有对应真实工作流 | 好。客服路径就是「先定位用户 → 再看/改地址」，与现有用户管理页天然衔接 |
| 隐私暴露面 | 大。一级菜单鼓励全量浏览全部用户地址，最小暴露原则被破坏 | 小。按用户按需查看，一次只暴露一个个体的数据 |
| 权限与留痕 | 需要额外关联操作人→用户→地址三元关系，日志可读性差 | 天然带 user 维度，操作日志语义完整（编辑用户 X 的地址） |
| 实现成本 | 需新建列表页、筛选区、分页等一整套页面 | 复用用户详情弹窗，增量小 |
| 误操作风险 | 全列表环境下改错行概率更高 | 用户上下文明确，风险低 |

**结论：选 B。** 独立菜单是典型的「为管理而管理」，收益为负。

---

## 4. 总体设计

### 4.1 权限模型

新增权限码（对齐 API 文档 §9 权限码参考风格）：

| 权限码 | 能力 | super_admin | operator | customer |
|---|---|---|---|---|
| address.view | 查看指定用户的地址列表 | ✅ | ✅（客服核对地址必需） | ❌ |
| address.manage | 代用户修改地址内容 | ✅ | ❌（默认不放开，见下） | ❌ |

operator 默认不给 `address.manage` 的理由：代改他人收货信息是敏感写操作，默认最小授权；运营真实需要时由超管显式授权，权限变更本身也应留痕。两个权限码均只进 `RolePermissionSeeder::PERMISSIONS` 全量集（super_admin 自动获得），operator 的 `syncPermissions` 白名单追加 `address.view`。

### 4.2 页面结构

```
后台 admin
└── 用户管理（/users，已有）
    └── 用户详情弹窗
        ├── 基本资料区（已有）
        ├── 最近订单区（已有）
        └── 收货地址区（新增）
            ├── 地址卡片列表：收货人 / 脱敏手机 / 省市区+详址 / 默认标签
            ├── 「代为修改」按钮（v-permission:address.manage，逐条操作）
            └── 代改弹窗 → 二次确认弹窗（提示快照不可变）→ 提交
```

前台用户端与下单链路维持现状，不在本次改动范围。

### 4.3 接口清单（挂在 `/api/admin` 前缀，Sanctum 认证）

| 方法 | 路径 | 权限 | 用途 |
|---|---|---|---|
| GET | /admin/users/{userId}/addresses | address.view | 某用户地址列表（默认地址置顶，同前台排序） |
| PUT | /admin/addresses/{id} | address.manage | 代改地址内容（不含 is_default） |

不做后台新增/删除/设默认接口（§1 结论 2）。`PUT /admin/addresses/{id}` 不采用 `/admin/users/{userId}/addresses/{id}` 三层嵌套：地址 ID 全局唯一，两层足够，嵌套只会让路由与日志更啰嗦。

---

## 5. 接口详细设计

### 5.1 GET /admin/users/{userId}/addresses

- 校验：`userId` 存在（`sys_user`，含 trashed 则 404），否则 40004。
- 响应：地址数组，字段与前台 `format()` 一致（`contact_phone` 脱敏、附 `contact_phone_full` 供代改回显），另附 `updated_at`（客服判断信息新旧）。
- 排序：`is_default desc, id desc`，与前台一致。
- 软删除地址不返回（与前台口径一致；订单快照不受影响）。

### 5.2 PUT /admin/addresses/{id}

请求体（全部 `sometimes`，只更新提交的字段）：

```json
{
  "contact_name": "张三",
  "contact_phone": "13800001111",
  "province": "广东省",
  "city": "深圳市",
  "district": "南山区",
  "detail_address": "科技园南路 88 号"
}
```

- 校验规则复用前台 `validateAddress(forUpdate: true)`：手机号正则、长度上限，保证两端数据标准一致。
- 明确不接受 `is_default` / `user_id`：传入即 40000（显式拒绝优于静默忽略，防止调用方误以为改成了默认）。
- 服务端流程：
  1. 查地址（软删除视为不存在 → 40004）。
  2. 记录 before 快照。
  3. 事务内更新。
  4. `OperationLogService::record(adminId, 'address', 'update_address', 'user_address', id, before/after JSON)`。
- 响应：更新后的地址（脱敏格式）+ `message: "地址已代为修改"`。
- 错误码：40000 参数/传了禁改字段、40003 无权限、40004 不存在、40001 未登录。

---

## 6. 前端页面设计（admin）

- **用户详情弹窗**新增「收货地址」区块：打开详情时与 `recent_orders` 一并并行请求。空态显示「该用户暂无收货地址」。
- **地址卡片**：`联系人 手机` 一行 + 省市区详址一行，默认地址加蓝色 `默认` 标签；手机号按接口脱敏展示。
- **代改按钮**：`v-permission="'address.manage'"` 控制显隐（指令已具备）。仅超管可见，运营只读。
- **代改弹窗**：表单预填（手机号用 `contact_phone_full` 回显），保存后弹二次确认：**「修改仅对用户后续下单生效，历史订单收货信息以订单快照为准，不会变化。」** 确认后提交并刷新区块。
- 提交成功 toast 提示「已记录操作日志」不必要——日志在后台发生，无需打扰操作者，仅成功/失败提示即可。

---

## 7. 安全与合规

| 关注点 | 设计 |
|---|---|
| 隐私最小暴露 | 无全局地址列表接口；只能按用户逐个查看；手机号列表脱敏 |
| 操作留痕 | 代改写 `sys_operation_log`（module=address，含 before/after 完整快照），可在已有「操作日志」页审计 |
| 越权防护 | 接口无用户侧入参可指定 user_id，地址自身携带归属；`address.manage` 仅超管 |
| 快照一致性 | 后台任何操作不触碰 `orders.address_snapshot`；文档层面禁止后续开发给地址模块加「同步历史订单」类逻辑 |
| 数据保留 | 软删除地址保留可追溯（审计/客诉举证），但不出现在任何列表中 |

---

## 8. 边界场景

| 场景 | 行为 |
|---|---|
| 代改时地址刚被用户删除 | 40004，前端提示「地址已被用户删除」并刷新列表 |
| 下单时所选地址已被删除 | 40004（现有行为，保持） |
| 用户账号被禁用 | 地址保留不可见不可改（用户端整体不可用），禁用解除后恢复 |
| 用户注销（软删） | 地址随用户保留；后台用户列表不含已注销用户，地址入口随之消失；硬删用户由外键级联清理 |
| 地址数量 | 前台 `store` 增加每用户上限 20 条校验（超出 40000）——独立小增强，随本方案一并实现 |
| 代改并发 | 同一地址并发修改为最后写入胜出；操作日志各留一条，可追溯 |

### 扩展项（不在本期，挂 Roadmap 候选）

- **待发货订单改址**：订单域接口 `PUT /admin/orders/{id}/address`，仅 `paid` 状态可改，直接更新 `order.address_snapshot` 并留痕。属订单业务，不与地址簿耦合。
- 省市区三级联动选择器（前端体验项）。

---

## 9. 数据库影响评估

**零迁移。** 复用 `user_addresses`、`sys_operation_log`。地址上限 20 为应用层常量（`AddressController`），不入库。

---

## 10. 实施清单

| 项 | 文件 | 内容 |
|---|---|---|
| 权限码 | `RolePermissionSeeder.php` | PERMISSIONS 追加 `address.view` / `address.manage`；operator 白名单追加 `address.view`（幂等，可重跑） |
| 后端 | `Admin/AddressController.php`（新建） | §5 两个接口，复用前台校验规则与 format 结构 |
| 路由 | `routes/api.php` | admin 组追加两条路由，分别挂 `permission:address.view` / `permission:address.manage` |
| 前端 | `admin/src/api/user.ts`（或独立 address.ts） | 两个 API 函数与类型 |
| 前端 | `admin/src/views/user/UserListView.vue` | 详情弹窗地址区块 + 代改弹窗 + 二次确认 |
| 文档 | `CubeShop_API_v1.0.md` | 新增 8.9 节；权限码表追加两行 |
| 测试 | `tests/Feature/UserAddressAdminTest.php` | 见 §11 |

预计工作量：后端 0.5 天 + 前端 0.5 天（含联调）。

---

## 11. 测试要点

1. operator（有 view 无 manage）：能查地址；代改返回 40003。
2. 超管代改成功 → `user_addresses` 更新、`sys_operation_log` 新增 module=address 记录且 before/after 完整。
3. 请求体携带 `is_default` → 40000，且默认地址未被改动。
4. 代改后用户在前台查看：新地址生效；**历史订单详情快照不变**（关键断言）。
5. 代改已被软删除的地址 → 40004。
6. 地址上限：第 21 条新增被拒 40000。
7. 手机号格式非法 → 40000（前后台同一正则）。
8. 回归：前台地址簿 CRUD、下单选地址、默认地址互斥不受影响。
