<?php

namespace App\Support\Search;

use App\Services\Common\ConfigService;
use Throwable;

/**
 * 站内搜索配置（站内搜索 S1-08）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.6 / §6
 *
 * 存在的理由：`search.*` 配置有**两个来源** —— `.env`（`config/services.php`）与
 * `system_configs`（后台可改）。分散在各处用 `config()` 读，后台就永远改不动；
 * 分散在各处用 `ConfigService` 读，安装期与迁移期又会因表不存在而 500。
 * 于是收敛到这里：**库里有值用库值，否则回落 env；读库失败静默回落**。
 *
 * 三条约定：
 * 1. 键名一律 `search.xxx`，与 `system_configs.config_key` 逐字一致（`ConfigGroup` 已登记 `search` 分组）；
 * 2. **切引擎必须递增 `index_version`** —— 缓存 key 含引擎名与版本号，不递增会读到旧引擎的命中集；
 * 3. 后台只能改 {@see self::SWITCHES} 白名单内的键，其余一律拒绝（写库前校验，不靠前端约束）。
 */
final class SearchConfig
{
    /**
     * 引擎取值白名单
     *
     * `''` / `'auto'`：自动（PG 可用则用，不可用降级 LIKE）
     * `'postgres'`：强制 PG 全文检索（不可用时仍会降级，绑定层保证）
     * `'like'` / `'off'`：强制 LIKE 降级
     * 阶段二新增 `meilisearch` / `elasticsearch` 时改三处：本常量、`SearchEngineResolver::primary()`、
     * 后台接口入参白名单 `SearchController::REQUEST_ENGINES`（后者不含空串，见那里的说明）。
     */
    public const ENGINES = ['', 'auto', 'postgres', 'like', 'off'];

    /**
     * 后台可维护的开关：`键 => 默认值`
     *
     * ⚠️ 只放**运维真的会改**的项。index_taxonomy_names 影响索引内容，
     * 改动后必须跑一次 `search:reindex`（后台页会提示）；synonyms_enabled 只影响
     * 查询侧展开逻辑，不动索引内容，故改完即生效、无需重建。
     */
    public const SWITCHES = [
        'search.index_taxonomy_names' => '1',
        'search.cache_ttl' => '60',
        'search.expose_debug' => '0',
        'search.synonyms_enabled' => '1',
    ];

    public function __construct(private readonly ConfigService $config)
    {
    }

    /**
     * 生效的引擎取值（库 → env → 自动）
     *
     * 非法值一律当「自动」处理：配错只降级，不因配置让搜索整体不可用。
     */
    public function engine(): string
    {
        $value = strtolower(trim((string) $this->get('search.engine', '')));

        return in_array($value, self::ENGINES, true) ? $value : '';
    }

    /**
     * 切换引擎：写库 + 递增 index_version
     *
     * @return int 递增后的 index_version
     */
    public function setEngine(string $engine): int
    {
        $engine = strtolower(trim($engine));

        if (! in_array($engine, self::ENGINES, true)) {
            $engine = '';
        }

        // 空串写进库没意义（读不到就回落 env），删掉它才是「跟随 env」
        if ($engine === '' || $engine === 'auto') {
            $this->forget('search.engine');
        } else {
            $this->set('search.engine', $engine);
        }

        return $this->bumpIndexVersion();
    }

    /**
     * @return array<string, string> 后台可维护开关的当前值
     */
    public function switches(): array
    {
        $out = [];

        foreach (self::SWITCHES as $key => $default) {
            $out[$key] = (string) ($this->get($key, $default) ?? $default);
        }

        return $out;
    }

    /**
     * 更新开关（白名单外直接忽略）
     *
     * @param  array<string, mixed>  $values
     */
    public function updateSwitches(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::SWITCHES)) {
                continue;
            }

            $this->set($key, $this->normalizeSwitch($value));
        }
    }

    /** 索引版本号（缓存 key 的一部分，批量失效用） */
    public function indexVersion(): int
    {
        return max(1, (int) ($this->get('search.index_version', 1) ?: 1));
    }

    /**
     * 递增索引版本号
     *
     * 切引擎、全量重建后调用：旧 key 自然过期，不必逐个 forget
     * （database/array cache store 不支持 tag，这是唯一低成本的批量失效手段）。
     *
     * @return int 递增后的版本号
     */
    public function bumpIndexVersion(): int
    {
        $next = $this->indexVersion() + 1;

        $this->set('search.index_version', (string) $next);

        return $next;
    }

    /**
     * 读配置：库 → env → 默认值
     *
     * ⚠️ 读库失败（表未建、数据库不可用、迁移期中）一律静默回落 ——
     * 配置没就绪不该让搜索 500，那是「搜什么都搜不到」级别的事故。
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = $this->config->get($key);
        } catch (Throwable) {
            $value = null;
        }

        if ($value !== null && $value !== '') {
            return $value;
        }

        return config('services.'.$key, $default);
    }

    public function set(string $key, string $value): void
    {
        try {
            $this->config->set($key, $value);
        } catch (Throwable) {
            // 写不进去（表未建）时不阻断主流程：内存态配置仍能工作
        }
    }

    private function forget(string $key): void
    {
        try {
            \App\Models\SystemConfig::query()->where('config_key', $key)->delete();
            $this->config->flush();
        } catch (Throwable) {
            // 同上：删不掉也不阻断
        }
    }

    /** 开关值归一成 '1' / '0' 或原样字符串（数值项保留数字） */
    private function normalizeSwitch(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = trim((string) $value);

        if (in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true)) {
            return '1';
        }

        if (in_array(strtolower($value), ['0', 'false', 'off', 'no', ''], true)) {
            return '0';
        }

        return $value;
    }
}
