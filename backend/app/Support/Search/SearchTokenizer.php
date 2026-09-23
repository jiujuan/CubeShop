<?php

namespace App\Support\Search;

/**
 * 检索分词器（站内搜索 S1-01）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.1
 *
 * 为什么自己分词而不是用 zhparser：
 * - 运行环境是 `postgres:16-alpine` 官方镜像，只带 contrib，**没有 zhparser**（需编译安装）；
 * - 生产若用托管 PG（RDS 类），通常也不允许装第三方扩展；
 * - 因此中文切分在 PHP 侧完成，写进 `products.search_title` / `search_body`，
 *   由 PG 生成列 `search_vector`（`to_tsvector('simple', ...)`）消费。
 *
 * 输出形态：空格分隔的 token 串（`simple` 配置按空格/标点切词，故中文 bigram 必须以空格分隔）。
 *
 * 规则：
 * - 汉字连续段 → 滑窗 bigram（`北欧实木沙发` → `北欧 欧实 实木 木沙 沙发`）；
 *   单字段落单字时原样输出（如 `椅`），但查询侧单字要走 LIKE 降级（{@see self::isSingleCjkChar()}）；
 * - 非汉字段 → 按非字母数字切词、转小写、过滤英文停用词（`iPhone15 Pro` → `iphone15 pro`）；
 * - 全角英数与全角空格先归一为半角，避免 `ＡＢＣ` 与 `ABC` 被当成两个词；
 * - 去重保序，tsvector 侧不再重复计数。
 *
 * ⚠️ 本类只做「文本 → token」，不负责：
 * - 去 HTML / 反转义 —— 由 SearchIndexWriter 在拼接字段前处理；
 * - tsquery 字面量转义与 `& | : *` 拼接 —— 由 PostgresFtsEngine 负责（那里才需要防语法错误）。
 */
final class SearchTokenizer
{
    /** 索引字段默认截断长度（字符数，防止 GIN 膨胀） */
    public const DEFAULT_MAX_LENGTH = 1000;

    /** 单个 token 最大长度，超长丢弃（防脏数据灌爆索引） */
    public const MAX_TOKEN_LENGTH = 64;

    /**
     * 计入中文的 Unicode 区段（CJK 统一表意文字 + 扩展 A + 兼容表意文字）
     *
     * ⚠️ 不能用 `\p{Han}` 代替：实测本环境 PCRE 把 U+3002「。」也判定为 Han，
     * 于是中文标点会参与 bigram，产生「。沙」这类噪音 token。
     * 因此显式列出区段（扩展 B 区罕见字电商场景用不到，且需 UTF-16 支持，不纳入）。
     */
    private const CJK = '\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}';

    /**
     * 英文停用词（仅作用于纯 ASCII 词，中文不参与）
     *
     * 表单是 isset 查表用的「词 => true」，避免 in_array 线性扫描。
     */
    private const STOP_WORDS = [
        'a' => true, 'an' => true, 'and' => true, 'are' => true, 'as' => true, 'at' => true,
        'be' => true, 'been' => true, 'but' => true, 'by' => true, 'do' => true, 'does' => true,
        'for' => true, 'from' => true, 'he' => true, 'her' => true, 'his' => true, 'i' => true,
        'in' => true, 'is' => true, 'it' => true, 'its' => true, 'my' => true, 'no' => true,
        'not' => true, 'of' => true, 'on' => true, 'or' => true, 'our' => true, 'she' => true,
        'that' => true, 'the' => true, 'their' => true, 'them' => true, 'then' => true,
        'there' => true, 'these' => true, 'they' => true, 'this' => true, 'those' => true,
        'to' => true, 'was' => true, 'we' => true, 'were' => true, 'with' => true, 'you' => true,
        'your' => true,
    ];

