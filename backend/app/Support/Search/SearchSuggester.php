<?php

namespace App\Support\Search;

use App\Models\Product;
use App\Models\SearchKeyword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 联想候选（站内搜索 S1-05）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.7
 *
 * 从 `PostgresFtsEngine` 里抽出来，是因为联想**与引擎无关**：它是纯数据库前缀查询，
 * PG 引擎与 LIKE 降级引擎必须给出完全相同的结果，否则一切引擎就换一次联想行为。
 *
 * 优先级：
 * ① `search_keywords` 前缀命中（S1-07 补齐；表未迁移时用 `Schema::hasTable` 守卫跳过）
 * ② 商品标题（按销量降序）
 * ③ 分类名
 * ④ 品牌名
 *
 * 去重保序，且**不补空位**：上游（标题）不足时由下游（分类/品牌）补齐，
 * 但同一来源内部不重复，避免「沙发」被标题和分类各贡献一次。
 */
final class SearchSuggester
{
    public function __construct(private readonly SearchTokenizer $tokenizer)
    {
    }

    /**
     * @return list<string>
     */
    public function suggest(string $keyword, int $limit): array
    {
        $keyword = trim($this->tokenizer->normalize($keyword));

        if ($keyword === '' || $limit <= 0) {
            return [];
        }

        $out = [];
        $like = $this->escapeLike($keyword).'%';

        // ① 搜过的词：热度降序。表未迁移（安装期/回滚态）时静默跳过，不能让联想 500
        if (Schema::hasTable('search_keywords')) {
            foreach (SearchKeyword::query()
                ->active()
                ->whereRaw("keyword LIKE ? ESCAPE '\\'", [$like])
                ->orderByDesc('hit_count')
                ->limit($limit)
                ->pluck('keyword')
                ->all() as $word) {
                $out[] = (string) $word;
            }
        }

        if (count($out) < $limit) {
            foreach (Product::query()->where('status', 1)
                ->whereRaw("title LIKE ? ESCAPE '\\'", [$like])
                ->orderByDesc('sales_count')
                ->limit($limit - count($out))
                ->pluck('title')
                ->all() as $title) {
                $out[] = (string) $title;
            }
        }

        if (count($out) < $limit) {
            foreach (DB::table('categories')
                ->whereRaw("name LIKE ? ESCAPE '\\'", [$like])
                ->orderBy('name')
                ->limit($limit - count($out))
                ->pluck('name')
                ->all() as $name) {
                $out[] = (string) $name;
            }
        }

        if (count($out) < $limit) {
            foreach (DB::table('brands')
                ->whereRaw("name LIKE ? ESCAPE '\\'", [$like])
                ->orderBy('name')
                ->limit($limit - count($out))
                ->pluck('name')
                ->all() as $name) {
                $out[] = (string) $name;
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * LIKE 通配符转义
     *
     * `%` / `_` 不转义时，用户搜 `50%` 会变成 `50` 前缀匹配任意后缀，搜 `_` 直接命中全部商品
     * （退化为全表扫描）。必须用 `ESCAPE '\'` 显式声明转义符，否则 PG/SQLite 都不认反斜杠。
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
