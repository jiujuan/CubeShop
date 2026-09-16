<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 阶段 2：把存量买家 Sanctum 令牌的 tokenable_type 改写为 App\Models\User
 *
 * 不做这一步的话，买家在拆分后已登录的令牌会解析出 SysUser 实例（错误模型），
 * 或被 auth:sanctum 拒绝，导致全体买家需要重新登录。
 *
 * 判据：tokenable_type = App\Models\SysUser 且 tokenable_id 存在于 users 表。
 * 批量且幂等，可重复执行。
 */
return new class extends Migration
{
    private const LEGACY_TYPE = 'App\Models\SysUser';

    private const NEW_TYPE = 'App\Models\User';

    public function up(): void
    {
        $this->rewrite(self::LEGACY_TYPE, self::NEW_TYPE);
    }

    public function down(): void
    {
        $this->rewrite(self::NEW_TYPE, self::LEGACY_TYPE);
    }

    private function rewrite(string $from, string $to): void
    {
        if (! Schema::hasTable('personal_access_tokens') || ! Schema::hasTable('users')) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', $from)
            ->whereIn('tokenable_id', function ($query) {
                $query->select('id')->from('users');
            })
            ->update(['tokenable_type' => $to]);
    }
};
