<?php

/**
 * CS-201 证据脚本：导出 cs_quick_reply / cs_announcement 的字段与索引快照
 *
 * 用法（开发库 PG cubeshop）：
 *   php artisan tinker --execute="require 'D:/codeproject/PHP/CubeShop/docs/testing/evidence/cs/CS-201/dump-schema.php';"
 *
 * 仅读取 information_schema / pg_indexes，不修改任何数据。
 */

use Illuminate\Support\Facades\DB;

$tables = ['cs_quick_reply', 'cs_announcement'];

echo '数据库连接：'.DB::connection()->getDriverName().' / '.DB::connection()->getDatabaseName().PHP_EOL;
echo '导出时间：'.now()->toDateTimeString().PHP_EOL.PHP_EOL;

foreach ($tables as $table) {
    echo '===== '.$table.' ====='.PHP_EOL;

    $columns = DB::select(
        'select column_name, data_type, character_maximum_length, is_nullable, column_default
         from information_schema.columns where table_name = ? order by ordinal_position',
        [$table],
    );

    foreach ($columns as $c) {
        echo sprintf(
            "  %-22s %-18s %-6s %-5s %s\n",
            $c->column_name,
            $c->data_type.($c->character_maximum_length ? '('.$c->character_maximum_length.')' : ''),
            $c->column_default === null ? '-' : $c->column_default,
            $c->is_nullable,
            '',
        );
    }

    echo '  --- 索引 ---'.PHP_EOL;
    foreach (DB::select('select indexname, indexdef from pg_indexes where tablename = ? order by indexname', [$table]) as $i) {
        echo '  '.$i->indexname.PHP_EOL;
    }

    $count = DB::table($table)->count();
    echo '  行数：'.$count.PHP_EOL.PHP_EOL;
}

// 决策 D8：确认配置承载方式（复用 system_configs，不存在 cs_service_config）
echo '===== 决策 D8：配置承载 ====='.PHP_EOL;
echo '  system_configs 存在：'.(Schema::hasTable('system_configs') ? 'yes' : 'no').PHP_EOL;
echo '  cs_service_config 存在：'.(Schema::hasTable('cs_service_config') ? 'yes(违反 D8)' : 'no(符合 D8)').PHP_EOL;

echo PHP_EOL.'===== cs_ticket 满意度 / 时间锚点字段核对 ====='.PHP_EOL;
foreach (['satisfaction', 'satisfaction_remark', 'satisfaction_at', 'first_replied_at', 'completed_at', 'closed_at', 'close_reason'] as $c) {
    echo sprintf("  %-22s %s\n", $c, Schema::hasColumn('cs_ticket', $c) ? 'OK' : 'MISSING');
}
