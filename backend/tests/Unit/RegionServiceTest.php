<?php

use App\Services\Common\RegionService;

/**
 * RegionService 单测（读真实 regions.json，不依赖 DB）
 */

test('树结构加载：34 个省级行政区，含港澳台', function () {
    $tree = RegionService::tree();

    expect(count($tree))->toBe(34);

    $names = array_column($tree, 'name');
    expect($names)->toContain('中国香港')
        ->and($names)->toContain('中国澳门')
        ->and($names)->toContain('中国台湾')
        ->and($names)->toContain('广东省');
});

test('树结构三级编码齐备（北京市→北京市→东城区）', function () {
    $bj = collect(RegionService::tree())->firstWhere('name', '北京市');

    expect($bj['code'])->toBe('110000')
        ->and($bj['children'][0]['name'])->toBe('北京市')
        ->and($bj['children'][0]['children'][0])->toBe(['code' => '110101', 'name' => '东城区']);
});

test('provinceName / nameOf 按 code 取名', function () {
    expect(RegionService::provinceName('440000'))->toBe('广东省')
        ->and(RegionService::nameOf('440300'))->toBe('深圳市')
        ->and(RegionService::nameOf('440305'))->toBe('南山区')
        ->and(RegionService::provinceName('999999'))->toBeNull()
        ->and(RegionService::nameOf('999999'))->toBeNull();
});

test('isValidProvinceCode 校验省 code', function () {
    expect(RegionService::isValidProvinceCode('650000'))->toBeTrue()
        ->and(RegionService::isValidProvinceCode('440305'))->toBeFalse() // 区 code 不是省 code
        ->and(RegionService::isValidProvinceCode('abc'))->toBeFalse()
        ->and(RegionService::isValidProvinceCode('990000'))->toBeFalse();
});

test('codeOfProvinceName：精确 / 前缀简称 / 未知', function () {
    expect(RegionService::codeOfProvinceName('广东省'))->toBe('440000')
        ->and(RegionService::codeOfProvinceName('内蒙古自治区'))->toBe('150000')
        ->and(RegionService::codeOfProvinceName('内蒙古'))->toBe('150000') // 前缀唯一命中
        ->and(RegionService::codeOfProvinceName('中国香港'))->toBe('810000')
        ->and(RegionService::codeOfProvinceName('火星省'))->toBeNull()
        ->and(RegionService::codeOfProvinceName(''))->toBeNull();
});
