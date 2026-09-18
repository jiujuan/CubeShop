<?php

namespace Database\Seeders;

use App\Models\SysUser;
use App\Support\AdminRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * P1 种子数据：权限码（对齐 API 文档 9）、角色、初始账号
 *
 * 初始账号：admin / Admin@123（超级管理员），operator / Operator@123（运营）
 * 客服角色 `cs_agent` 只建角色不建账号：客服账号由超管在「系统 → 管理员账号」按需创建。
 */
class RolePermissionSeeder extends Seeder
{
    /** 客服（cs_agent）权限清单：客服工作台 + 帮助中心，不含任何经营数据（CS-117 缺陷 #4） */
    public const CS_AGENT_PERMISSIONS = [
        'cs.ticket.view',
        'cs.ticket.handle',
        'cs.faq.manage',
    ];

    /**
     * API 文档 9 的权限码参考表
     */
    public const PERMISSIONS = [
        'product.view',
        'product.create',
        'product.update',
        'category.manage',
        'order.view',
        'order.ship',
        'order.export',
        'shipping.manage',
        'refund.view',
        'refund.process',
        'dashboard.view',
        'config.manage',
        'log.view',
        'user.manage',
        'address.view',
        'address.manage',
        // 支付与订单流水（后台 payment.view / payment.manage / order.log）
        'payment.view',
        'payment.manage',
        'order.log',
        // V1.1 新增权限码（T-017 / T-020 / T-022）
        'review.manage',
        'report.view',
        'account.manage',
        'role.manage',
        'inventory.manage',
        // V1.1 二期（T-032）营销管理：优惠券与满减活动
        'marketing.manage',
        // 公告管理（P-Announcement）：运营可自助发布/管理前台公告
        'announcement.manage',
        // 收银台与支付渠道（payment.channel.manage 仅超管，另两个运营也有）
        'payment.channel.manage',
        'payment.offline.review',
        'balance.recharge.view',
        // 客户服务中心（CS-103）：工单查看 / 工单处理 / 帮助中心维护
        'cs.ticket.view',
        'cs.ticket.handle',
        'cs.faq.manage',
    ];

    public function run(): void
    {
        // 重置权限缓存
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 角色：name 为英文标识（程序用），display_name 为中文名（展示用）
        // 注：V1.1 用户表拆分后买家独立成表（users）且不参与 spatie 权限体系，
        //     故不再存在 customer 角色。
        // 标识与中文名的唯一来源是 App\Support\AdminRole（新增角色只改那里 + 本类 + 迁移）。
        $superAdmin = Role::findOrCreate(AdminRole::SUPER_ADMIN, 'web');
        $operator = Role::findOrCreate(AdminRole::OPERATOR, 'web');
        $csAgent = Role::findOrCreate(AdminRole::CS_AGENT, 'web');

        foreach ([$superAdmin, $operator, $csAgent] as $role) {
            $displayName = AdminRole::LABELS[$role->name];
            if ($role->display_name !== $displayName) {
                $role->display_name = $displayName;
                $role->save();
            }
        }

        // 运营：除用户管理外的日常运营权限（地址仅可查看核对，代改需超管授权）
        // 账号/角色管理（account.manage / role.manage）为超管专属，运营默认不授予
        $operator->syncPermissions([
            'product.view', 'product.create', 'product.update', 'category.manage',
            'order.view', 'order.ship', 'order.export',
            // 物流管理（V1.1 二期 T-047）：快递字典维护与异常看板
            'shipping.manage',
            'refund.view', 'refund.process',
            'dashboard.view',
            'address.view',
            'review.manage', 'report.view', 'inventory.manage',
            // 营销管理（V1.1 二期 T-032）：运营可自助发券/建满减活动
            'marketing.manage',
            // 公告管理（P-Announcement）：运营可自助发布/管理前台公告
            'announcement.manage',
            // 支付只读（查看支付单/支付日志）+ 订单流水；关闭支付单需超管
            'payment.view', 'order.log',
            // 收银台：线下核账 + 充值单查看（渠道配置为超管专属）
            'payment.offline.review', 'balance.recharge.view',
            // ⚠️ 客服中心（cs.ticket.view / cs.ticket.handle / cs.faq.manage）**不授予 operator**：
            //    运营与客服为两条职责线，客服权限归 cs_agent 角色。
            //    迁移 2026_09_17_000039 曾误授予 operator 这三个权限，已由
            //    2026_09_17_000040_revoke_cs_permissions_from_operator.php 回收（存量库）。
            //    新工单通知按「持有 cs.ticket.view 权限的账号」投递，不写死角色名。
        ]);

        // 客服（cs_agent）：只做客服工作台与帮助中心，不含任何经营数据（CS-117 缺陷 #4）
        // 授权后新工单通知（按 cs.ticket.view 投递）会自动覆盖该角色
        $csAgent->syncPermissions(self::CS_AGENT_PERMISSIONS);

        // 超级管理员：全部权限
        $superAdmin->syncPermissions(self::PERMISSIONS);

        // 初始账号
        $admin = SysUser::withTrashed()->updateOrCreate(
            ['username' => 'admin'],
            [
                'password' => Hash::make('Admin@123'),
                'nickname' => '超级管理员',
                'status' => 1,
            ],
        );
        $admin->syncRoles([$superAdmin]);

        $operatorUser = SysUser::withTrashed()->updateOrCreate(
            ['username' => 'operator'],
            [
                'password' => Hash::make('Operator@123'),
                'nickname' => '运营账号',
                'status' => 1,
            ],
        );
        $operatorUser->syncRoles([$operator]);
    }
}
