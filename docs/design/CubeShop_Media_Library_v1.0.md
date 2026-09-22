# CubeShop 统一图片资源管理中心 —— 分析与实现方案 v1.0

> 目标：让后台能统一查看、搜索、替换、上传、删除全站图片，并让其他模块从同一处选图。
> 本文先分析代价与收益，再给出**可以分阶段落地、且不必一次性大爆炸重构**的实现方案。
>
> 相关：
> - `docs/design/CubeShop_Kuaidi100_Phase1_v1.0.md`（同为"先止血再建设"的三期写法）
> - `docs/design/CubeShop_Logistics_Layering_and_CarrierCode_v1.0.md`（同为"唯一真源"思路）

---

## 1. 现状：图片散落到什么程度

### 1.1 散落清单

| 业务实体 | 模型 | 字段形态 | 迁移 |
|---|---|---|---|
| 商品主图 | `Product` | `main_image` string URL | `2026_09_15_000004` |
| 商品相册 | `ProductImage` | `url` string URL（独立表） | 同上 |
| 品牌 logo | `Brand` | `logo` string URL | `2026_09_16_000007` |
| Banner / 轮播 | `HomeBanner` | `image` string URL | `2026_09_18_000055` |
| 用户 / 管理员头像 | `User` / `SysUser` | `avatar` string URL | `000022` / `000001` |
| 订单行项快照图 | `OrderItem` | `sku_image` string URL（**快照语义**） | `2026_09_15_000007` |
| 评价晒图 | `Review` | `images` JSON URL 数组 | `2026_09_16_000010` |
| 退货 / 售后凭证 | `Refund` | `images` / `admin_images` JSON 数组 | `2026_09_19_000074` |
| 客服工单留言图 | `CsTicketMessage` | `images` JSON 数组 | `2026_09_17_000038` |
| 线下转账凭证 | `Payment` | `voucher_url` string URL | `2026_09_16_000019` |
| 站点 logo | `SystemConfig` | `site.logo` / `site.logo_small` | `2026_09_20_000094` |
| CMS 正文 / 区块 | `CsFaqArticle` | `content_md` Markdown 内联 + `blocks` JSON | `000043` / `000097` |

补充澄清：

- **商品分类没有图片字段**（`categories` 表无 icon），不存在"分类图标"要迁移；
- `cs_faq_category.icon` 存的是 lucide 图标名（32 字符），**不是图片 URL**，不纳入；
- **全项目没有任何一处把图片存成 id** —— 清一色是完整 URL、JSON 里的 URL、或 Markdown 里的 URL。

### 1.2 上传与存储机制

- 唯一上传服务：`App\Services\Common\FileUploadService::uploadImage(UploadedFile, $module)`；
- 入口：`Admin\UploadController::store()`；另有 `uploadVoucher()`（凭证专用）；
- 校验：`ALLOWED_MIMES = jpg/jpeg/png/gif/webp`，`MAX_SIZE_KB = 5120`；规则分散在 Controller Request 与 Service 两处；
- `composer.json` **未安装** `intervention/image`、`spatie/laravel-medialibrary` 等任何图片处理包 —— 无压缩、无缩略图、无水印；
- 磁盘：`public` 盘 root = `storage/app/public`，`url` 配置为 `env('APP_URL').'/storage'`；`.env` 为 `FILESYSTEM_DISK=local`；
- `storage/app/public/uploads` 在 `storage/app/public/.gitignore` 中整体被忽略 —— **图片不入库 CM，备份策略需单独考虑**。

### 1.3 三个已经存在的问题

