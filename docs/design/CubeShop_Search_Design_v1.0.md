# CubeShop 站内搜索设计方案

**版本**：v1.0
**日期**：2026-09-24
**范围**：A1 站内搜索。阶段一用 PostgreSQL 原生全文检索；阶段二可切换 Meilisearch / Elasticsearch，**业务代码零改动**
**关联**：`CubeShop_Roadmap_v1.1.md` §3.3 F10、`CubeShop_Feature_Gap_Analysis_v1.0.md` A1
**基线**：迁移最大号 `000118`（新迁移从 `000119` 起）、PG 16（`docker-compose.yml` postgres:16-alpine）、测试固定 `DB_CONNECTION=sqlite :memory:`

---

## 1. 现状与问题

| 项 | 现状 |
|----|------|
| 搜索实现 | `StorefrontProductController::index()` L37-40：`title OR subtitle LIKE '%kw%'` |
| 可搜字段 | 仅 title / subtitle。品牌名、分类名、关键词、SKU 编码、属性均不可搜 |
| 中文能力 | 无分词。`LIKE '%沙发%'` 能命中，但「北欧沙发」搜不到「北欧实木沙发」（不连续） |
| 排序 | 关键词下仍按 `sort/id` 或价格/销量，**无相关度排序** |
| 联想/热搜 | 无 |
| 索引 | `index(title)` 对 `%x%` 无效，全表扫描 |
| 前端 | `ShopHeader` 输入框 → 跳 `/search?keyword=`，`BrowseView` 复用列表接口，无联想无热搜 |

**三个硬约束（决定架构）**：

1. **测试固定 SQLite**：`phpunit.xml` 写死 `DB_CONNECTION=sqlite`、`DB_DATABASE=:memory:`。PG 的 `tsvector`/`GIN` 在测试里根本不存在 → 必须有降级路径，且降级路径行为要与现状一致（否则 1500+ 用例回归）。
2. **PG 官方镜像无 zhparser**：`postgres:16-alpine` 只带 contrib（`pg_trgm` 有），zhparser 需编译安装，生产托管 PG（RDS 类）通常也不允许装第三方扩展 → **不能依赖 zhparser**。
3. **缓存/队列默认 database**：`config/cache.php`、`config/queue.php` 默认走数据库，不假设 Redis。

---

## 2. 核心设计决策

| 决策 | 选择 | 理由 |
|------|------|------|
| D1 中文分词 | **应用层 PHP 分 bigram**，不装扩展 | 零扩展依赖；zhparser 在托管 PG 上装不了；bigram 对中文召回率足够（业界通用做法） |
| D2 索引载体 | **PG 生成列 `tsvector` + GIN 索引** | 生成列由 PG 自动维护，应用层只需写两个 text 列，无需触发器、无需同步任务；PG 16 支持 |
| D3 引擎抽象 | `ProductSearchEngine` 接口 + 4 个实现 | 与既有 `ShippingChannelInterface` / `WaybillChannelInterface` 完全同构，团队已熟悉 |
| D4 职责边界 | **引擎只返回「命中的 id + 分数」，筛选/分页/资源转换仍在 Eloquent** | 阶段二换引擎时，分类筛选、品牌筛选、属性筛选、权限、Resource 全部零改动 |
| D5 降级 | 引擎不可用时回落 `FallbackLikeEngine`（等同现状 LIKE） | 保证 SQLite 测试、PG 未就绪、第三方引擎宕机三种情况都不白屏 |
| D6 索引同步 | 复用 `Product::booted()` 的 `saving` 派生（与 `description` 派生同构） | 项目已有先例，零新增事件/Job，后台任意改商品自动生效 |
| D7 配置切换 | `config('search.engine')` + `system_configs.search.engine` 覆写 | 与 `shipping.channel` / `waybill.channel` 完全对称（含 `off` 强制降级） |
| D8 详情正文不进索引 | `description` 不参与全文索引 | bigram 会让 tsvector 膨胀 5~10 倍，GIN 索引失控；商品详情对搜索召回贡献低 |

---

## 3. 架构分层

```
Controller (Storefront\SearchController / ProductController)
   │  解析参数 → SearchCriteria（DTO）
   ▼
ProductSearchService            ← 编排：缓存 → 引擎 → 回源 → 重排 → 落词频
   │
   ├── Cache（id 列表，TTL 60s，key 含 index_version）
   │
   ▼
ProductSearchEngine（接口）
   ├── PostgresFtsEngine        ★ 阶段一默认：生成列 tsvector + GIN
   ├── MeilisearchEngine        ○ 阶段二
   ├── ElasticsearchEngine      ○ 阶段二（骨架）
   └── FallbackLikeEngine       ★ 降级：等同现状 LIKE
   │
   ▼
返回 SearchResult { ids[], scores[], total, relaxed, engine }
   │
   ▼
Eloquent: whereIn(ids) + 分类/品牌/价格/属性筛选 + 排序 + paginate
   │
   ▼
ProductResource（不变）
```

**为什么引擎只出 id 不做完整查询**：Meilisearch 也能做筛选，但让两边筛选语义完全一致成本极高（属性多值 OR/AND、价格区间、public_id 转换）。让引擎只负责召回，筛选留在 SQL，切换引擎的风险面最小。

---

## 4. 阶段一：PostgreSQL 原生全文检索

### 4.1 分词器