    /**
     * 分词：返回去重后的 token 数组（保序）
     *
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return [];
        }

        $tokens = [];

        // 切成「汉字连续段」与「非汉字连续段」两段交替，分别按各自规则处理
        preg_match_all('/['.self::CJK.']+|[^'.self::CJK.']+/u', $normalized, $matches);

        foreach ($matches[0] as $segment) {
            if ($segment === '') {
                continue;
            }

            $tokens = [
                ...$tokens,
                ...(preg_match('/^['.self::CJK.']+$/u', $segment) === 1
                    ? $this->bigrams($segment)
                    : $this->words($segment)),
            ];
        }

        return array_values(array_unique($tokens));
    }

    /**
     * 分词并拼成索引串（写入 search_title / search_body 的形态）
     *
     * 截断按「整 token」丢弃，绝不会切出半个 token —— 半个 token 会导致该词永远搜不到。
     */
    public function tokenize(string $text, int $maxLength = self::DEFAULT_MAX_LENGTH): string
    {
        $tokens = $this->tokens($text);

        if ($tokens === []) {
            return '';
        }

        if ($maxLength <= 0) {
            return '';
        }

        $out = '';

        foreach ($tokens as $index => $token) {
            $candidate = $out === '' ? $token : $out.' '.$token;

            // 截断以「整 token」为粒度：宁可少收录，也不能切出半个 token。
            // 首个 token 无条件收录 —— 连它都超限就返回空串，等于该字段静默失去索引，
            // 这比略微超出 maxLength 更糟且更难排查。
            if ($index > 0 && mb_strlen($candidate, 'UTF-8') > $maxLength) {
                break;
            }

            $out = $candidate;
        }

        return $out;
    }

    /**
     * 归一化：全角转半角、控制字符与各类空白归一为单个半角空格
     *
     * 不做整体小写 —— 汉字无大小写，小写只在 {@see self::words()} 里对非汉字段生效，
     * 避免在 normalize 上叠加语义让调用方困惑。
     */
    public function normalize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // 全角英数 → 半角（a）、全角空格 → 半角（s）；mbstring 为 Laravel 硬性依赖
        $text = mb_convert_kana($text, 'as', 'UTF-8');

        // 控制字符与格式字符（含零宽）→ 空格，否则会把相邻词粘成一个 token
        $text = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text) ?? '';

        // 各类空白（全角空格、NBSP、制表换行）→ 单个半角空格
        $text = preg_replace('/[\p{Z}\s]+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * 是否「恰好一个汉字」——查询侧降级判定
     *
     * 单字查询（如「椅」）走 bigram 匹配会召回大量噪声（`椅` 参与的每个 bigram 都命中），
     * 故设计文档 §4.4 降级链第 0 步规定：单字直接走 LIKE 引擎。
     */
    public function isSingleCjkChar(string $text): bool
    {
        $normalized = $this->normalize($text);

        return $normalized !== '' && preg_match('/^['.self::CJK.']$/u', $normalized) === 1;
    }

    /**
     * 汉字段 → 滑窗 bigram
     *
     * @return list<string>
     */
    private function bigrams(string $segment): array
    {
        $chars = preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false || $chars === []) {
            return [];
        }

        if (count($chars) === 1) {
            return $chars;
        }

        $out = [];
        $last = count($chars) - 1;

        for ($i = 0; $i < $last; $i++) {
            $out[] = $chars[$i].$chars[$i + 1];
        }

        return $out;
    }

    /**
     * 非汉字段 → 按非字母数字切词、转小写、过滤停用词
     *
     * @return list<string>
     */
    private function words(string $segment): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $segment, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [];
        }

        $out = [];

        foreach ($parts as $part) {
            $word = mb_strtolower($part, 'UTF-8');

            if ($word === '' || mb_strlen($word, 'UTF-8') > self::MAX_TOKEN_LENGTH) {
                continue;
            }

            if (isset(self::STOP_WORDS[$word])) {
                continue;
            }

            $out[] = $word;
        }

        return $out;
    }
}