| # | 问题 | 证据 | 后果 |
|---|---|---|---|
| ⚠️ **A** | **URL 把域名写死进了数据库** | `'url' => rtrim(env('APP_URL'),'/').'/storage'` + `APP_URL=http://localhost:8000`；实测 `products.main_image = http://localhost:8000/storage/uploads/products/20260918/Xd6.png` | **部署上线当天，全部历史图片 404**；本地 3000/5173 端口前端也是跨域取图 |
| ⚠️ **B** | **删除零联动** | 全仓 `Storage::delete` / `deleting` 钩子数量为 **0** | 272 个文件 90MB，数据库引用约百条，**孤儿已过半且线性增长**；换图也不回收旧文件 |
| ⚠️ **C** | **无缩略图** | 未装任何图像处理包 | 列表页直接加载原图（均值约 330KB/张），移动首屏流量浪费；媒体库网格视图也无法高效渲染 |

> **A 与 B 必须在媒体库之前修**，且与媒体库无关 —— 但它们恰恰是本次建设最扎实的收益来源。
> 更关键的是：**修这两件事的正确姿势，天然产出了媒体库的一半**（一张图片注册表）。
> 所以顺序不是"做媒体库 → 顺带治理"，而是"修 bug → 顺产出媒体库"。

---

## 2. 关键决策

### 2.1 决策一：不要把业务表的 URL 改成 `media_id`

这是多数媒体库方案的默认思路，**本项目明确不采用**。

理由：

1. **性能**：商品列表一次渲染几十张图，存 id 就得 N 次查询或 join。为了"存储规范"付真实运行时代价，不值。
2. **语义**：`OrderItem.sku_image` 是**下单时刻快照**，它本来就该是字面值。把它改成引用，等于让历史订单跟着媒体库变动 —— 这是错的。
3. **改造面**：现有四种形态（string / JSON / Markdown / blocks），改成 id 要动所有写入点与渲染点。其中 `content_md` 内联图只能靠正则改写，是**高风险一次性手术**，回滚困难。
4. **收益不受影响**：去重、清理、搜索、选图 —— 这些真正想要的能力，**全都不需要改业务表**。

### 2.2 决策二：用"旁路注册表"（sidecar registry）

**业务字段照旧**（只把存储的 URL 规范成相对路径），**旁边另起一张 `media_files` 做资产侧索引**，两边用 `path` 关联。

```
业务表（不动 / 只改 URL 形态）           资产侧（新增）
  products.main_image   ─┐
  brands.logo           ─┤                 media_files
  reviews.images[JSON]  ─┼── 以 path 关联 ─  · md5 去重
  refunds.images[JSON]  ─┤                  · 宽高/体积/MIME
  cs_article.content_md ─┘                  · usage_count
                                            · path + disk → 渲染时才拼 URL
```

一句话：**业务侧继续存"字符串"，资产侧负责"知道这张图的一切"。**

### 2.3 决策三：不引入 `spatie/laravel-medialibrary`

- 它的心智是 polymorphic media + 业务字段改存关联 —— 正是 2.1 反对的那条路；
- 它不解决孤儿清理，也不解决 Markdown 内引用；
- 引入它等于引入一整套 migration 与 API 面，而本项目要的只有"注册表 + 选择器"，自写约 300 行；
- 符合项目一贯风格：`HtmlSanitizer`、`CarrierCode`、`PublicId` 等均为自建真源。

### 2.4 决策四：URL 必须是相对路径（新增 `MediaUrl` 助手）

修问题 A 的正确做法不是"再改一次 APP_URL"，而是**数据库只存相对路径，URL 在渲染时才拼**。

- 新增 `App\Support\MediaUrl::to(string $path): string`，内部 `Storage::disk($disk)->url($path)`；
- 所有出口（API Resource / CMS 渲染）经它转换；
- 迁移里把存量 `http://localhost:8000/storage/` 前缀批量替换为空 → 只留 `uploads/...`；
- 未来切 S3/OSS 时，只需换 `filesystems.php` 的 disk，**零数据迁移**。

⚠️ 反例警示：现在改 id 也救不了这个 bug —— URL 生成规则还是错的。**根因是"生成时机"，不是"存的键"**。

---

## 3. 数据模型（迁移 `000114`）