`App\Support\Search\SearchTokenizer`

```php
public function tokenize(string $text): string
```

规则：

| 输入片段 | 处理 | 输出 |
|---------|------|------|
| 中文连续段 | 滑窗 bigram，单字时输出该单字 | `北欧实木沙发` → `北欧 欧实 实木 木沙 沙发` |
| 英文/数字段 | 按非字母数字切分，转小写 | `iPhone15 Pro` → `iphone15 pro` |
| 中英混排 | 分别按上述规则处理再拼接 | `iPhone15 手机壳` → `iphone15 手机 机壳` |
| 标点/空白 | 丢弃（视作分隔符） | — |
| 停用词 | 英文常见词过滤（内置 30 词表，可配） | `the a of` 丢弃 |

约束：
- 输出**全小写**、空格分隔、去重（保留首次出现位置无关）
- ⚠️ **空白是分词边界，不跨段拼 bigram**：「北欧 实木沙发」→ `北欧 实木 木沙 沙发`（不产生 `欧实`）。
  跨段拼接会造噪音；由此产生的召回损失由 §4.4 降级链的 AND→OR 兜底
- 单字中文查询（如「椅」）bigram 退化为 unigram，此时必须走 `FallbackLikeEngine`（否则召回噪声过大）→ 见 §4.4 降级链第 0 条
- 长度上限：`search_title` 截断 1000 字符，`search_body` 截断 4000 字符（防 GIN 膨胀）；
  截断以**整 token** 为粒度，**首个 token 无条件收录**（连它都超限就返回空串 = 该字段静默失去索引，比略微超限更难排查）

**实现落点**：`App\Support\Search\SearchTokenizer`（S1-01 已落地）

⚠️ **不要用 `\p{Han}` 判定汉字**：实测本环境 PCRE 把 U+3002「。」也算作 Han，中文标点因此参与 bigram，
产出「。沙」这类噪音 token。已改为显式列出 CJK 区段 `\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}`
（扩展 B 区罕见字电商用不到，且需 UTF-16 支持，不纳入）。

### 4.2 索引列与生成列

迁移 `000119_add_search_columns_to_products`：

```php
// 通用：两个 text 列（SQLite 也建，供降级路径与单元测试使用）
Schema::table('products', function (Blueprint $t) {
    $t->text('search_title')->nullable()->comment('检索文本·标题域（应用层分词写入）');
    $t->text('search_body')->nullable()->comment('检索文本·正文域（副标题/关键词/SKU/品牌/分类/属性）');
});

// 仅 PG：生成列 + GIN
if (DB::connection()->getDriverName() === 'pgsql') {
    DB::statement(<<<'SQL'
        ALTER TABLE products
        ADD COLUMN search_vector tsvector
        GENERATED ALWAYS AS (
            setweight(to_tsvector('simple', coalesce(search_title, '')), 'A') ||
            setweight(to_tsvector('simple', coalesce(search_body,  '')), 'B')
        ) STORED
    SQL);

    DB::statement('CREATE INDEX products_search_vector_gin ON products USING GIN (search_vector)');
    DB::statement("COMMENT ON COLUMN products.search_vector IS '全文检索向量（生成列，由 search_title/search_body 派生）'");
}
```

> **PG < 12 的降级**：生成列不可用，改为 `BEFORE INSERT OR UPDATE` 触发器维护 `search_vector`（PL/pgSQL 片段见 §10.2）。当前 PG 16 不需要，仅作备案。

**字段来源（写入时机见 §4.3）**：

| 域 | 来源 | 权重 |
|----|------|------|
| `search_title` | `title` | A |
| `search_body` | `subtitle`、`keywords`、`sku_code`（该商品全部 SKU 编码拼接）、品牌名、分类名、属性值（`product_attribute_values.value`） | B |

⚠️ **品牌名/分类名进入索引会带来级联问题**：改品牌名需重算该品牌下全部商品。处理方式：
- `Brand::saved` / `Category::saved` → 若 `name` 变更 → dispatch `ReindexProductsByBrandOrCategory` Job（低频，队列执行）
- 开关 `search.index_taxonomy_names`（默认 true），关掉则品牌/分类名不进索引，级联问题消失

> **已拍板（2026-09-24）**：品牌名与分类名**进索引**，级联 Job 保留，开关默认开启。
> 理由：搜「小米」「沙发」这类词时，品牌/分类是最强的召回信号，召回收益大于低频改名的重算成本。

**真机实测（2026-09-24，PG 16 本地库）**，三条结论直接支撑上面的设计：

1. `to_tsvector('simple', '实木餐桌')` 产出的是**一个** lexeme `实木餐桌`，不是四个字。
   → PG 把连续汉字当整词，**不会**自动切分。这正是必须在 PHP 侧预先切成 bigram 的根本原因，
   否则「搜北欧找北欧实木沙发」永远召回不了。
2. 生成列接受 `setweight(to_tsvector('simple', ...))` —— 显式传 regconfig 的版本是 IMMUTABLE，
   建列通过；若写成单参 `to_tsvector(text)`（STABLE）PG 会直接拒绝。
3. 单字查询 `to_tsquery('simple','椅')` 命中 0 行（索引里没有单字 lexeme），
   前缀 `沙:*` 正常命中 —— §4.4 降级链第 0 条（单字走 LIKE）不是保守设计，是必需。

