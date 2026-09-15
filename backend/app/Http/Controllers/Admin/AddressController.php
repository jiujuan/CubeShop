<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\SysUser;
use App\Models\UserAddress;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 后台收货地址管理（设计文档 CubeShop_Address_Design_v1.0 §5）
 *
 * 定位：只读查看（address.view）+ 受限代改（address.manage）。
 * 不提供代用户新增/删除/设默认——增删主权保留在前台用户侧；
 * 订单收货信息以下单时刻的 orders.address_snapshot 快照为准，代改不影响历史订单。
 */
class AddressController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 某用户的收货地址列表（无全局地址列表接口，按用户逐个查看）
     * GET /admin/users/{userId}/addresses（权限 address.view）
     */
    public function userIndex(int $userId): JsonResponse
    {
        $user = SysUser::query()->find($userId);
        if (! $user) {
            throw BusinessException::notFound('用户不存在');
        }

        $addresses = UserAddress::query()
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return $this->success($addresses->map(fn (UserAddress $a) => $this->format($a))->values());
    }

    /**
     * 代用户修改地址内容（明确不含 is_default / user_id）
     * PUT /admin/addresses/{id}（权限 address.manage）
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $address = UserAddress::query()->find($id);
        if (! $address) {
            throw BusinessException::notFound('地址不存在');
        }

        // 显式拒绝禁改字段（优于静默忽略，防止调用方误以为生效）
        if ($request->has('is_default') || $request->has('user_id')) {
            throw BusinessException::badRequest('不支持修改默认标记或归属用户');
        }

        $data = $this->validateAddress($request);

        if (empty($data)) {
            throw BusinessException::badRequest('未提交任何修改字段');
        }

        $before = $address->only(['contact_name', 'contact_phone', 'province', 'city', 'district', 'detail_address']);

        DB::transaction(function () use ($address, $data) {
            $address->fill($data)->save();
        });

        $this->operationLog->record(
            $request->user()->id,
            'address',
            'update_address',
            'user_address',
            $address->id,
            sprintf('代改用户 #%d 地址 #%d：%s', $address->user_id, $address->id, json_encode(['before' => $before, 'after' => $data], JSON_UNESCAPED_UNICODE)),
        );

        return $this->success($this->format($address->refresh()), '地址已代为修改');
    }

    // ---------- internals ----------

    /** 校验规则与前台 AddressController 保持一致，保证两端数据标准统一 */
    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'contact_name' => ['sometimes', 'string', 'max:64'],
            'contact_phone' => ['sometimes', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'province' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:64'],
            'district' => ['nullable', 'string', 'max:64'],
            'detail_address' => ['sometimes', 'string', 'max:255'],
        ]);
    }

    /** 格式与前台一致：列表脱敏，contact_phone_full 仅供编辑回显 */
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
            'updated_at' => $a->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
