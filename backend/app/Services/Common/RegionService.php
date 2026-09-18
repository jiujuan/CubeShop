<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\File;

/**
 * 行政区划字典服务（GB/T 2260 省市区三级）
 *
 * 唯一数据源：resources/data/regions.json（进仓库，前后端共用同一份，杜绝两套数据漂移）。
 * 结构：[{ code, name, children: [{ code, name, children: [{ code, name }] }] }]。
 *
 * 用途：
 *  - 运费 region 模板按省 code 匹配（规避「内蒙古/内蒙古自治区」等名称变体）；
 *  - 模板规则校验（provinces 必须是合法省 code）；
 *  - 公开接口 GET /api/regions 下发给前端（地址选择器 / 模板编辑器共用）。
 *
 * 数据极少变更（行政区划），进程内静态缓存即可，无需外部缓存层。
 * 乡镇级不收录（体量过大，地址仅存三级文本，无落库需求）。
 */
final class RegionService
{
    private static ?array $tree = null;

    /** @var array<string, string>|null code => name（含全部三级） */
    private static ?array $nameMap = null;

    /** @var array<string, string>|null 省 code => name */
    private static ?array $provinceMap = null;

    /** 完整树结构（省→市→区县） */
    public static function tree(): array
    {
        if (self::$tree === null) {
            $path = resource_path('data/regions.json');
            abort_unless(File::exists($path), 500, '地区字典文件缺失');

            $tree = json_decode((string) File::get($path), true);
            if (! is_array($tree) || $tree === []) {
                abort(500, '地区字典文件损坏');
            }
            self::$tree = $tree;
        }

        return self::$tree;
    }

    /** 省级列表（code + name，前端省份多选 / 下拉用） */
    public static function provinces(): array
    {
        if (self::$provinceMap === null) {
            self::$provinceMap = [];
            foreach (self::tree() as $prov) {
                self::$provinceMap[(string) $prov['code']] = (string) $prov['name'];
            }
        }

        return array_map(
            fn (string $code, string $name) => ['code' => $code, 'name' => $name],
            array_keys(self::$provinceMap),
            self::$provinceMap,
        );
    }

    /** 按省 code 取省名；未知 code 返回 null */
    public static function provinceName(string $code): ?string
    {
        if (self::$provinceMap === null) {
            self::provinces();
        }

        return self::$provinceMap[$code] ?? null;
    }

    /** 任意层级 code → name（省/市/区县）；未知返回 null */
    public static function nameOf(string $code): ?string
    {
        if (self::$nameMap === null) {
            self::$nameMap = [];
            foreach (self::tree() as $prov) {
                self::$nameMap[(string) $prov['code']] = (string) $prov['name'];
                foreach ($prov['children'] as $city) {
                    self::$nameMap[(string) $city['code']] = (string) $city['name'];
                    foreach ($city['children'] as $area) {
                        self::$nameMap[(string) $area['code']] = (string) $area['name'];
                    }
                }
            }
        }

        return self::$nameMap[$code] ?? null;
    }

    /** 省code 是否合法（运费 region 规则校验用） */
    public static function isValidProvinceCode(string $code): bool
    {
        return self::provinceName($code) !== null;
    }

    /**
     * 省名 → 省 code（地址快照存的是省名，运费 region 按 code 匹配前需换算）
     *
     * 兼容常见变体：调用方传入「内蒙古」「广西」等简称时按前缀匹配唯一省。
     */
    public static function codeOfProvinceName(string $name): ?string
    {
        if (self::$provinceMap === null) {
            self::provinces();
        }

        $name = trim($name);
        if ($name === '') {
            return null;
        }

        // 精确匹配（provinceMap: code => name）
        $exact = array_search($name, self::$provinceMap, true);
        if ($exact !== false) {
            return $exact;
        }

        // 前缀匹配（如「内蒙古」→「内蒙古自治区」），必须唯一
        $hits = [];
        foreach (self::$provinceMap as $code => $fullName) {
            if (str_starts_with($fullName, $name)) {
                $hits[] = $code;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }
}
