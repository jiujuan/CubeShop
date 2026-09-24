<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 同义词（站内搜索 S1-10 补做，设计 §4.8）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.4（降级链第 3 步）/ §4.8
 *
 * 一行 = 一条替换规则：`from_word` → `to_words`。检索零结果后由
 * `ProductSearchService::synonymResult()` 展开重查，命中则 `relaxed=true`。
 *
 * ⚠️ 入库即归一化：from_word 与 to_words 里的每个词都过
 * `SearchTokenizer::normalize()` + `mb_strtolower`（Controller 写入时做），
 * 匹配逻辑（子串 `mb_strpos`）依赖这个约定，库里混入未归一化的词会静默失配。
 *
 * ⚠️ 词表缓存 300 秒（键 `search:synonyms`），挂在本模型的 saved/deleted 事件上失效
 * —— 与 SystemConfig 的配置缓存同一套「写入侧闭环」体例，新增直写路径不必手动 flush。
 */
class SearchSynonym extends Model
{
    public const STATUS_ACTIVE = 1;

    public const STATUS_BLOCKED = 0;

    protected $fillable = [
        'from_word',
        'to_words',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    /**
     * ⚠️ 不能用默认 `'array'` cast：Laravel 的 asJson 走默认 json_encode，
     * 中文会被转义成 \uXXXX —— 后台列表「按 to 词 LIKE 筛选」在数据库侧就永远筛不到。
     * 这里显式 JSON_UNESCAPED_UNICODE 落库；读取侧 decode 成数组。
     */
    protected function toWords(): Attribute
    {
        return Attribute::make(
            get: fn ($value): array => $value === null ? [] : (array) json_decode((string) $value, true),
            set: fn ($value): string => json_encode(array_values((array) $value), JSON_UNESCAPED_UNICODE),
        );
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * 启用规则的 `from_word => to_words` 映射（缓存 300s）
     *
     * ⚠️ 读失败（表未建、迁移期中）静默回落空表：同义词是锦上添花的第 3 步，
     * 不该让搜索因为表没就绪而 500 —— 与 SearchConfig 的读库失败回落同一立场。
     *
     * @return array<string, list<string>>
     */
    public static function activeMap(): array
    {
        try {
            return Cache::remember('search:synonyms', 300, function (): array {
                $out = [];

                foreach (self::query()->active()->orderBy('from_word')->get() as $row) {
                    $words = array_values(array_filter(
                        array_map(strval(...), (array) $row->to_words),
                        fn (string $w): bool => $w !== '',
                    ));

                    if ($words !== []) {
                        $out[$row->from_word] = $words;
                    }
                }

                return $out;
            });
        } catch (Throwable) {
            return [];
        }
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('search:synonyms'));
        static::deleted(fn () => Cache::forget('search:synonyms'));
    }
}
