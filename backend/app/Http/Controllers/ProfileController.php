<?php

namespace App\Http\Controllers;

use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Services\Common\FileUploadService;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 获取当前用户资料（API 文档 3.1）
     * GET /user/profile
     *
     * 面向买家（表 users）；买家不参与 spatie 权限体系，角色与权限码为空数组。
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user instanceof SysUser;

        return $this->success([
            'id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'avatar' => $user->avatar,
            'phone' => $user->phone,
            'email' => $user->email,
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip' => $user->last_login_ip,
            'roles' => $isAdmin ? $user->getRoleNames() : [],
            'permissions' => $isAdmin ? $user->getAllPermissions()->pluck('name') : [],
        ]);
    }

    /**
     * 更新个人资料（API 文档 3.2）
     * PUT /user/profile
     *
     * 唯一性校验按当前身份落到对应表（买家 users / 管理员 sys_user）。
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user instanceof SysUser;
        $table = $isAdmin ? 'sys_user' : 'users';

        $data = $request->validate([
            'nickname' => ['sometimes', 'nullable', 'string', 'max:64'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:512'],
            'email' => ['sometimes', 'nullable', 'email', 'max:128', "unique:{$table},email,{$user->id}"],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', "unique:{$table},phone,{$user->id}"],
        ]);

        $user->fill(collect($data)->filter(fn ($v) => $v !== null)->all());
        $user->save();

        $this->operationLog->record(
            $user->id,
            'user',
            'update_profile',
            $table,
            $user->id,
            $data,
            $isAdmin ? SysOperationLog::ACTOR_ADMIN : SysOperationLog::ACTOR_CUSTOMER,
        );

        return $this->success([
            'id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'avatar' => $user->avatar,
            'phone' => $user->phone,
            'email' => $user->email,
        ], '资料更新成功');
    }

    /**
     * 上传头像/图片（API 文档 11.5 独立上传接口约定）
     * POST /user/upload
     */
    public function upload(Request $request, FileUploadService $uploader)
    {
        $request->validate([
            'file' => ['required', 'file', 'image'],
        ]);

        $url = $uploader->uploadImage($request->file('file'), 'profile');

        return $this->success(['url' => $url], '上传成功');
    }
}