`media_files`

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint PK | |
| `disk` | varchar(32) | 默认 `public`，未来可为 `s3` |
| `path` | varchar(500) | 相对路径 `uploads/products/20260918/xxx.png` |
| `md5` | char(32) | 文件内容哈希，去重键 |
| `mime` | varchar(64) | `image/webp` 等 |
| `size` | unsigned int | 字节 |
| `width` / `height` | unsigned int nullable | 读原图得出（P3 才需要） |
| `original_name` | varchar(255) nullable | 供后台搜索 |
| `module` | varchar(32) | 来源模块：`product`/`brand`/`cms`/`review`/`refund`/`ticket`/`common` |
| `uploaded_by` | bigint nullable | admin 用户 id；前台上传为 null |
| `usage_count` | unsigned int default 0 | **扫描重算，非实时** |
| `last_scanned_at` | timestamp nullable | |
| `created_at` / `updated_at` | timestamp | |
| `deleted_at` | timestamp nullable | 软删，见 §5 删除策略 |

索引：

- `unique(disk, path)` —— 防止重复登记，也是业务侧的关联键；
- `index(md5)` —— 去重查询；
- `index(module, created_at)` —— 后台按模块/时间筛选；
- `index(deleted_at, usage_count)` —— 孤儿回收查询。

---

## 4. 上传链路改造（P0）

改造 `FileUploadService`，流程变为：

```
UploadedFile
  → 算 md5
  → 查 media_files 是否已有同 md5 记录
       命中：直接返回既有 path（磁盘不新增文件，天然去重）
       未命中：store 到 disk → 读元信息 → 插入 media_files
  → 返回相对 path（不再是绝对 URL）
```

要点：

- **返回值语义变了**：从"完整 URL"变成"相对路径"。为避免一次性改动所有调用点，可在 Service 内保留一个 `uploadImageUrl()` 兼容方法（内部 = `MediaUrl::to(uploadImage(...))`），后续逐步收敛；
- md5 计算与元信息读取有开销，**大批量商品导入时必须走队列**，否则导入变慢；
- `uploadVoucher()` 属于凭证，**不登记进媒体库**（凭证是敏感单据，不应出现在可浏览的图片库里，且它已按用户分日隔离）。

---

## 5. 删除策略（P1，最需要克制的部分）

`usage_count` 是**扫描得出的、非实时**的值。它不可靠，所以基于它的删除必须保守：

```
删除商品 / 文章 / Banner
  → 触发 deleting 钩子
  → 不做任何物理删除，只为这些 URL 重新计算 usage_count
  → usage_count 归 0 的记录进入「待回收」状态（软删或标记）

回收执行：
  php artisan media:prune --days=7      # 仅清理 usage_count=0 且超过 7 天
  php artisan media:prune --dry-run     # 预演，列出将被删除的文件
```

规则：

1. **永不自动删除，只有显式命令 + 预演**；
2. `usage_count > 0` 的记录**禁止物理删除**；
3. 扫描命令 `media:scan` 走一条互不重叠的定时任务（参考 `PullShippingTraces` 的 `withoutOverlapping` 写法）；
4. 扫描范围要覆盖全部四种形态：string 列、`JSON` 数组 decode、Markdown 正则 `!\[.*?\]\((.*?)\)`、`blocks` JSON —— **扫描器是这个方案里唯一"全知"的组件，必须写对**。

---

## 6. 消费侧（P2，这时才做"看得见"的部分）

### 6.1 新增权限码

按项目约定，**权限码真源 = `RolePermissionSeeder::PERMISSIONS`，Seeder 与幂等迁移同改**：

- `media.view` —— 浏览媒体库、使用选择��
- `media.upload` —— 上传
- `media.manage` —— 替换 / 删除 / 回收

### 6.2 接口

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| GET | `/admin/media` | `media.view` | 分页；`keyword`（original_name / path）、`module`、`unused=1`、`sort`、`size_from` 筛选 |
| POST | `/admin/media` | `media.upload` | 上传（复用既有 UploadController 逻辑，登记后返回） |
| PATCH | `/admin/media/{id}` | `media.manage` | 改名 / 换 module |
| POST | `/admin/media/{id}/replace` | `media.manage` | **替换文件保留 path** —— 这是最有价值的操作，换 banner 图不影响任何引用 |
| DELETE | `/admin/media/{id}` | `media.manage` | 软删（`usage_count > 0` 时拒绝） |

