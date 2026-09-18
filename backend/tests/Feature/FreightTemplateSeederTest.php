<?php

use Database\Seeders\FreightTemplateSeeder;
use App\Models\FreightTemplate;
use App\Services\Shipping\FreightRuleValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('种子数据：4 个模板全部入库且规则通过校验器', function () {
    $this->seed(FreightTemplateSeeder::class);

    $templates = FreightTemplate::all();
    expect($templates->count())->toBe(4)
        ->and($templates->every(fn ($t) => $t->status === 1))->toBeTrue();

    foreach ($templates as $t) {
        expect(FreightRuleValidator::validate($t->mode, $t->rules))->toBe([]);
    }
});

test('种子数据：三种 mode 形态齐全（fixed/weight/region）', function () {
    $this->seed(FreightTemplateSeeder::class);

    $modes = FreightTemplate::query()->pluck('mode')->unique()->sort()->values()->all();
    expect($modes)->toBe(['fixed', 'region', 'weight']);
});

test('种子数据：region 省 code 均为 RegionService 合法编码', function () {
    $this->seed(FreightTemplateSeeder::class);

    foreach (FreightTemplate::where('mode', 'region')->get() as $t) {
        foreach ($t->rules['areas'] as $area) {
            foreach ($area['provinces'] as $code) {
                expect(\App\Services\Common\RegionService::isValidProvinceCode($code))->toBeTrue();
            }
        }
    }
});

test('种子数据：幂等 —— 重复执行不产生重复行', function () {
    $this->seed(FreightTemplateSeeder::class);
    $this->seed(FreightTemplateSeeder::class);

    expect(FreightTemplate::count())->toBe(4);
});