**各来源必须以空格拼接**：`顾家家居` + `实木沙发` 若直接相连会切出 `家实` 这种跨来源假词。
`SearchIndexWriter` 统一用空格 join，并用单测（TC-SEARCH-S1-02-003）钉住这条不变式。

### 4.3 索引同步

复用 `Product::booted()` 已有模式（与 `description_md → description` 派生并排）：

```php
protected static function booted(): void
{
    static::saving(function (self $product): void {
        // ... 既有 description 派生（保持不变）

        // 搜索索引派生：title/副标题/关键词任一变更，或首次写入
        if ($product->isDirty(['title', 'subtitle', 'keywords']) || $product->search_title === null) {
            $product->attributes['search_title'] = app(SearchIndexWriter::class)->buildTitleField($product);
            $product->attributes['search_body']  = app(SearchIndexWriter::class)->buildBodyField($product);
        }
    });
}
```

- `search_vector` 由 PG 生成列自动重算，**应用层不写 SQL**
- `SearchIndexWriter::buildBodyField()` 需要品牌名/分类名/SKU 编码 → 一次 `with('brand','category','skus','attributeValues')` 预加载，避免 N+1
- 属性变更（`ProductAttributeService` 保存后）→ 显式调用 `SearchIndexWriter::reindexProduct($productId)`
- 下架/删除：不改索引（`search_vector` 随行存在），查询侧统一 `where status = 1` 过滤即可，与现状一致

**全量重建**：`php artisan search:reindex`（`--chunk=500 --sleep=0 --ids=`），分块 `chunkById`，输出进度；每日 03:40 定时校准一次（与 `media:scan` 同风格）。

**实现落地（S1-03）**，三条来源各有各的同步点，因为它们的写入时机不同：

| 来源 | 同步点 | 理由 |
|------|--------|------|
| `title`/`subtitle`/`keywords`/`brand_id`/`category_id` | `Product::saving` 钩子 | 与主行同一次写入，顺带派生最自然 |
| SKU 编码、参数值 | `Admin\ProductController` 事务末尾 `reindex($product)` | 子表写在主行**之后**，`saving` 时它们还没落库 |
| 品牌名 / 分类名 | `Brand::saved` / `Category::saved` → `ReindexProductsByBrandOrCategory` | 1:N 影响面，必须异步 |

⚠️ **三个已踩实的坑，改动相关代码前必读**：

1. **`wasRecentlyCreated` 不能在钩子里当「是否新建」用** —— 它只在 `performInsert()` 里置 `true`，
   **之后永不重置**。用 `wasRecentlyCreated &&` 做守卫会让同一实例上的后续改名被永久误判成新建，
   级联 Job 再也不会派发（S1-03 实测踩到，5 条用例集体挂掉）。
2. **改外键后必须 `unsetRelation()`** —— `brand_id` 换了，但 `$product->brand` 还是旧缓存，
   不丢掉就会把**旧品牌的名字**写进新索引。
3. **`reindex()` 一律从库里重取** —— 调用方手上的 `$product->skus` 多半是 SKU 写入前的旧集合，
   直接用会算出缺 SKU 编码的索引。

无关保存（改价格/改状态/改库存）不触发重算，靠 `isDirty([...])` + 「存量行 `search_title === null` 时补算」两条判定
（见 `Product::shouldRebuildSearchFields()`）。

### 4.4 查询构造与降级链

命中规则（依次尝试，任一命中即返回）：

| 步 | 规则 | 说明 |
|----|------|------|
| 0 | 查询串分词后 token 数 = 1 且是单字中文 | 直接走 `FallbackLikeEngine`（bigram 对单字无意义） |
| 1 | AND：`to_tsquery('simple', '北欧 & 欧沙 & 沙发')` | 词组连续语义，精确率优先 |
| 2 | 零结果 → OR：`to_tsquery('simple', '北欧 \| 沙发')` | 标记 `relaxed=true`，前端提示「已为你放宽匹配」 |
| 3 | 仍零 → 同义词替换后重跑步骤 1（若启用 `search.synonyms_enabled`） | 「手机壳」=「保护套」 |
| 4 | 仍零 → 返回空 + `recommendations`（同分类热销 8 条 + 首页推荐 4 条） | 不白屏 |

前缀匹配（联想场景）：最后一个 token 加 `:*`，如输入 `iphon` → `to_tsquery('simple', 'iphon':*)`。

排序（PG 引擎内）：

```sql
ORDER BY ts_rank_cd(search_vector, query, 32) DESC, sales_count DESC, id DESC
```

`ts_rank_cd` 的 `32` = 归一化（cover density + 文档长度归一），比 `ts_rank` 更适合短查询。

⚠️ **token 必须引号转义**：`to_tsquery` 对 `& | ! ( ) : *` 敏感，PHP 侧统一包成 `'<token>'` 单引号字面量，并对内部单引号转义，防语法错误与注入。

### 4.5 引擎接口

```php
namespace App\Support\Search;

interface ProductSearchEngine
{
    /** 引擎在当前环境是否可用（PG / 扩展 / 第三方连通性） */
    public function isAvailable(): bool;

    /** 引擎标识，用于缓存 key 与响应诊断 */
    public function name(): string;

    /** 检索：返回按相关度降序的命中（仅 id + 分数），不做筛选与分页 */
    public function search(SearchCriteria $criteria): SearchResult;

    /** 联想候选 */
    public function suggest(string $keyword, int $limit): array;

    /** 单商品重建索引 */
    public function reindex(int $productId): void;

    /** 全量重建，返回处理条数 */
    public function reindexAll(int $chunk = 500, ?\Closure $progress = null): int;

    /** 从索引移除 */
    public function remove(int $productId): void;
}
```

