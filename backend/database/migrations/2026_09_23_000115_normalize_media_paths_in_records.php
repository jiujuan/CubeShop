<?php

use App\Support\MediaUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 存量图片 URL 清洗（媒体治理 P0，配合迁移 000114）
 *
 * 历史数据把 `Storage::disk('public')->url($path)` 的**绝对 URL** 直接写进了业务表，
 * 形如 `http://localhost:8000/storage/uploads/products/20260918/x.png` —— 域名被写死，
 * 部署到正式环境当天全部历史图片就会 404。
 *
 * 本迁移统一改写为**域名无关**的形态：
 * - 单值列 / JSON 数组列 / 图片类系统配置 → 相对路径 `uploads/products/20260918/x.png`
 * - 富文本（商品详情 / 帮助中心正文）与嵌套 JSON（CMS 区块）→ 根相对 `/storage/uploads/...`
 *   （正文是「一堆字符串」，无法逐条当成列处理，只能保留 URL 形态；读取时由 MediaRichText cast 拼域名）
 *
 * 幂等：值未变化的行不写回，重复执行无副作用。
 *
 * ⚠️ 之后仍会长这样的数据由模型的 MediaPath cast 在写入时就归一掉，不会再积累。
 */
return new class extends Migration
{
    /** @var array<int, array{table: string, columns: array<int, string>}> 单值列：归一成相对路径 */
    private const SINGLE_COLUMNS = [
        ['table' => 'products', 'columns' => ['main_image']],
        ['table' => 'product_images', 'columns' => ['url']],
        ['table' => 'brands', 'columns' => ['logo']],
        ['table' => 'home_banners', 'columns' => ['image']],
        ['table' => 'users', 'columns' => ['avatar']],
        ['table' => 'sys_user', 'columns' => ['avatar']],
        ['table' => 'cs_faq_article', 'columns' => ['cover_image']],
        ['table' => 'payments', 'columns' => ['voucher_url']],
        // 快照列：语义是「下单时刻的字面值」，但写死的域名同样会让历史订单图裂，一并清洗
        ['table' => 'order_items', 'columns' => ['sku_image']],
    ];

    /** @var array<int, array{table: string, columns: array<int, string>}> JSON 数组列：元素逐个归一 */
    private const LIST_COLUMNS = [
        ['table' => 'reviews', 'columns' => ['images']],
        ['table' => 'refunds', 'columns' => ['images', 'admin_images']],
        ['table' => 'cs_ticket_message', 'columns' => ['images']],
    ];

    /** @var array<int, array{table: string, columns: array<int, string>}> 富文本：内联图改写成根相对 */
    private const TEXT_COLUMNS = [
        ['table' => 'products', 'columns' => ['description', 'description_md']],
        ['table' => 'cs_faq_article', 'columns' => ['content', 'content_md']],
    ];

    /** @var array<int, array{table: string, columns: array<int, string>}> 嵌套 JSON：递归改写叶子字符串 */
    private const NESTED_COLUMNS = [
        ['table' => 'cs_faq_article', 'columns' => ['blocks', 'page_fields']],
    ];

    /** 存图片的系统配置键 */
    private const MEDIA_CONFIG_KEYS = ['site.logo', 'site.logo_small'];

    public function up(): void
    {
        foreach (self::SINGLE_COLUMNS as $source) {
            $this->cleanRows($source['table'], $source['columns'], fn (string $v): string => MediaUrl::toPath($v) ?? $v);
        }

        foreach (self::LIST_COLUMNS as $source) {
            $this->cleanRows($source['table'], $source['columns'], fn (string $v): string => $this->cleanList($v));
        }

        foreach (self::TEXT_COLUMNS as $source) {
            $this->cleanRows($source['table'], $source['columns'], fn (string $v): string => MediaUrl::normalizeEmbedded($v) ?? $v);
        }

        foreach (self::NESTED_COLUMNS as $source) {
            $this->cleanRows($source['table'], $source['columns'], fn (string $v): string => $this->cleanNested($v));
        }

        $this->cleanConfigs();
    }

    public function down(): void
    {
        // 不可逆：原有绝对 URL 依赖当时的 APP_URL，无法可靠还原；
        // 且降级后图片会回到「写死域名」的坏形态。此处刻意留空（只降表结构，不降数据语义）。
    }

    /**
     * 逐行清洗：值无变化则不写回
     *
     * @param  array<int, string>  $columns
     * @param  callable(string): string  $converter
     */
    private function cleanRows(string $table, array $columns, callable $converter): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return;
        }

        $columns = array_values(array_filter(
            $columns,
            fn (string $c): bool => \Illuminate\Support\Facades\Schema::hasColumn($table, $c),
        ));

        if ($columns === []) {
            return;
        }

        DB::table($table)
            ->orderBy('id')
            ->select(['id', ...$columns])
            ->chunkById(200, function ($rows) use ($table, $columns, $converter): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($columns as $column) {
                        $raw = $row->{$column} ?? null;
                        if (! is_string($raw) || $raw === '') {
                            continue;
                        }

                        $converted = $converter($raw);
                        if ($converted !== $raw) {
                            $updates[$column] = $converted;
                        }
                    }

                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    /** JSON 数组：["http://h/storage/uploads/a.png"] → ["uploads/a.png"] */
    private function cleanList(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $raw;
        }

        $converted = array_map(
            fn (mixed $v): mixed => is_string($v) ? (MediaUrl::toPath($v) ?? $v) : $v,
            $decoded,
        );

        return json_encode($converted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 嵌套 JSON：递归改写所有字符串叶子 */
    private function cleanNested(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $raw;
        }

        return json_encode($this->walk($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function walk(mixed $node): mixed
    {
        if (is_string($node)) {
            return MediaUrl::toPath($node) ?? $node;
        }

        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $k => $v) {
            $node[$k] = $this->walk($v);
        }

        return $node;
    }

    /** 图片类系统配置：归一成相对路径 */
    private function cleanConfigs(): void
    {
        foreach (DB::table('system_configs')->whereIn('config_key', self::MEDIA_CONFIG_KEYS)->get() as $row) {
            $raw = (string) ($row->config_value ?? '');
            if ($raw === '') {
                continue;
            }

            $converted = MediaUrl::toPath($raw) ?? $raw;
            if ($converted !== $raw) {
                DB::table('system_configs')->where('id', $row->id)->update(['config_value' => $converted]);
            }
        }
    }
};
