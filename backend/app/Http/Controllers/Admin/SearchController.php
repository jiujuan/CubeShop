<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SearchKeyword;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use App\Support\Search\SearchConfig;
use App\Support\Search\SearchEngineResolver;
use App\Support\Search\SearchIndexWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 站内搜索后台配置（V1.2 站内搜索 S1-08）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §6 后台部分
 *
 * 权限 `search.manage`（超管专属，与 media.manage 同体例 —— 切引擎会让全站检索行为整体变化）。
 *
 * ⚠️ 两条硬约定：
 * 1. **任何配置变更都要递增 `index_version`**：命中集缓存 key 含版本号，不递增就会
 *    继续用旧引擎 / 旧索引算出来的结果，表现为「后台改了但前台没变」。
 * 2. **改 `search.index_taxonomy_names` 后必须重建索引**：该开关决定品牌名/分类名是否
 *    进索引列，改完只 bump 版本号不重建，索引内容还是旧的。
 */
class SearchController extends Controller
{
    use ApiResponse;

    /**
     * `PUT /admin/search/config` 的 `engine` 合法取值
     *
     * ⚠️ 与 {@see SearchConfig::ENGINES} 的差别：这里**不含空串**。
     * 空串在 HTTP 层根本传不过来 —— 全局中间件 `ConvertEmptyStringsToNull` 会先把它变成 null，
     * 而 null 的语义是「不改动」。所以「清空、跟随 .env」必须显式传 `auto`。
     * 若把空串也列进来，前端传 `engine: ''` 时会得到一个「既不报错也不生效」的静默行为。
     */
    private const REQUEST_ENGINES = ['auto', 'postgres', 'like', 'off'];

    public function __construct(
        private readonly OperationLogService $operationLog,
        private readonly SearchConfig $config,
        private readonly SearchEngineResolver $engines,
    ) {
    }

    /**
     * 搜索配置详情 GET /admin/search/config
     *
     * 出参区分「配置值」与「实际生效的实现」：配了 postgres 但 PG 不可用时，
     * `engine=postgres` 而 `active_engine=like`，后台据此提示「当前处于降级状态」。
     */
    public function config(): JsonResponse
    {
        $current = $this->config->engine();
        $active = $this->engines->primary();

        return $this->success([
            'engine' => $current,
            'active_engine' => $active->name(),
            'degraded' => $active->name() !== 'postgres' && $current !== 'like' && $current !== 'off',
            'engines' => $this->engineOptions($current),
            'switches' => $this->config->switches(),
            'index_version' => $this->config->indexVersion(),
        ]);
    }