DTO：

```php
final class SearchCriteria   // keyword / categoryId / brandId / attributeValues / minPrice / maxPrice / sort / page / pageSize
final class SearchResult     // ids: int[], scores: array<int,float>, total: int, relaxed: bool, engine: string
```

绑定（`AppServiceProvider::register()`，与物流渠道并排）：

```php
$this->app->bind(ProductSearchEngine::class, function () {
    $engine = match (config('search.engine')) {
        'meilisearch'  => new MeilisearchEngine(),
        'elasticsearch'=> new ElasticsearchEngine(),
        'off'          => new FallbackLikeEngine(),
        default        => new PostgresFtsEngine(),
    };
    return $engine->isAvailable() ? $engine : new FallbackLikeEngine();
});
```

`AppServiceProvider::boot()` 追加 `applySearchEngineOverride()`（读 `system_configs.search.engine`，与 `applyShippingChannelOverride()` 逐行对称）。

**实现落地（S1-05）**：

```php
$this->app->bind(ProductSearchEngine::class, function () {
    $engine = match (config('services.search.engine')) {
        'off', 'like' => $this->app->make(FallbackLikeEngine::class),
        default       => $this->app->make(PostgresFtsEngine::class),
    };

    return $engine->isAvailable() ? $engine : $this->app->make(FallbackLikeEngine::class);
});
```

三处与契约稿的偏离：

1. 绑定读 `services.search.engine`（不是 `config('search.engine')`）—— 与 `services.shipping.channel`
   保持一致，且 `config/services.php` 里已有 `search` 段。
2. **阶段二未实现的引擎名（meilisearch / elasticsearch）不做早退**，一律落进 `default`。
   配置写错时降级到 PG/LIKE，而不是让搜索整体 500 —— 引擎选型不该是可用性单点。
3. `off` 在搜索这里是**有意义的取值**（强制 LIKE），不像 `shipping.channel=off` 等价于清空；
   所以 `applySearchEngineOverride()` 原样写入 config，不转成 `null`。

`FallbackLikeEngine` 的三条边界（与 PG 引擎刻意不对齐）：

| 项 | 降级引擎 | 说明 |
|----|----------|------|
| 匹配字段 | **仅 `title` / `subtitle`** | 与改造前 `/products?keyword=` 逐字同语义。不查 `keywords`/品牌/分类名——那些是 PG 索引域的增量，加进来会静默把既有接口的召回变成超集 |
| 分词后无 token | **退回整串** | 纯英文停用词（`the and`）被分词器吃光时若返回空，就是把「有结果」变成「零结果」——那不是降级，是劣化 |
| 通配符 | **必须 `ESCAPE '\'` 转义** | `%` 不转义会命中全表（且退化为全表扫描）；`_`、`\` 同理 |

评分是自定义的覆盖率分：`(标题命中数 × 1.0 + 仅副标题命中数 × 0.4) / token 总数`，
量纲统一在 0~1，保证「标题全命中」永远排在「只在副标题沾上一个词」之前。

联想（`SearchSuggester`）从 `PostgresFtsEngine` 抽出共用 —— 联想是纯数据库前缀查询，
与引擎无关，两个引擎必须给出相同结果，否则切引擎会连带换掉联想行为。

**实现落地（S1-04）**，与上面契约的两处偏离，都是实现时发现的更优/更必要写法：

1. 绑定读的是 `services.search.engine`（不是 `config('search.engine')`）—— 与 `services.shipping.channel`
   保持一致，且 `config/services.php` 里已有 `search` 段（`index_taxonomy_names`）。
2. 引擎内**唯一的过滤条件是 `status = 1`**，命中总数用 `count(*) OVER ()` 一次查询取出
   （窗口函数在 LIMIT 之前计算，不必为 total 再跑一遍全表 count）。

⚠️ **`SearchResult::total` 是「引擎命中数」，未经分类/品牌/价格/属性筛选**。
最终分页的 total 必须由 Service 在 Eloquent 侧重算 —— 引擎不知道筛选条件（约束 2），
调用处若直接拿它当分页总数，就会出现「total=86 但翻到第 3 页就空了」的经典分页错位。

**真机实测（2026-09-24，PG 16 本地库 44 条商品）**：

| 查询 | 结果 |
|------|------|
| `保温` | 命中「不锈钢保温杯」score=0.5833 |
| `保温杯` | 同上，score=0.5992（更长的连续匹配得分更高） |
| `保温 耳机` | AND 零结果 → **OR 放宽救回 2 条**，`relaxed=true` ✅ |
| `保温 & 耳机` | 同上（`&` 被分词器当分隔符吃掉，不会成为语法字符） |
| `椅`（单字） | 零命中 ✅ 印证降级链第 0 步必须走 LIKE 引擎 |

**引擎不可用时 `search()` 抛 `RuntimeException` 而非返回空** —— 绑定层保证业务侧拿到的引擎一定可用，
走到异常说明是调用方 bug；静默返回空会表现为「搜什么都搜不到」，是最难排查的故障形态。

### 4.6 缓存

- key：`search:v{index_version}:{engine}:{md5(criteria_json)}`
- 只缓存 **id 列表 + total**（不缓存商品行，避免价格/库存变更不一致）
- TTL：`search.cache_ttl`（默认 60s）；热门词（`hit_count >= 50`）TTL 300s
- `index_version` 存 `system_configs.search.index_version`（整数），切引擎或全量重建后 +1，实现低成本批量失效（database cache store 不支持 tag）

**实现落地（S1-06）**，与上面两处偏离：

1. **key 只有 `引擎名 + 关键词`，不含分页与筛选**（不是 `md5(criteria_json)`）。
   筛选不下推到引擎（约束 2），换筛选条件引擎结果不变；引擎一次最多给 MAX_HITS 条，
   翻页只是换切片。带上分页会让每一页都重新算一遍相关度，缓存等于没做。
2. **缓存数组而不是 `SearchResult` 对象**（`toArray()` / `fromArray()`）。
   ⚠️ Laravel 12 的缓存 store 反序列化时带 `allowed_classes` 白名单（默认不允许任何类），
   缓存对象取回来一律是 `__PHP_Incomplete_Class`。**array store 不暴露这个问题**，
   只有 file / database / redis 会 —— 也就是说本地测试全绿、上线才炸，必须写进代码注释。

### 4.7 词频与热搜

迁移 `000121_create_search_keywords_table`：

```
search_keywords: id, keyword(unique), hit_count, result_count, last_hit_at, status, timestamps
  索引: (status, hit_count desc), (last_hit_at)
