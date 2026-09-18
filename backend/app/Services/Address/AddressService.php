<?php

namespace App\Services\Address;

use App\Models\UserAddress;

/**
 * 地址增强服务（V1.1 E04 / T-028）
 *
 * - 行政区划数据（T-053 Stage1 起统一委托 RegionService：code+name 树，单一数据源）
 * - 一行文本智能解析（姓名 / 手机 / 省 / 市 / 区 / 详址）
 * - 使用频次记录
 */
class AddressService
{
    /** 行政区划树（RegionService 统一加载，进程内缓存） */
    public function regions(): array
    {
        return \App\Services\Common\RegionService::tree();
    }

    /**
     * 解析一行地址文本
     *
     * 规则：先抽手机号，再按行政区划最长匹配省/市/区，剩余文本首段视为收货人、末段视为详址。
     * 无法识别时返回空字段而非抛错，由前端兜底提示。
     *
     * @return array{contact_name:string,contact_phone:string,province:string,city:string,district:string,detail_address:string,confidence:float}
     */
    public function parse(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $result = [
            'contact_name' => '',
            'contact_phone' => '',
            'province' => '',
            'city' => '',
            'district' => '',
            'detail_address' => '',
            'confidence' => 0.0,
        ];

        if ($text === '') {
            return $result;
        }

        // 1. 手机号（中国大陆 11 位）
        if (preg_match('/1[3-9]\d{9}/', $text, $m)) {
            $result['contact_phone'] = $m[0];
            $text = trim(str_replace($m[0], ' ', $text));
        }

        // 2. 行政区划匹配（省 → 市 → 区，走 RegionService 的 code+name 树）
        foreach ($this->regions() as $province) {
            $pName = $province['name'] ?? '';
            if ($pName === '' || ! str_contains($text, $pName)) {
                continue;
            }
            $result['province'] = $pName;
            $text = trim(str_replace($pName, ' ', $text));

            foreach ($province['children'] ?? [] as $city) {
                $cName = $city['name'] ?? '';
                // 直辖市 / 特区：省与市同名，已在上一步消费
                $cityMatched = ($cName !== '' && $cName === $pName)
                    || ($cName !== '' && str_contains($text, $cName));

                if (! $cityMatched) {
                    continue;
                }

                $result['city'] = $cName;
                if ($cName !== $pName) {
                    $text = trim(str_replace($cName, ' ', $text));
                }

                foreach ($city['children'] ?? [] as $area) {
                    $dName = $area['name'] ?? '';
                    if ($dName !== '' && str_contains($text, $dName)) {
                        $result['district'] = $dName;
                        $text = trim(str_replace($dName, ' ', $text));
                        break;
                    }
                }
                break;
            }
            break;
        }

        // 3. 剩余文本：首个连续中文字段视为收货人姓名，其余为详址
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text !== '') {
            $parts = explode(' ', $text);
            if (count($parts) > 1 && preg_match('/^[\x{4e00}-\x{9fa5}]{2,6}$/u', $parts[0])) {
                $result['contact_name'] = $parts[0];
                $result['detail_address'] = trim(implode(' ', array_slice($parts, 1)));
            } else {
                $result['detail_address'] = $text;
            }
        }

        // 置信度：识别出的关键字段比例
        $score = 0;
        foreach (['contact_name', 'contact_phone', 'province', 'detail_address'] as $key) {
            if ($result[$key] !== '') {
                $score++;
            }
        }
        $result['confidence'] = round($score / 4, 2);

        return $result;
    }

    /** 下单使用地址后累加频次（在订单创建事务内调用） */
    public function recordUsage(int $addressId): void
    {
        UserAddress::whereKey($addressId)->update([
            'used_count' => \Illuminate\Support\Facades\DB::raw('used_count + 1'),
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