    /**
     * 更新搜索配置 PUT /admin/search/config
     *
     * body: {
     *   engine?: 'auto' | 'postgres' | 'like' | 'off'   // 省略（或 null）= 不改动；'auto' = 清空库值、跟随 .env
     *   switches?: { 'search.cache_ttl'?: string, ... } // 只认白名单内的键，其余静默忽略
     * }
     *
     * ⚠️ 两种变更都**必然递增 `index_version`**：缓存 key 含版本号，不递增就会出现
     * 「后台改了、前台照旧」—— 看起来像没保存成功，实则读到的是旧命中集。
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'engine' => ['nullable', 'string', Rule::in(self::REQUEST_ENGINES)],
            'switches' => ['nullable', 'array'],
        ]);

        $before = [
            'engine' => $this->config->engine(),
            'switches' => $this->config->switches(),
        ];

        $version = null;

        // ⚠️ `isset`（而非 array_key_exists）：缺省或 null 都是「不改动」。
        // 若用 array_key_exists，`{"engine": null}` 会被当成空串去清空引擎 —— 两者语义截然不同。
        if (isset($data['engine'])) {
            $version = $this->config->setEngine((string) $data['engine']);
        }

        if (! empty($data['switches'])) {
            $this->config->updateSwitches($data['switches']);
            $version ??= $this->config->bumpIndexVersion();
        }

        $version ??= $this->config->indexVersion();

        $this->operationLog->record(
            $request->user()?->id,
            'search',
            'config.update',
            'SystemConfig',
            null,
            [
                'before' => $before,
                'after' => [
                    'engine' => $this->config->engine(),
                    'switches' => $this->config->switches(),
                ],
                'index_version' => $version,
            ],
        );

        return $this->success([
            'engine' => $this->config->engine(),
            'switches' => $this->config->switches(),
            'index_version' => $version,
        ], '已保存');
    }

    /**
     * 热搜词列表 GET /admin/search/keywords
     *
     * `result_count = 0` 且 `hit_count` 高的词是运营最该关注的信号：
     * 有人搜、但搜不到 —— 要么配同义词，要么补商品。
     */
    public function keywords(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));

        // page_size 夹在 1~100：与后台其它列表同口径（page_size=100000 会直接把库拖垮）
        $pageSize = min(100, max(1, (int) $request->query('page_size', 20)));

        $paginator = SearchKeyword::query()
            ->when($keyword !== '', fn ($q) => $q->where('keyword', 'like', '%'.$keyword.'%'))
            // 热度倒序；同热度按词序稳定输出，翻页不会出现「同一行来回跳」
            ->orderByDesc('hit_count')
            ->orderBy('keyword')
            ->paginate($pageSize);

        return $this->paginated($paginator);
    }

    /**
     * 重建检索索引 POST /admin/search/reindex
     *
     * ⚠️ 与设计的偏差：设计写的是「异步 Job + 进度查询」，这里**同步执行并立即返回统计**。
     * 理由：单商家万级商品全量重算是秒级，为此引入 Job + 进度存储不划算；
     * 真到十万级再换异步不迟，接口形态（返回条数与版本号）可以保持不变。
     * 另有命令行兜底：`php artisan search:reindex`（每日 03:40 校准）。
     */
    public function reindex(Request $request): JsonResponse
    {
        $started = microtime(true);

        $updated = app(SearchIndexWriter::class)->reindexQuery(Product::query()->withTrashed());
        $version = $this->config->bumpIndexVersion();

        $elapsed = (int) round((microtime(true) - $started) * 1000);

        $this->operationLog->record(
            $request->user()?->id,
            'search',
            'reindex',
            'Product',
            null,
            ['updated' => $updated, 'index_version' => $version, 'elapsed_ms' => $elapsed],
        );

        return $this->success([
            'updated' => $updated,
            'index_version' => $version,
            'elapsed_ms' => $elapsed,
        ], '重建完成');
    }

    /**
     * 可选引擎清单
     *
     * 阶段二在此加 `meilisearch` / `elasticsearch`（同时改 `SearchEngineResolver` 与
     * `SearchConfig::ENGINES`），前端无需感知即可出现新选项。
     *
     * @return list<array{key: string, label: string, available: bool, current: bool}>
     */
    private function engineOptions(string $current): array
    {
        $options = [
            ['key' => '', 'label' => '自动（PG 可用则用，否则降级 LIKE）', 'available' => true],
            ['key' => 'postgres', 'label' => 'PostgreSQL 原生全文检索', 'available' => $this->engines->postgres()->isAvailable()],
            ['key' => 'like', 'label' => 'LIKE 降级引擎（等同改造前行为）', 'available' => $this->engines->like()->isAvailable()],
        ];

        $out = [];

        foreach ($options as $option) {
            $out[] = [
                'key' => $option['key'],
                'label' => $option['label'],
                'available' => $option['available'],
                // `off` 是 `like` 的别名：配置里可能是 off，选项里只有 like
                'current' => $option['key'] === $current
                    || ($option['key'] === '' && ($current === '' || $current === 'auto'))
                    || ($option['key'] === 'like' && in_array($current, ['like', 'off'], true)),
            ];
        }

        return $out;
    }
}