```

- 每次搜索（有结果）→ `UpdateSearchKeyword` Job（`afterCommit`）自增，避免拖慢响应
- 节流：同一 IP + 同一词 60s 内只计一次（Cache 计数）
- 联想来源优先级：① `search_keywords` 前缀命中 ② 商品 `title` 前缀命中 ③ 分类名 ④ 品牌名
- 热搜：`GET /search/hot`，缓存 300s

**实现落地（S1-06）**：

- 迁移 `000121` 已建表（本次一并落地，因为投递没有表就是空谈）
- ⚠️ **不用 `DB::afterCommit`**：项目测试跑在事务里，`afterCommit` 的回调永不执行，
  会表现为「词频功能在本地永远不生效」这种极难定位的问题。改成普通 `dispatch()`
- 节流用 `Cache::add()`（不存在才写，天然原子），比 get+put 少一个竞态窗口
- **只记有结果的搜索**：零结果的词不该进热搜榜，否则「搜不到」反而把脏词顶上去
- 词先过 `SearchTokenizer::normalize()`，避免「沙发　A」与「沙发 A」各算一半热度
- `result_count` 记**最近一次**结果数，用于后台发现「有热度但零结果」的词 —— 那是最该配同义词或补商品的信号

### 4.8 同义词（可选，S1-07）

```
search_synonyms: id, from_word, to_words(jsonb), status, timestamps
```
查询前按 `from_word → to_words` 展开成 OR 组。后台维护（权限 `search.manage`）。

**状态**：S1-07 未做（设计里标为可选）。降级链第 3 步（同义词展开）因此暂缺，
目前是「AND 零结果 → 直接 OR 放宽」。补做时落点在 `ProductSearchService::searchByKeyword()`：
零结果后按 `from_word → to_words` 展开再查一次，命中则 `relaxed=true`。
建表 `000122_create_search_synonyms_table`，权限 `search.manage` 随 S1-09 一起给。

### 4.9 服务层：`ProductSearchService`（S1-06）

引擎之上、控制器之下的一层，把四件事收在一处：

| 职责 | 实现 |
|------|------|
| 缓存 | 只缓存引擎命中集（数组形态），见 §4.6 |
| 降级链 | 第 0 步（单字中文 → LIKE 引擎）、第 4 步（仍零结果 → 推荐位） |
| 重排 | 筛选与排序全在 Eloquent；`sort=relevance` 时在 PHP 侧按引擎顺序还原 |
| 词频 | 有结果的搜索异步记一笔，见 §4.7 |

两条容易写错的约定：

1. **分页 `total` 必须是筛选后的总数**。引擎给的 `SearchResult::total` 未经筛选，
   拿它分页就是「total=86 但翻到第 3 页就空了」。实现上靠「pluck 出筛选后的全部命中 id」
   一次拿到 —— 引擎命中上限 MAX_HITS，所以 pluck 不会失控，顺带省掉一遍 count。
2. **`whereIn` 不保证返回顺序**，相关度序必须在 PHP 侧按命中 id 的位置还原。

引擎选择走 `SearchEngineResolver`，而不是让 Service 直接注入某个引擎 ——
§5.1 约束 1 要求 Service 不得 import 引擎具体类，但降级链第 0 步又需要「指定要 LIKE 引擎」，
只靠容器绑定 `ProductSearchEngine` 表达不了这个语义。`AppServiceProvider` 对
`ProductSearchEngine` 的绑定也委托给同一个 resolver，避免两处判定漂移。

---

## 5. 阶段二：切换专用搜索引擎

### 5.1 前置：阶段一必须守住的约束

| # | 约束 |
|---|------|
| 1 | Controller / Service **不得 import 任何引擎具体类**，只依赖 `ProductSearchEngine` 接口与 DTO |
| 2 | 筛选（分类/品牌/价格/属性）**只允许写在 Eloquent**，不得写进引擎查询 |
| 3 | 引擎 `search()` 只返回 `ids + scores`，排序语义统一为「相关度降序」 |
| 4 | 所有引擎对同一数据集必须返回**相同 id 集合**（顺序可不同）→ 契约测试保证 |
| 5 | 缓存 key 必须含 `engine`，避免切引擎后读到旧引擎缓存 |

### 5.2 Meilisearch 落地步骤

1. `docker-compose.yml` 加 `meilisearch: getmeili/meilisearch:v1.11`（单二进制，与既有 redis 服务并列）
2. `config/search.php` 增 `meilisearch` 段（host / key / index / sync_batch_size），key 走 `.env`，不入库（与 `shipping.key` 同原则）
3. `MeilisearchEngine` 实现接口：
   - `reindex()`：文档 = `['id' => public_id?]` ⚠️ 用**内部 int id** 作主键（引擎只回 id，Controller 用 `whereIn('id')`，与 public_id 无关，避免混淆）
   - 字段：`title, subtitle, keywords, brand_name, category_name, attribute_values[], price, sales_count, status, image`
   - `search()`：`filter` 只放 `status = 1`（其余筛选交给 SQL），`limit` 取 `page * pageSize` 上限 1000（防深翻页）
   - `suggest()`：直接用 Meilisearch 的搜索前缀能力
4. `search:reindex --engine=meilisearch` 全量灌库
5. 双跑比对：`search:compare --engine=meilisearch --sample=200` 输出与 PG 引擎的 id 集合差异率
6. 后台切 `search.engine = meilisearch` → 观察 24h → 保留 `postgres` 可一键回切

### 5.3 Elasticsearch（备选）

仅预留 `ElasticsearchEngine` 骨架 + `config/search.php` 字段；ES 需要 JVM 与更多运维成本，**单商家万级商品不划算**，建议除非已有 ES 集群否则不上。

---

## 6. 接口设计

### 前台（公开，无需登录）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/search` | 搜索主入口。参数同 `/products`（keyword / category_id / brand_id / min_price / max_price / attribute_values[] / sort / page / page_size），新增 `sort=relevance`（有 keyword 时默认） |
| GET | `/search/suggest` | 联想。`?keyword=&limit=10`，返回 `string[]` |
| GET | `/search/hot` | 热搜榜。`?limit=10` |