### 6.3 前端

- `admin/src/views/system/MediaLibraryView.vue`：网格视图（缩略图）、搜索、模块筛选、"未使用"筛选、替换、软删；
- `admin/src/components/ImagePicker.vue`：**统一选图入口**，内含「上传」与「从媒体库选择」两个 Tab；
  无 `media.view` 权限的账号（如纯商品录入岗）自动退化为纯上传、走既有 `/admin/upload`，行为与改造前一致；
- 逐接入顺序：**商品主图/相册 → Banner → 品牌 logo → CMS 区块图**（正文 Markdown 内联图放到最后，且优先级最低）；
- 顺带补齐 `AppImage` 组件（web 端统一图片兜底：懒加载 `loading=lazy` + 加载占位 `bg-slate-100` + `onerror` 失败兜底显示中性占位图标，`class` 透传根元素）。

> 首次落地只做了「媒体库页 + `ImagePicker` + 商品主图/相册接入」三件事（详见 §10.4）。
> Banner / 品牌 / CMS 的选图器接入留待下一阶段；`AppImage` 已在第二批落地（见 §10.5）。

---

## 7. 优缺点（诚实评估）

### 7.1 收益

| 收益 | 说明 |
|---|---|
| **图片可复用** | 现在是"只能上传、无法选已有图"。同一张 banner 换季复用、商品详情共用素材都变得可行 |
| **存储去重** | md5 去重后，重复上传不再占用额外空间 |
| **孤儿可治理** | 从"只增不减"变成"可扫描、可预演、可回收" |
| **域名解耦** | 相对路径 + 渲染时拼 URL，切 CDN / S3 零数据迁移 —— **顺便修掉了上线会炸的坑** |
| **替换不破引用** | 换文件保留 path，是全方案里最实用的一环 |
| **统一入口** | 后续扩展（水印、格式转换、CDN 预热、图片压缩）都只有一个切面 |

### 7.2 代价与风险

| 代价 | 说明 / 缓解 |
|---|---|
| 多一层间接⑲⑲ | 已用"旁路"把间接性压到最低：业务字段不改，渲染链路不变 |
| **`usage_count` 非实时** | Markdown 引用难以实时统计。缓解：只做软删、永不自动删、回收必须显式命令 + 预演 |
| 上传变慢 | md5 + 元信息读取有开销。缓解：批量导入走队列 |
| 多一套 UI 与权限要维护 | 可接受；P2 才引入 |
| **P0 会改动返回值语义** | `uploadImage()` 从返回绝对 URL 改为相对 path。缓解：保留兼容方法，逐步收敛 |
| 图片不入 git | `uploads` 已被忽略（既有事实），备份策略与 DB 分离，需运维侧单独约定 |
| 当前体量小，"选图器"收益不显性 | 几十张图时最硬的收益是**存储治理**，不是 UI。所以 P2 排在后面，不为"看着专业"提前做 |

---

## 8. 实施分期与工作量

| 期 | 内容 | 迁移风险 | 工作量 |
|---|---|---|---|
| **P0 止血** | 迁移 `000114` 建 `media_files`；`FileUploadService` 登记 + md5 去重 + 改存相对路径；新增 `MediaUrl`；存量 URL 前缀清洗；`media:scan` / `media:prune --dry-run` 两命令；磁盘回填登记 | **零**（不动任何业务表字段） | ≈ 1 天 |
| **P1 清理闭环** | Product / Brand / Banner / Review 加 `deleting` 钩子（只重算 usage_count，不物理删）；保守回收策略落地 | 低 | ≈ 0.5 天 |
| **P2 消费侧** | 3 个权限码（Seeder + 幂等迁移）；`/admin/media` 五接口；媒体库页 + `ImagePicker`；按 Banner → 品牌 → 商品 → CMS 顺序接入；补 `AppImage` | 低 | ≈ 2~3 天 |
| **P3 可选** | 缩略图生成（队列）；切 S3/OSS；CMS Markdown 内联图迁移（**视 P2 收益再决定，建议长期搁置**） | 中（尤其 Markdown） | ≈ 1 天起 |

