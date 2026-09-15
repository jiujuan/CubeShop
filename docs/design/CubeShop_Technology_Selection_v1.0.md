# CubeShop 技术选型

| 项目 | 内容 |
|------|------|
| 版本 | V1.0 |
| 日期 | 2026-09-15 |
| 适用产品 | CubeShop V1.0 MVP 及后续迭代 |
| 关联文档 | PRD、数据库设计、API 接口设计、开发路线图 |

---

## 1. 选型结论（一览）

| 层级 | 技术 | 说明 |
|------|------|------|
| 后端框架 | **PHP Laravel 13** | API、业务逻辑、权限、队列、定时任务 |
| 前端框架 | **Vue 3 + TypeScript + Vite** | SPA 管理端 |
| UI | **Tailwind CSS + shadcn-vue + cn** | 高定制企业后台样式，对齐高信息密度 |
| 图标 | **Lucide** | 线性图标，与简洁企业风一致 |
| 数据库 | **PostgreSQL 14+** | 主库（见 `CubeShop_schema.sql`） |
| 认证 | **Laravel Sanctum** | 独立账号体系，对接本系统 `sys_user` |
| 缓存/队列 | **Redis**（如果没有用 postgresql 替换） | 缓存、队列、会话 |
| 权限 | **spatie/laravel-permission** + 业务权限校验 | 功能权限（菜单/按钮） |
| 测试 | **前端：Vitest，后端：Pest** | 单元测试、集成测试、冒烟测试、回归测试 |

---

## 2. 前端技术栈

### 2.1 核心

| 技术 | 用途 |
|------|------|
| Vue 3 | 视图层（Composition API） |
| TypeScript | 类型安全，降低表单/列表字段返工 |
| Vite | 构建与开发服务器 |
| Vue Router | 路由、菜单、权限守卫 |
| Pinia | 全局状态（用户、权限、字典） |
| Axios | HTTP 请求 |
| Vitest | 单元测试、集成测试、冒烟测试、回归测试 |

### 2.2 UI 与样式

| 技术 | 用途 |
|------|------|
| **Tailwind CSS** | 原子化样式，控制间距、布局、响应式 |
| **shadcn-vue** | 基于 Radix + Tailwind 的高质量组件（Table、Dialog、Form、Select 等） |
| **cn**（`clsx` + `tailwind-merge`） | 条件 class 合并 |
| **Lucide**（`lucide-vue-next`） | 线性图标库 |

**UI 约定（与原型一致）**：
- 侧栏背景：浅蓝色 `#e6f4ff`
- 主色：`#1677ff`
- 列表、筛选、表单采用高信息密度（紧凑行高、小间距）
- 不采用 Ant Design Vue / Element Plus 作为主组件库

### 2.3 推荐前端依赖（参考）

```text
vue
vue-router
pinia
axios
typescript
vite
tailwindcss
class-variance-authority
clsx
tailwind-merge
lucide-vue-next
radix-vue
dayjs
@vueuse/core
```

### 2.4 前端工程约定

- 路径别名：`@/` → `src/`
- 组件：`components/ui`（shadcn）+ `components/biz`（业务组件）
- API：按领域拆分 `api/sales.ts`、`api/inventory.ts` 等
- 权限：路由 `meta` + 按钮级指令/组件（与后端权限码对齐）

---

## 3. 后端技术栈

### 3.1 核心

| 技术 | 用途 |
|------|------|
| Laravel 13 | Web/API 框架 |
| PHP 8.2+ | 运行时 |
| PostgreSQL 14+ | 主数据库 |
| Redis | 缓存、队列、限流 |
| Pest | 单元测试、集成测试、冒烟测试、回归测试 |

**特别说明**：

- 如果没有 Redis，那么用 postgresql 作为缓存、队列、限流的后端存储

### 3.2 认证与权限

| 技术 | 用途 |
|------|------|
| **Laravel Sanctum** | SPA / Token 认证 |
| **本系统 `sys_user`** | 用户主数据 |
| **spatie/laravel-permission** | 角色、功能权限（菜单/按钮） |

**认证流程**：

1. 登录校验 `sys_user`（用户名 + 密码哈希）
2. Sanctum 颁发 Token
3. 中间件：`auth:sanctum` 保护业务路由
4. 写操作按权限码校验

### 3.3 业务配套包

| 技术 | 用途 |
|------|------|
| maatwebsite/excel | 列表与报表导出 |
| Laravel Queue（redis） | 可选异步任务（大批量导出等） |
| Laravel Storage | 附件（后续扩展） |
| DB Transaction | 出库/入库/调拨/核销等强一致事务 |

### 3.4 推荐后端依赖（参考）

```text
laravel/framework
laravel/sanctum
spatie/laravel-permission
maatwebsite/excel
predis/predis 或 phpredis
```

---

## 4. 基础设施

| 组件 | 建议 | 用途 |
|------|------|------|
| Web | Nginx + PHP-FPM | 反向代理与 PHP 运行 |
| DB | PostgreSQL 14+ | 执行 `CubeShop_schema.sql` + `CubeShop_seed.sql` |
| Cache/Queue | Redis 6+ | 缓存 + 队列 |
| 容器（推荐） | Docker Compose | 本地与部署环境一致 |

**Docker Compose 典型服务**：`app`（PHP）、`nginx`、`postgres`、`redis`（可选 `queue` worker）。

---

## 5. 与业务能力的映射

| 业务能力 | 技术落点 |
|----------|----------|
| 登录 / 会话 | Sanctum + `sys_user` |
| 菜单与按钮权限 | spatie + 前端路由/指令 |
| 库存加减与流水 | InventoryService + DB Transaction |
| 销售出库闭环 | 事务：扣库存 + 流水 + 订单数量 + 应收 |
| 采购入库闭环 | 事务：加库存 + 流水 + 订单数量 + 应付 |
| 调拨 / 盘点 | 事务内双向库存 + 流水 |
| 收款/付款核销 | 事务更新应收应付金额与状态 |
| 高密度列表/表单 | Tailwind + shadcn-vue Table/Form |
| 报表导出 | Excel |
| 图标 | Lucide |

---


**文档结束**
