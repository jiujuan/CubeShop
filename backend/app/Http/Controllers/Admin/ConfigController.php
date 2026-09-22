<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use App\Services\Common\FileUploadService;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use App\Support\ConfigGroup;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConfigController extends Controller
{
    use ApiResponse;

    /** 站点 logo 上传存放模块（storage/app/public/uploads/site/{Ymd}） */
    private const SITE_LOGO_MODULE = 'site';

    /**
     * 存图片的配置键（媒体治理 P0）
     *
     * 这些键的值是小图片路径：**入库归一为相对路径**，出参时才拼域名，
     * 否则「站点配置」会成为唯一还把 APP_URL 写死进数据的地方。
     */
    private const MEDIA_KEYS = ['site.logo', 'site.logo_small'];

    public function __construct(
        private readonly OperationLogService $operationLog,
        private readonly FileUploadService $uploader,
    ) {}

    /**
     * 系统配置列表（API 文档 8.6，权限 config.manage）
     * GET /admin/configs
     *
     * 返回按「分组 → 配置键」排序的扁平列表，每项带 group 标签，
     * 后台据此渲染 Tab（分组真源见 App\Support\ConfigGroup）。
     */
    public function index()
    {
        $configs = SystemConfig::orderBy('config_key')->get()
            ->map(fn (SystemConfig $c) => [
                'config_key' => $c->config_key,
                'config_value' => $this->serializeValue($c),
                'description' => $c->description,
                'group' => ConfigGroup::labelOf($c->config_key),
                'updated_at' => $c->updated_at?->format('Y-m-d H:i:s'),
            ])
            // 先按 Tab 顺序、再按配置键排序，前端顺序取用即可得到稳定的 Tab 与条目顺序
            ->sortBy([
                fn (array $a, array $b) => ConfigGroup::orderOf($a['group']) <=> ConfigGroup::orderOf($b['group']),
                fn (array $a, array $b) => $a['config_key'] <=> $b['config_key'],
            ])
            ->values();

        return $this->success($configs);
    }

    /**
     * 上传站点图片（logo 等，权限 config.manage）
     * POST /admin/configs/upload  form-data: file
     *
     * 独立于 /admin/upload（后者落在 products 目录），此处落在 uploads/site。
     */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'image', 'max:5120'], // 5MB
        ]);

        $url = $this->uploader->uploadImage($data['file'], self::SITE_LOGO_MODULE);

        return $this->success(['url' => $url], '上传成功');
    }

    /**
     * 批量更新系统配置（API 文档 8.6）
     * PUT /admin/configs  body: { configs: [{config_key, config_value}, ...] }
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'configs' => ['required', 'array', 'min:1'],
            'configs.*.config_key' => ['required', 'string', Rule::exists('system_configs', 'config_key')],
            // present + nullable：允许提交空串以清空配置（如移除已上传的 logo）。
            // 注意 Laravel 的 ConvertEmptyStringsToNull 会把 '' 转成 null，
            // 若只写 required/string 则「清空」永远 422；入库时统一归一为 ''。
            'configs.*.config_value' => ['present', 'nullable', 'string', 'max:255'],
        ]);

        foreach ($data['configs'] as $item) {
            $raw = $item['config_value'] ?? '';

            SystemConfig::where('config_key', $item['config_key'])
                ->update(['config_value' => $this->normalizeValue((string) $item['config_key'], $raw)]);
        }

        // 配置缓存失效
        app(\App\Services\Common\ConfigService::class)->flush();

        $this->operationLog->record(
            $request->user()->id,
            'system',
            'update_config',
            'system_configs',
            null,
            collect($data['configs'])->pluck('config_value', 'config_key')->all(),
        );

        return $this->success(null, '配置更新成功');
    }

    /** 出参：图片类配置键值拼成绝对 URL，供后台预览 */
    private function serializeValue(SystemConfig $config): string
    {
        $value = (string) ($config->config_value ?? '');

        return in_array($config->config_key, self::MEDIA_KEYS, true)
            ? (MediaUrl::to($value) ?? '')
            : $value;
    }

    /** 入库：图片类配置键值归一为相对路径，其余原样 */
    private function normalizeValue(string $key, mixed $value): string
    {
        $value = $value === null ? '' : (string) $value;

        return in_array($key, self::MEDIA_KEYS, true)
            ? (MediaUrl::toPath($value) ?? '')
            : $value;
    }
}