**建议现在就动的是 P0。** 它是唯一"零迁移风险且修掉了两个真 bug"的一步，做完立刻能看到：孤儿清单、重复文件清单、以及不再往库里写 `localhost:8000`。

P2 是否值得，取决于 P0 跑完后**真实孤儿率和重复率有多高**——如果扫出来只有几十 KB 垃圾、零重复，那说明"选图器的复用需求"才是真痛点，可以把重心直接放 P2；反之则 P1 优先。

---

## 9. 验收清单

> 附：P0 / P1（删除联动）已于 2026-09-23 落地，演进见 §10。

- [ ] `media_files` 表建立，磁盘 272 个文件已回填登记
- [ ] 新上传一张图：既有文件已存在时，磁盘文件数**不增加**
- [ ] 数据库里新写入的是 `uploads/...`，**不再是** `http://localhost:8000/storage/...`
- [ ] 存量 `http://localhost:8000/storage/` 前缀已清洗干净
- [ ] 前端商品列表、详情、CMS 正文图片显示正常（URL 由 `MediaUrl` 拼出）
- [ ] `media:scan` 后各图 `usage_count` 与实际引用一致
- [ ] `media:prune --dry-run` 能列出孤儿且不误删在用图片
- [ ] 改 `APP_URL` 后所有图片仍正常（验证域名解耦是否真的生效）

---

## 10. 落地情况（2026-09-23：P0 + 删除联动）

### 10.1 相对路径怎么落的地

关键决定：**不在 old 出口改代码，而是在模型层用 cast 收口** —— 因为同一个字段有
`$model->attr`、`toArray()`、Resource 三条读取路径，逐出口改必然漏。

| 产物 | 职责 |
|---|---|
| `App\Support\MediaUrl` | 换算唯一真源：`to()`（写→读方向拼 URL）、`toPath()`（读→写方向归一成 `uploads/...`）、`normalizeEmbedded()` / `absoluteEmbedded()`（富文本内联图）、`toIfAsset()` 系列（嵌套结构保守版） |
| `App\Casts\MediaPath` / `MediaPathList` / `MediaRichText` / `MediaNested` | 挂在各自模型的 `$casts` 上；**挂了 cast 的列自动成为「图片列」**，删除联动也据此识别，不需要登记第二处 |
| 迁移 `000115` | 存量清洗：单值/JSON 列 → `uploads/...`，富文本与 CMS blocks → `/storage/...`，幂等可重跑 |
| `Product` / `CsFaqArticle` 的 saving 钩子 | markdown→HTML 的派生产物原本直写 `$this->attributes[...]`（绕过 cast），已补上 `normalizeEmbedded()`，否则派生产物会把域名重新写回库里 |

由此：数据类型输出给前端仍是**绝对 URL**（前端只代理 `/api`，根相对地址会打到本地端口 404），
而库里只剩域名无关的相对路径 —— 切 CDN / OSS 零数据迁移。

### 10.2 删除联动：30 天软删 + 到期通知

用户诉求是「删除要联动、软删保留 30 天、到期通知、手动或自动删」，落成三段式：

| 阶段 | 触发 | 动作 |
|---|---|---|
| ① 解除引用 | `App\Models\Traits\ReleasesMediaOnDelete`（deleted / updating+updated） | 调用 `MediaRegistry::releaseReferences()` 重算 usage_count，**归零才软删**（`deleted_at` 即窗口起点） |
| ② 自愈 | `media:scan` 重算 | 曾被软删的图若又被引用（`usage_count > 0`）自动 restore |
| ③ 回收 | `media:prune` | 满 30 天且仍无引用者才处理；默认只列清单 + 写通知日志，`--dry-run` 预演，`--force` 才真删（带二次确认，`--yes` 供自动化） |

