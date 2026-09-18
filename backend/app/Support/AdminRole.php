<?php

namespace App\Support;

/**
 * 后台角色的**唯一标识来源**（CS-117 缺陷 #4）
 *
 * 背景：角色相关信息原先散落在三处 —— `RolePermissionSeeder`（创建角色）、
 * `Admin\AccountController::ADMIN_ROLES`（可分配角色白名单 + 列表过滤）、
 * `Admin\RoleController::BUILTIN_ROLES`（不可删除角色）—— 新增角色时漏改任一处，
 * 就会出现「Seeder 建了角色但后台分配不到」这类不一致（与 CS-103 权限双路径问题同源）。
 *
 * 约定：
 * - 角色英文标识（name）与中文名（display_name）在此定义，其余位置一律引用本类；
 *   权限码仍以 `RolePermissionSeeder::PERMISSIONS` 为准。
 * - 新增内置角色时：本类 + Seeder（`RolePermissionSeeder`）+ 幂等迁移（存量库）三处同改，
 *   并由 `tests/Feature/CsPermissionSyncTest.php` 锁定两条路径结果一致。
 */
final class AdminRole
{
    /** 超级管理员：全部权限，Gate::before 直接放行 */
    public const SUPER_ADMIN = 'super_admin';

    /** 运营：商品/订单/退款/营销等日常运营权限 */
    public const OPERATOR = 'operator';

    /** 客服（CS-117 缺陷 #4）：只做客服工作台与帮助中心，不含经营数据 */
    public const CS_AGENT = 'cs_agent';

    /** 后台内置角色：账号管理可分配、角色管理不可重命名/删除 */
    public const BUILTIN = [
        self::SUPER_ADMIN,
        self::OPERATOR,
        self::CS_AGENT,
    ];

    /** 中文名（写入 roles.display_name，后台展示用） */
    public const LABELS = [
        self::SUPER_ADMIN => '超级管理员',
        self::OPERATOR => '运营',
        self::CS_AGENT => '客服',
    ];
}
