<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SysOperationLog;
use App\Models\SystemConfig;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConfigController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 系统配置列表（API 文档 8.6，权限 config.manage）
     * GET /admin/configs
     */
    public function index()
    {
        $configs = SystemConfig::orderBy('config_key')->get();

        return $this->success($configs->map(fn (SystemConfig $c) => [
            'config_key' => $c->config_key,
            'config_value' => $c->config_value,
            'description' => $c->description,
            'updated_at' => $c->updated_at?->format('Y-m-d H:i:s'),
        ]));
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
            'configs.*.config_value' => ['required', 'string', 'max:255'],
        ]);

        foreach ($data['configs'] as $item) {
            SystemConfig::where('config_key', $item['config_key'])
                ->update(['config_value' => $item['config_value']]);
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
}
