<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品编码（业务主键）
 *
 * 背景：商品批量导入缺少幂等锚点——没有编码就无法判断「这一行是新建还是更新」，
 * 运营不敢重复导入。故为 products 增加可空的业务编码，作为导入/更新的定位键。
 *
 * 可空：手工在后台新建的商品可以不填，不影响现有流程。
 * 唯一：含软删行一并占用（与 sku_code 同体例），避免复用已删除商品的编码。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('code', 64)->nullable()->after('id')
                ->comment('商品编码（业务主键，批量导入/更新锚点，可空）');
        });

        // MySQL/PG 可直接加唯一索引；SQLite 对 NULL 允许多行（符合可空语义）
        Schema::table('products', function (Blueprint $table) {
            $table->unique('code', 'products_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_code_unique');
            $table->dropColumn('code');
        });
    }
};
