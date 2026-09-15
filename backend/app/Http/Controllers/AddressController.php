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

    /** 地址列表 */
    public function index(Request $request): JsonResponse
    {
        $addresses = UserAddress::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return $this->success($addresses->map(fn (UserAddress $a) => $this->format($a))->values());
    }

    /** 新增地址（首个地址自动设为默认） */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAddress($request);

        $userId = $request->user()->id;
        $isFirst = ! UserAddress::where('user_id', $userId)->exists();

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

    /** 删除地址（删除默认后不自动顶替） */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = UserAddress::where('user_id', $request->user()->id)->where('id', $id)->delete();
        if (! $deleted) {
            throw BusinessException::notFound('地址不存在');
        }

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
            'is_default' => (bool) $a->is_default,
        ];
    }
}
