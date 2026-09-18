<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\UserAddress;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 收货地址（API 文档 3.3 ~ 3.7）
 */
class AddressController extends Controller
{
    use ApiResponse;

    /** 每用户地址数量上限（设计文档 CubeShop_Address_Design_v1.0 §8） */
    private const MAX_ADDRESSES = 20;

    /** 地址列表（默认置顶，其次按使用频次倒序） */
    public function index(Request $request): JsonResponse
    {
        $addresses = UserAddress::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderByDesc('used_count')
            ->orderByDesc('id')
            ->get();

        return $this->success($addresses->map(fn (UserAddress $a) => $this->format($a))->values());
    }

    /**
     * 行政区划数据（省/市/区三级，GB/T 2260 编码树，含中国香港、中国澳门、中国台湾）
     *
     * T-053 Stage1 起统一由 RegionService 提供唯一数据源（regions.json，code+name 树），
     * 前后端共用；数据极少变更 → 强缓存 + ETag（命中返回 304）。
     */
    public function regions(Request $request): JsonResponse
    {
        $tree = \App\Services\Common\RegionService::tree();
        $etag = '"regions-'.md5((string) json_encode($tree)).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        return $this->success(['regions' => $tree])
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /** 一行文本智能解析（V1.1 E04 / T-028） */
    public function parse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:500'],
        ]);

        return $this->success(app(\App\Services\Address\AddressService::class)->parse($data['text']));
    }

    /** 新增地址（首个地址自动设为默认） */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAddress($request);

        $userId = $request->user()->id;
        $count = UserAddress::where('user_id', $userId)->count();

        if ($count >= self::MAX_ADDRESSES) {
            throw BusinessException::badRequest('收货地址最多添加 '.self::MAX_ADDRESSES.' 条');
        }

        $isFirst = $count === 0;

        $address = DB::transaction(function () use ($userId, $data, $isFirst) {
            if (! empty($data['is_default']) || $isFirst) {
                UserAddress::where('user_id', $userId)->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            return UserAddress::create([...$data, 'user_id' => $userId]);
        });

        return $this->success($this->format($address), '创建成功');
    }

    /** 更新地址 */
    public function update(Request $request, int $id): JsonResponse
    {
        $address = UserAddress::where('user_id', $request->user()->id)->find($id);
        if (! $address) {
            throw BusinessException::notFound('地址不存在');
        }

        $data = $this->validateAddress($request, forUpdate: true);

        DB::transaction(function () use ($address, $data) {
            if (! empty($data['is_default'])) {
                UserAddress::where('user_id', $address->user_id)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
                $address->is_default = true;
            }
            $address->fill($data)->save();
        });

        return $this->success($this->format($address), '更新成功');
    }

    /** 删除地址（删除默认后自动把剩余最新一条设为默认） */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $address = UserAddress::where('user_id', $request->user()->id)->find($id);
        if (! $address) {
            throw BusinessException::notFound('地址不存在');
        }

        $userId = $address->user_id;
        $wasDefault = (bool) $address->is_default;

        DB::transaction(function () use ($address, $userId, $wasDefault) {
            $address->delete();

            // 默认地址被删：自动接替（取剩余最新一条）
            if ($wasDefault) {
                $next = UserAddress::where('user_id', $userId)->orderByDesc('id')->first();
                if ($next) {
                    UserAddress::where('user_id', $userId)->update(['is_default' => false]);
                    $next->is_default = true;
                    $next->save();
                }
            }
        });

        return $this->success(null, '删除成功');
    }

    /** 设为默认地址 */
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $address = UserAddress::where('user_id', $request->user()->id)->find($id);
        if (! $address) {
            throw BusinessException::notFound('地址不存在');
        }

        DB::transaction(function () use ($address) {
            UserAddress::where('user_id', $address->user_id)->update(['is_default' => false]);
            $address->is_default = true;
            $address->save();
        });

        return $this->success(null, '已设为默认地址');
    }

    // ---------- internals ----------

    private function validateAddress(Request $request, bool $forUpdate = false): array
    {
        return $request->validate([
            'contact_name' => [$forUpdate ? 'sometimes' : 'required', 'string', 'max:64'],
            'contact_phone' => [$forUpdate ? 'sometimes' : 'required', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'province' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:64'],
            'district' => ['nullable', 'string', 'max:64'],
            'detail_address' => [$forUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:16'],
            'is_default' => ['nullable', 'boolean'],
        ]);
    }

    private function format(UserAddress $a): array
    {
        return [
            'id' => $a->id,
            'contact_name' => $a->contact_name,
            'contact_phone' => substr($a->contact_phone, 0, 3).'****'.substr($a->contact_phone, -4),
            'contact_phone_full' => $a->contact_phone,
            'province' => $a->province,
            'city' => $a->city,
            'district' => $a->district,
            'detail_address' => $a->detail_address,
            'label' => $a->label,
            'is_default' => (bool) $a->is_default,
            'used_count' => (int) $a->used_count,
            'last_used_at' => $a->last_used_at?->format('Y-m-d H:i:s'),
        ];
    }
}
