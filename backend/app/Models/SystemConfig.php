<?php

namespace App\Models;

use App\Services\Common\ConfigService;
use Illuminate\Database\Eloquent\Model;

class SystemConfig extends Model
{
    protected $table = 'system_configs';

    protected $fillable = [
        'config_key',
        'config_value',
        'description',
    ];

    /**
     * 写入即失效 {@see ConfigService} 的整表缓存
     *
     * ⚠️ 缓存一致性只能挂在**写入侧**，不能指望读取侧：
     * `ConfigService::all()` 把整张 `system_configs` 缓存 300 秒（缓存键 `sys_configs`），
     * 原先只有 `ConfigService::set()` 会失效它。于是任何**绕过 ConfigService 的直写**
     * （Seeder、迁移、tinker、后台脚本）都会写进库里却读不出来 —— 表现为
     * 「配置明明写进去了，程序行为却没变」，排查成本极高（TC-NOTIFY-006 就是这么挂的：
     * `ReviewNotifySeeder` 直写 `notify.mail_types`，而此前的商品写入已把缓存焐热）。
     *
     * 放在模型事件里，是为了让「谁能写 system_configs」这件事自动闭环 ——
     * 新增写入路径时不必记得手动 flush。
     *
     * 注：`Model::query()->delete()` 这类批量操作**不触发**模型事件，
     * 需要删行的代码请沿用 `ConfigService::flush()`（如 `SearchConfig::forget()`）。
     */
    protected static function booted(): void
    {
        $invalidate = static function (): void {
            app(ConfigService::class)->flush();
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }
}