`/search` 响应（在现有 `paginated()` 基础上扩展 `meta`）：

```json
{
  "list": [ ...ProductResource ],
  "pagination": { ...PublicPagination },
  "meta": {
    "keyword": "北欧沙发",
    "relaxed": true,
    "engine": "postgres",
    "related_categories": [{ "id": "xxx", "name": "沙发", "count": 12 }],
    "recommendations": [ ...ProductResource ]
  }
}
```

- `related_categories` 仅在结果非空时返回（按命中结果的分类聚合，供前端二次筛选）
- `recommendations` 仅在结果为空时返回
- ⚠️ `relaxed` / `engine` 属于诊断信息，生产可通过 `search.expose_debug` 关闭（默认关闭，避免暴露内部实现）

### 兼容性

`GET /products?keyword=` **保留且行为不变**（内部改走同一 `ProductSearchService`，降级路径下与现状逐字一致），保护既有前端与测试。`/search` 是新增的富入口。

**实现落地（S1-07）**，三条必须显式守住的兼容边界：

1. **未传 `sort` 时 `/products` 强制回落 `newest`**（旧实现是 `sort desc, id desc`）。
   `SearchCriteria` 在有关键词时默认 `relevance`，直接复用会让老接口悄悄换成相关度排序
   —— 那是 `GET /search` 的行为，`/products` 不能跟着变。由 `SearchController::criteriaFrom($request, true)` 区分。
2. **`/products` 不吐 `meta`**。它是兼容入口，加了 `meta` 会让既有前端的响应解析多出未知字段。
3. **分页结构手搓但必须与 `ApiResponse::paginated()` 同形**（`page/page_size/total/total_pages/has_more`），
   且 `total` 仍受 SEC-04 管控（公开接口置 `null` 而非 `0`）。统一走 `SearchPage::pagination()`，避免两处漂移。

### 后台（需权限）

| 方法 | 路径 | 权限 | 说明 |
|------|------|------|------|
| GET | `/admin/search/config` | `search.manage` | 当前引擎、可用引擎列表、开关 |
| PUT | `/admin/search/config` | `search.manage` | 切换引擎与开关，写库后即时生效 + 操作日志 |
| GET | `/admin/search/keywords` | `search.manage` | 热搜词列表（可置顶/屏蔽） |
| POST | `/admin/search/reindex` | `search.manage` | 触发全量重建（异步 Job + 进度查询） |

权限码 `search.manage` 需同时改 `RolePermissionSeeder::PERMISSIONS` 与幂等迁移（项目约定）。
`ConfigGroup::PREFIX_LABELS` 需登记 `'search' => '搜索与推荐'`，否则落进「其它设置」。

---

## 7. 前端改造（web）

| 位置 | 改动 |
|------|------|
| `ShopHeader.vue` | 搜索框加防抖（200ms）联想下拉：热搜榜 + 联想词 + 搜索历史（localStorage，最多 10 条） |
| `BrowseView.vue` | keyword 模式改调 `/search`；结果头部展示「找到 N 个商品」+ 放宽匹配提示 + 相关分类快捷筛选；空结果展示推荐位 |
| `api/search.ts`（新） | `searchProducts` / `searchSuggest` / `searchHot` |
| `admin` | 「系统 → 搜索配置」页（引擎切换卡片，镜像现有「当前查询渠道 / 当前面单渠道」卡）+ 热搜词管理 |