已注册联动的模型：Product（含相册子表）/ ProductImage / Brand / HomeBanner / Review /
Refund / CsTicketMessage / CsFaqArticle；User、SysUser 头像与支付凭证**不纳入**（见下方备注）。

改动相对本文原方案的三处偏离，都是有意为之：

1. **回收窗口 7 天 → 30 天**，并加了「到期先通知」的日常调度（每天 03:20 只列清单，一个字节都不删）；
2. **`DB::afterCommit` → 即时执行**。原设计想规避「外层事务回滚误判孤儿」，但实测两个问题：
   软删本身可逆且能自愈，而 `afterCommit` 在测试事务（RefreshDatabase）里根本不会执行 ——
   行为不可观测就无法被测试保护。真正的兜底是「30 天窗口 + usages 重算」，不是延迟执行；
3. **换图也纳入联动**（`updating` 取旧快照 → `updated` 比对差集），顺手解决了 §1.3 的「换图不回收」。

### 10.3 已知限制

- `ProductImage`（相册行）在商品软删时不会被删除，所以在 `Product::referencedMediaPaths()` 里
  单独把它们合并进来了 —— 否则相册图永远算作「在用」，进不了回收窗口；
- `order_items.sku_image` 是快照，仍然享受 URL 换算（避免历史订单图裂），但**不参与 usage 统计**；
- `payments.voucher_url` 是敏感凭证，按既定口径**不登记**进媒体库（也因此它的孤儿要靠人工）；

### 10.4 落地情况（2026-09-23：P2 消费侧，第一刀落在商品选图器）

P0/P1 上线后真实扫出：277 张登记、91 张在用、186 张孤儿（≈55MB）、27 组重复 MD5 ——
孤儿率 2/3，证明「选图器复用 + 回收」是真痛点，故推进 P2。

**本批交付（已实现范围）**

| 层 | 产物 | 说明 |
|---|---|---|
| 后端 | 迁移 `2026_09_23_000116_sync_media_permissions` | 幂等把 `media.view/upload/manage` 同步进 `role_permissions`；`operator` 授 `view+upload`，`manage` 仅超管 |
| 后端 | `App\Services\Common\MediaRegistry::replaceFile()` | 替换文件保留 `path`，复用同一 `media_files` 行 |
| 后端 | `App\Http\Controllers\Admin\MediaController` | 五接口：`index`(分页+筛选) / `store`(上传登记) / `update`(改名·换模块) / `replace`(保 path) / `destroy`(软删，仍被引用则 403 拒绝) |
| 后端 | 12 条 `AdminMediaApiTest` | 覆盖分页筛选、md5 复用、改名、替换保 path、软删拒绝、无权限 403；全量 pest 1555 passed |
| 前端 | `admin/src/api/media.ts` | `getMediaList` / `uploadMedia` / `replaceMedia` / `deleteMedia` / `updateMedia` |
| 前端 | `admin/src/components/ImagePicker.vue` | 双 Tab 选图器（`upload` / `library`），`v-model:open` + `@select(urls[])`，支持单选/多选+limit，权限降级为纯上传 |
| 前端 | `admin/src/views/system/MediaLibraryView.vue` | 媒体库页：网格、搜索、模块筛选、未使用筛选、替换、软删 |
| 前端 | 路由 `media` + 菜单「媒体库」 | 挂在 configs 段后，`permission: media.view` |
| 前端 | `admin/src/views/product/ProductEditView.vue` | 主图/详情图改走 `ImagePicker`（删除裸 `<input type=file>`），保留 10 张上限与删除交互；Markdown 正文内联图仍走原 `onUploadImg` 钩子（不接入媒体库，留给 CMS 阶段） |

**校验**：admin 侧 `vue-tsc -b` 0 错、`vitest` 306 passed；backend 侧 pest 1555 passed。

**本批未做（明确留待下一阶段）**

