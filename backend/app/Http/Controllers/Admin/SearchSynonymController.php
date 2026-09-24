<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SearchSynonym;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use App\Support\Search\SearchTokenizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 同义词维护（V1.2 站内搜索 S1-10 补做，设计 §4.8）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.4（降级链第 3 步）/ §4.8
 *
 * 权限 `search.manage`（超管专属，挂在 /admin/search 路由组上）。
 *
 * ⚠️ 与 Service 匹配逻辑的绑定约定（见 SearchSynonym docblock）：
 * **入库即归一化** —— from_word 与 to_words 一律过 `SearchTokenizer::normalize()`
 * + `mb_strtolower`，匹配是对小写化归一关键词做子串 `mb_strpos`。
 * 这里就是那条唯一写入路径，绕过它直写库会静默失配。
 *
 * ⚠️ 不需要 `bumpIndexVersion()`：同义词只影响查询侧第 3 步展开，
 * 不动索引内容与原词的命中集缓存（展开查询有自己独立的缓存 key），
 * 所以新增/删除规则即时生效，与引擎开关的版本递增是两回事。
 */
class SearchSynonymController extends Controller
{
    use ApiResponse;

    /** to_words 候选数上限：展开查询有 SYNONYM_MAX_QUERIES 熔断，这里管住单规则规模 */
    private const MAX_TO_WORDS = 10;

    public function __construct(
        private readonly OperationLogService $operationLog,
        private readonly SearchTokenizer $tokenizer,
    ) {
    }

    /** 同义词列表 GET /admin/search/synonyms */
    public function index(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 20)));

        $paginator = SearchSynonym::query()
            // 同词/候选都筛：运营找「这条词配过没有」时不记得记的是 from 还是 to
            ->when($keyword !== '', function ($q) use ($keyword): void {
                $q->where(fn ($inner) => $inner
                    ->where('from_word', 'like', '%'.$keyword.'%')
                    ->orWhere('to_words', 'like', '%'.$keyword.'%'));
            })
            ->orderBy('from_word')
            ->paginate($pageSize);

        return $this->paginated($paginator);
    }

    /** 新增规则 POST /admin/search/synonyms */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $synonym = SearchSynonym::create($data);

        $this->record($request, 'create', $synonym, null);

        return $this->success($this->present($synonym), '已创建', 201);
    }

    /** 更新规则 PUT /admin/search/synonyms/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $synonym = SearchSynonym::findOrFail($id);
        $data = $this->validated($request, $synonym->id);

        $before = $this->present($synonym);
        $synonym->update($data);

        $this->record($request, 'update', $synonym, $before);

        return $this->success($this->present($synonym), '已更新');
    }

    /** 删除规则 DELETE /admin/search/synonyms/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $synonym = SearchSynonym::findOrFail($id);
        $before = $this->present($synonym);

        // ⚠️ 必须走模型实例 delete()：批量 query()->delete() 不触发模型事件，
        // 词表缓存（search:synonyms）就不会失效，删了的规则继续生效到缓存过期
        $synonym->delete();

        $this->operationLog->record(
            $request->user()?->id,
            'search',
            'synonym.delete',
            'SearchSynonym',
            $synonym->id,
            ['before' => $before],
        );

        return $this->success(null, '已删除');
    }

    /**
     * 校验 + 归一化（from/to 一律 normalize + 小写，见类 docblock）
     *
     * @return array{from_word: string, to_words: list<string>, status: int}
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        // ⚠️ 结构规则只做长度与数量；去重 / 去 from 自身在下方归一化后做 ——
        // 'distinct' 在 HTTP 层比较的是原始串，全角空格、大小写差异会漏过，
        // 真正的判重必须发生在归一化之后
        $data = $request->validate([
            'from_word' => $this->fromWordRules($ignoreId),
            'to_words' => ['required', 'array', 'min:1', 'max:'.self::MAX_TO_WORDS],
            // ⚠️ nullable 而非 required：ConvertEmptyStringsToNull 会把 '' 变成 null，
            // required/string 都会拒绝它 —— 空候选是合法输入，交给下方归一化剔除
            'to_words.*' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'boolean'],
        ]);

        $from = $this->normalizeWord($data['from_word']);

        if ($from === '') {
            abort(422, 'from_word 归一化后为空');
        }

        // 归一化 + 去重 + 去自身：to 里含 from 的「替换」等于重跑原查询，白费一次检索
        // （'' 经中间件已成 null，(string) 归一回空串再被过滤）
        $toWords = array_values(array_unique(array_filter(
            array_map(fn ($w): string => $this->normalizeWord((string) $w), $data['to_words']),
            fn (string $w): bool => $w !== '' && $w !== $from,
        )));

        if ($toWords === []) {
            abort(422, 'to_words 归一化后与 from_word 相同或为空');
        }

        return [
            'from_word' => $from,
            'to_words' => $toWords,
            'status' => ($data['status'] ?? true) ? SearchSynonym::STATUS_ACTIVE : SearchSynonym::STATUS_BLOCKED,
        ];
    }

    private function normalizeWord(string $word): string
    {
        return mb_strtolower($this->tokenizer->normalize($word));
    }

    /**
     * from_word 的校验规则（unique 在 update 时排除自身）
     *
     * ⚠️ ignore() 只在有 id 时挂：ignore(null) 会把「id != NULL」写进查重 SQL，
     * SQL 里与 NULL 比较恒为 UNKNOWN，行为不可预期 —— 别依赖它。
     */
    private function fromWordRules(?int $ignoreId): array
    {
        $unique = $ignoreId === null
            ? Rule::unique('search_synonyms', 'from_word')
            : Rule::unique('search_synonyms', 'from_word')->ignore($ignoreId);

        return ['required', 'string', 'max:50', $unique];
    }

    /**
     * @return array{id: int, from_word: string, to_words: list<string>, status: int, updated_at: ?string}
     */
    private function present(SearchSynonym $synonym): array
    {
        return [
            'id' => $synonym->id,
            'from_word' => $synonym->from_word,
            'to_words' => array_values((array) $synonym->to_words),
            'status' => (int) $synonym->status,
            'updated_at' => $synonym->updated_at?->toDateTimeString(),
        ];
    }

    private function record(Request $request, string $action, SearchSynonym $synonym, ?array $before): void
    {
        $this->operationLog->record(
            $request->user()?->id,
            'search',
            'synonym.'.$action,
            'SearchSynonym',
            (string) $synonym->id,
            $before === null ? ['after' => $this->present($synonym)] : ['before' => $before, 'after' => $this->present($synonym)],
        );
    }
}