⚠️ 与既有约定一致：`/search` 路由已存在且 `BrowseView` 复用，改造只在数据层与头部信息区，不动 `FilterRows` 组件。

---

## 8. 迁移清单（从 000119 起）

| 编号 | 内容 | 驱动差异 |
|------|------|----------|
| 000119 | `products` 增 `search_title` / `search_body`；PG 增生成列 `search_vector` + GIN 索引 | PG 额外建生成列与索引 |
| 000120 | 存量回填（分块 `chunkById`，幂等，可重复执行） | 通用 |
| 000121 | `create_search_keywords_table` | 通用 |
| 000122 | `create_search_synonyms_table` | 通用 |
| 000123 | `sync_search_permissions`（`search.manage`） | 通用 |
| 000124 | `seed_search_configs`（`search.engine=''`、`search.fallback_enabled=true`、`search.cache_ttl=60`、`search.index_version=1`） | 通用 |

---

## 9. 测试策略（关键：SQLite 下如何测）

三层，避免「PG 才能测」导致主流程裸奔：

| 层 | 用例 | 运行环境 |
|----|------|----------|
| 单元 | `SearchTokenizerTest`（中文 bigram / 英文 / 数字 / 混合 / 标点 / 停用词 / 长度截断） | SQLite |
| 单元 | `SearchCriteriaTest`（参数解析、缓存 key 稳定性、page_size 边界） | SQLite |
| 契约 | `FallbackLikeEngineTest`（21 条：与现状 LIKE 逐条一致 + 通配符转义 + 放宽 + 评分） | SQLite |
| 契约 | `SearchEngineBindingTest`（9 条：降级链、配置覆写、未实现引擎名不炸、分组登记） | SQLite |
| 契约 | `SearchApiTest`（18 条：控制器契约——public_id 出口、SEC-04、public_id 入参、page_size 上限、翻页、诊断开关、联想/热搜、`/products` 兼容 3 条） | SQLite |
| 契约 | `ProductSavingIndexTest`（改 title 后 `search_title` 同步更新；改 description 不触发重算） | SQLite |
| 特性 | `PostgresFtsEngineTest`（AND/OR/权重/前缀/零结果降级） | **仅 PG**，非 pgsql 时 `markTestSkipped` |
| 特性 | `SearchEngineContractTest`（三个引擎同数据集返回相同 id 集合） | 仅 PG（阶段二启用） |

约定：
- 测试文件内**全局函数必须唯一**（既有踩坑，全量跑会 `Cannot redeclare`）
- 新增 `tests/Feature/...` 每个文件加 `uses(RefreshDatabase::class)`
- PG 特性测试走既有 PG 兼容性回归通道（`DB_CONNECTION=pgsql` 单独跑），不进 SQLite 全量

---

## 10. 任务拆分与工作量

### 阶段一（S1）

| 编号 | 任务 | 人天 |
|------|------|------|
| S1-01 | ✅ `SearchTokenizer` + 单元测试（20 passed / 37 assertions） | 0.5 |
| S1-02 | ✅ 迁移 000119/000120（生成列 + GIN + 存量回填）+ `SearchIndexWriter` | 0.5 |
| S1-03 | ✅ `Product::saving` 派生 + 品牌/分类级联 Job（`SearchIndexWriter` 在 S1-02 已落地） | 1 |
| S1-04 | ✅ `ProductSearchEngine` 接口 + `SearchCriteria`/`SearchResult` DTO + `PostgresFtsEngine` | 1.5 |
| S1-05 | `FallbackLikeEngine` + 引擎绑定 + `applySearchEngineOverride` | 0.5 | ✅ 完成 |
| S1-06 | `ProductSearchService`（缓存、降级链、重排、词频投递）+ 000121 | 1 | ✅ 完成 |
| S1-07 | 控制器与路由 `/search`、`/search/suggest`、`/search/hot` + `/products` 改走服务层 | 1 | ✅ 完成 |
| S1-08 | ✅ `search:reindex`（`--chunk/--sleep/--ids/--no-bump`，幂等）+ 每日 03:40 调度 + 后台配置 4 接口（`GET/PUT /admin/search/config`、`GET /admin/search/keywords`、`POST /admin/search/reindex`）；真 PG 端到端已验证 | 0.5 |
| S1-09 | 权限码 `search.manage`（迁移 000122 + seeder）✅、`ConfigGroup` 登记 `search` 分组 ✅；后台配置页 ⏳ 待做 | 0.5 |
| S1-10 | 前端：联想下拉、搜索页头部信息、空结果推荐 | 1.5 |
| S1-11 | 测试（单元 + 契约 + PG 特性）+ 万级商品性能验证 | 1 |
| | **后端小计** | **~7** |
| | **前端小计** | **~2.5** |

### 阶段二（S2）

| 编号 | 任务 | 人天 |
|------|------|------|
| S2-01 | Meilisearch 服务部署 + `config/search.php` | 0.5 |
| S2-02 | `MeilisearchEngine` + 索引映射 + 增量同步 | 1.5 |
| S2-03 | `search:reindex --engine` / `search:compare` 双跑比对工具 | 0.5 |
| S2-04 | 契约测试 + 灰度切换与回切演练 | 0.5 |
| | **小计** | **~3**（不含 Meilisearch 运维） |