- CMS Markdown 内联图迁移（长期搁置，视 P2 收益再定）。
- User / SysUser 头像未注册联动：账号删除是低频且高风险操作，头像回收暂由人工决定。

### 10.5 落地情况（2026-09-23：媒体库体验 + AppImage 统一兜底）

**媒体库管理页体验**
- 分页改用后台统一 `TablePagination`（与「商品列表」等列表页一致：页码折叠 + 跳页 + 统计文案），
  去掉原来自写的「上一页 / N / 下一页」；
- 缩略图点击 `openPreview` 弹出大图查看层（Teleport 到 body 的遮罩 + 大图 + 文件名，点遮罩/✕ 关闭）。

**AppImage（web 端统一图片兜底）**
- 新建 `web/src/components/AppImage.vue`：懒加载 `loading=lazy` + 加载占位 `bg-slate-100` +
  `onerror` 失败兜底（显示中性 `ImageOff` 占位，不再裂图）；`class` / `data-testid` / `@click` 等透传到根元素，可作裸 `<img>` 的 drop-in 替换。
- 已接入核心内容图：`ProductCard`（首页/搜索/收藏/购物车复用，影响面最大）、`CmsArticleBody`（正文内联商品卡）、
  `BlockGallery` / `BlockHero` / `BlockTextImage`（CMS 区块）、`DetailView`（商品主图/相册/新闻封面）、
  `NewsDetailView` / `NewsListView` / `NewsTagView`（新闻封面）。
- **刻意未改**：`ShopHeader` 的 logo 已有「无图回落内置品牌图标」的兜底，改 AppImage 反而降级，保留原逻辑；
  `v-html` 内的 CMS 正文图由后端白名单净化，不走组件。

**校验**：admin `vue-tsc -b` 0 错 + `vitest` 306 passed；web `vue-tsc -b` 0 错、改动相关测试（cms-blocks 6 / cms-page+news 25 / account-center 7）隔离全过。
web 全量并行偶发 `account-center` flaky（记忆已知：串行全过），与本次改动无关。

### 10.6 落地情况（2026-09-23：Banner / 品牌 / CMS 区块 / CMS 封面 接入选图器）

**策略：保留原上传入口 + 新增「从媒体库选择」**

按需求「仍用原上传入口」，这四个入口**不替换**原上传控件，而是在其旁新增一个「从媒体库选择」按钮，
打开 `ImagePicker`（上传 + 媒体库双 Tab）从已有资产复用图片。原上传行为（含 `uploadImage` / `uploadCmsImage`）
完全保留，因此既有上传测试零改动。

**改动点**

- 首页广告位 `HomeBannerListView.vue`：保留 `file` 上传，新增「从媒体库选择」按钮 → `ImagePicker`（单选，`module=banners`），选中回填 `form.image`。
- 品牌 `BrandView.vue`：保留 logo URL 文本框（原入口，可手填外部链接），新增缩略图预览 + 「从媒体库选择」按钮 → `ImagePicker`（单选，`module=brands`），选中回填 `form.logo`。
- CMS 区块 `PageFieldForm.vue`：`image`（单图）/ `image_list`（多图）字段保留 `uploadCmsImage` 上传，各新增「从媒体库选择」按钮 → `ImagePicker`（`image` 单选、`image_list` 多选 `limit=20`，`module=cms`），选中回填对应字段。
- CMS 文章封面 `CsFaqArticleEditView.vue`：保留封面上传 `file` 入口，新增「从媒体库选择」按钮 → `ImagePicker`（单选，`module=cms`），选中回填 `form.cover_image`。

**权限降级**：四个入口的「从媒体库选择」按钮均带 `v-permission="'media.view'"`；
无媒体库浏览权限的账号只看到原上传入口，与改造前一致，不被媒体库上线卡住。

**校验**：admin `vue-tsc -b` 0 错 + `vitest` 306 passed（含受影响测试 `cs-article-edit` 29 / `cs-faq-blocks` 8 / `product-attribute` 16 / `marketing-admin` 10 全过）。