---

## 11. 验收标准

> **S1-08 验证证据**（2026-09-24，PostgreSQL 17.5 / 开发库 44 商品）：
> `products.search_vector` 为 `GENERATED ALWAYS`（`setweight(A, search_title) || setweight(B, search_body)`），
> GIN 索引 `products_search_vector_gin` 在；索引覆盖 44/44；服务层检索 `engine=postgres`、单字「椅」自动落 `engine=like`。
> 测试：`tests/Feature/AdminSearchApiTest.php`（9）+ `tests/Feature/SearchReindexCommandTest.php`（7）。

**阶段一**
- [ ] 万级商品下搜索 P95 < 200ms（PG 本地，cold cache）—— 当前只有 44 条商品，实测 6~60ms，**万级待压测**
- [x] 中文分词召回（真 PG 实证）：三字词按 bigram 切分后命中 —— 搜「保温杯」命中「不锈钢保温杯」；
      无命中时 `related_categories` 0 个、推荐位 12 条（空结果兜底生效）
- [x] 相关度排序生效（真 PG 实证）：搜「收纳」时 `ts_rank` 标题命中（权重 A）0.6687 > 仅分类名命中（权重 B）0.2432
- [~] 搜分类名 / SKU 编码已实证（「收纳」命中分类名；`CS-005-01` 命中「运动跑步鞋」）；
      ⚠️ 品牌名未覆盖 —— 开发库商品 `brand_id` 全为空，待有品牌数据后补验
- [ ] 切 `search.engine=off` 后行为与改造前**逐条一致**（回归网）
- [ ] SQLite 全量用例无回归（基线 1583 passed）；PG 特性用例通过
- [ ] 后台改商品标题后，无需重建即可搜到新标题；改品牌名后 1 分钟内同步
- [x] `search:reindex` 幂等：单测钉住「第二次写回 0 条、索引逐字节一致」，另覆盖 `--ids` / `--no-bump` / 软删商品

**阶段二**
- [ ] 双引擎 id 集合差异率 < 1%（200 词样本）
- [ ] Meilisearch 宕机时自动回落 PG 引擎，页面不白屏（`isAvailable()` 健康检查）
- [ ] 一键回切 `postgres` 后 60s 内恢复

---

## 12. 风险与应对

| 风险 | 影响 | 应对 |
|------|------|------|
| 生成列在部分托管 PG 上受限（如某些只读副本/老版本） | 索引不生效 | 迁移里探测 PG 版本 < 12 → 改触发器方案（§10.2 备案）；探测失败则不建生成列，自动走降级引擎 |
| bigram 让 tsvector 膨胀 | GIN 索引过大 | `description` 不进索引；`search_body` 截断 4000；上线前用真实商品量跑一次 `pg_total_relation_size` 评估 |
| 单字中文查询噪声 | 结果不相关 | 单字直接走 LIKE 降级（§4.4 第 0 步） |
| `to_tsquery` 特殊字符导致 500 | 搜索崩溃 | token 统一单引号字面量 + 转义；引擎内 try/catch → 回落降级引擎并记日志 |
| 深翻页性能（page > 50） | 慢查询 | 引擎侧 limit 上限 1000；`paginate` 保持既有行为，超出返回空列表并提示 |
| 索引与数据不一致（生成列被绕过） | 搜不到 | 每日 03:40 `search:reindex` 校准；后台提供手动重建按钮 |
| 阶段二引入新运维组件 | 部署复杂度 | 与既有决策一致：单二进制 Docker Compose，索引可重建，随时回切 PG |

---

## 13. 备案代码

### 13.1 PG < 12 的触发器方案

```sql
CREATE OR REPLACE FUNCTION products_search_vector_refresh() RETURNS trigger AS $$
BEGIN
    NEW.search_vector :=
        setweight(to_tsvector('simple', coalesce(NEW.search_title, '')), 'A') ||
        setweight(to_tsvector('simple', coalesce(NEW.search_body,  '')), 'B');
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER products_search_vector_tg
    BEFORE INSERT OR UPDATE OF search_title, search_body ON products
    FOR EACH ROW EXECUTE FUNCTION products_search_vector_refresh();
```

### 13.2 查询构造（PHP 侧）

```php
// token 转 tsquery 字面量（AND）
$parts = array_map(fn ($t) => "'".str_replace("'", "''", $t)."'", $tokens);
$tsquery = implode(' & ', $parts);

// 前缀模式（联想）
$parts[count($parts) - 1] .= ':*';

// 执行
$rows = DB::select(<<<'SQL'
    SELECT id, ts_rank_cd(search_vector, q, 32) AS score
    FROM products, to_tsquery('simple', ?) q
    WHERE status = 1 AND search_vector @@ q
    ORDER BY score DESC, sales_count DESC, id DESC
    LIMIT ? OFFSET ?
SQL, [$tsquery, $limit, $offset]);
```

> 生产实现走 `$q->whereRaw('search_vector @@ to_tsquery(?, ?)', ['simple', $tsquery])` 以复用 Eloquent 的其它条件（`whereIn('id')` 不适用，此处引擎仅召回）。

---

**—— 站内搜索设计方案结束 ——**
