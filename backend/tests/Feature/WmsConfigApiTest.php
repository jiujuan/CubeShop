<?php

use App\Models\ProductSku;
use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Models\Warehouse;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Models\WmsSkuMapping;
use App\Services\Common\CaptchaService;
use App\Services\Wms\WmsConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * 后台 WMS 对接配置接口（WMS 计划 P0 / §4.1）
 *
 * 覆盖：权限隔离、仓库 CRUD、配置读写与脱敏、留空不覆盖、连通性测试（成功/失败 + 落日志）、
 * SKU 解析（same/manual）、映射批量导入（部分失败不回滚）、删除、审计日志。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true, 'app.url' => 'https://shop.test']);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    // 客服角色：不持有 wms.config.manage，用于权限隔离断言
    $csAgent = SysUser::create([
        'username' => 'cs_wms_test',
        'password' => Hash::make('Cs@123456'),
        'nickname' => '客服',
        'status' => 1,
    ]);
    $csAgent->assignRole('cs_agent');
    $this->csAuth = ['Authorization' => 'Bearer '.$csAgent->createToken('cs')->plainTextToken];

    $this->warehouse = Warehouse::create([
        'code' => 'WH_API', 'name' => '接口测试仓', 'status' => 1,
    ]);
});

/** 配置载荷（合法基线） */
function wmsConfigPayload(array $override = []): array
{
    return array_merge([
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'app_key' => 'cn-app-key',
        'app_secret' => 'cn-app-secret',
        'access_token' => 'cn-access-token',
        'customer_id' => 'CUST-1',
        'owner_no' => 'OWNER-1',
        'warehouse_code' => 'CN-WH-1',
        'warehouse_no' => 'CN-WH-1-P',
        'api_env' => 'sandbox',
        'remark' => '联调环境',
    ], $override);
}

// ---------- 权限隔离 ----------

test('TC-WMS-001 无 wms.config.manage 权限访问 → 403', function () {
    $this->getJson('/api/admin/wms/warehouses', $this->csAuth)
        ->assertStatus(403)->assertJsonPath('code', 40003);

    $this->postJson('/api/admin/wms/warehouses', ['code' => 'X', 'name' => 'x', 'status' => 1], $this->csAuth)
        ->assertStatus(403);

    $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload(), $this->csAuth)
        ->assertStatus(403);
});

// ---------- 仓库 CRUD ----------

test('TC-WMS-002 仓库列表与新建（编码重复 → 409）', function () {
    $list = $this->getJson('/api/admin/wms/warehouses', $this->adminAuth)->json('data');
    expect(array_column($list['list'], 'code'))->toContain('WH_API')
        ->and($list['list'][0])->toHaveKey('wms');

    $created = $this->postJson('/api/admin/wms/warehouses', [
        'code' => 'WH_NEW', 'name' => '新仓库', 'status' => 1,
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
    ], $this->adminAuth)->assertOk()->json('data');
    expect($created['code'])->toBe('WH_NEW')->and($created['id'])->toBeGreaterThan(0);

    // 重名 → 40009/409
    $this->postJson('/api/admin/wms/warehouses', [
        'code' => 'WH_NEW', 'name' => '重复仓', 'status' => 1,
    ], $this->adminAuth)->assertStatus(409)->assertJsonPath('code', 40009);

    // 非法编码（含空格）→ 422
    $this->postJson('/api/admin/wms/warehouses', [
        'code' => 'BAD CODE', 'name' => 'x', 'status' => 1,
    ], $this->adminAuth)->assertStatus(422);
});

// ---------- 配置读写与脱敏 ----------

test('TC-WMS-003 保存配置后落库为密文，接口出口只有掩码', function () {
    $this->getJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", $this->adminAuth)
        ->assertOk()->assertJsonPath('data.configured', false)
        ->assertJsonPath('data.api_env', 'sandbox');

    $resp = $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload(), $this->adminAuth)
        ->assertOk()->json('data');

    expect($resp['configured'])->toBeTrue()
        ->and($resp['app_secret_masked'])->toBe('****cret')
        ->and($resp['has_app_secret'])->toBeTrue()
        ->and($resp['callback_url'])->toStartWith('https://shop.test/api/wms/callback/cainiao?token=');

    // 出口整体不含明文
    $raw = json_encode($resp);
    expect($raw)->not->toContain('cn-app-secret')
        ->and($raw)->not->toContain('cn-access-token');

    // 库内存的是密文
    $stored = DB::table('wms_configs')->where('warehouse_id', $this->warehouse->id)->first();
    expect($stored->app_secret_enc)->not->toBe('cn-app-secret');

    // provider 非法值 → 422
    $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload(['provider' => 'bad']), $this->adminAuth)
        ->assertStatus(422);
});

test('TC-WMS-004 二次保存留空凭证 → 原值保留', function () {
    $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload(), $this->adminAuth)->assertOk();

    $before = DB::table('wms_configs')->where('warehouse_id', $this->warehouse->id)->value('app_secret_enc');

    // 留空 app_secret / access_token 再保存
    $resp = $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload([
        'app_secret' => '', 'access_token' => null, 'remark' => '改了备注',
    ]), $this->adminAuth)->assertOk()->json('data');

    $after = DB::table('wms_configs')->where('warehouse_id', $this->warehouse->id)->value('app_secret_enc');

    expect($after)->toBe($before)
        ->and($resp['has_app_secret'])->toBeTrue()
        ->and($resp['remark'])->toBe('改了备注');
});

test('TC-WMS-005 京东云仓保存 → 400 提示 P8', function () {
    $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload(['provider' => 'jd_cloud']), $this->adminAuth)
        ->assertStatus(400)
        ->assertJsonPath('code', 40000);

    expect(WmsConfig::where('warehouse_id', $this->warehouse->id)->exists())->toBeFalse();
});

// ---------- 连通性测试 ----------

test('TC-WMS-006 未配置真实凭证时连通性测试走 Mock 并落 wms_api_logs', function () {
    // P2 起「有凭证走真实网关」：这里显式清掉凭证，验证的才是 Mock 兜底路径
    $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload([
        'app_key' => null, 'app_secret' => '',
    ]), $this->adminAuth)->assertOk();

    $resp = $this->postJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config/test", [], $this->adminAuth)
        ->assertOk()->json('data');

    expect($resp['success'])->toBeTrue()
        ->and($resp['mock'])->toBeTrue()
        ->and($resp['provider'])->toBe('mock')
        ->and($resp['message'])->toContain('Mock')
        ->and($resp['request_id'])->not->toBeNull();

    $log = WmsApiLog::where('direction', 'outbound')->where('api_name', 'queryInventory')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->success)->toBeTrue()
        ->and($log->provider)->toBe('cainiao')
        ->and($log->request_body)->toBeArray();
});

test('TC-WMS-006B 沙箱填了真实凭证即改走真实网关，缺网关地址时 fail-closed（不静默 Mock）', function () {
    // 沙箱网关地址未配置（测试环境本就没有该 env），凭证齐备 → 必须走 CainiaoAdapter 并被拒绝调用
    config(['wms.providers.cainiao.gateway.sandbox' => null]);

    $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload(), $this->adminAuth)->assertOk();

    $resp = $this->postJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config/test", [], $this->adminAuth)
        ->assertOk()->json('data');

    expect($resp['success'])->toBeFalse()
        // mock 为 null = 根本没走 Mock 适配器，这正是 F9 要锁定的行为
        ->and($resp['mock'])->toBeNull()
        ->and($resp['error'])->toContain('网关');

    $log = WmsApiLog::latest('id')->first();
    expect($log->success)->toBeFalse()
        ->and($log->error_msg)->toContain('网关');
});

test('TC-WMS-007 生产环境缺密钥时连通性测试 fail-closed 并落失败日志', function () {
    $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload([
        'api_env' => 'prod', 'app_key' => null, 'app_secret' => '',
    ]), $this->adminAuth)->assertOk();

    $resp = $this->postJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config/test", [], $this->adminAuth)
        ->assertOk()->json('data');

    expect($resp['success'])->toBeFalse()
        ->and($resp['error'])->toContain('生产环境');

    $log = WmsApiLog::latest('id')->first();
    expect($log->success)->toBeFalse()
        ->and($log->error_msg)->toContain('生产环境');
});

test('TC-WMS-008 未配置 WMS 的仓库测试连通性 → 404', function () {
    $other = Warehouse::create(['code' => 'WH_NOCFG', 'name' => '无配置仓', 'status' => 1]);

    $this->postJson("/api/admin/wms/warehouses/{$other->id}/config/test", [], $this->adminAuth)
        ->assertStatus(404)->assertJsonPath('code', 40004);
});

// ---------- SKU 解析 ----------

test('TC-WMS-009 same 模式回落平台 sku_code，manual 模式缺映射抛 40009', function () {
    $sku = createTestSku(stock: 10, price: '10.00');
    $config = app(WmsConfigService::class)->save($this->warehouse->id, wmsConfigPayload(), 1);

    // same：直接返回平台 sku_code
    expect(app(WmsConfigService::class)->resolveSkuCode($config, $sku->id))->toBe((string) $sku->sku_code);

    // manual 且无映射 → 409/40009
    $config = app(WmsConfigService::class)->save($this->warehouse->id, wmsConfigPayload(['sku_mapping_mode' => 'manual']), 1);
    expect(fn () => app(WmsConfigService::class)->resolveSkuCode($config, $sku->id))
        ->toThrow(App\Exceptions\BusinessException::class);

    // 有映射后命中
    WmsSkuMapping::create([
        'warehouse_id' => $this->warehouse->id, 'sku_id' => $sku->id,
        'platform_sku_code' => $sku->sku_code, 'wms_sku_code' => 'WMS-ITEM-9', 'status' => 1,
    ]);
    expect(app(WmsConfigService::class)->resolveSkuCode($config, $sku->id))->toBe('WMS-ITEM-9');
});

// ---------- SKU 映射管理 ----------

test('TC-WMS-010 映射批量导入：逐行反馈，非法行不影响合法行', function () {
    $skuA = createTestSku(stock: 5, price: '10.00');
    $skuB = createTestSku(stock: 5, price: '20.00');

    $resp = $this->postJson("/api/admin/wms/warehouses/{$this->warehouse->id}/sku-mappings/batch", [
        'rows' => [
            ['sku_code' => $skuA->sku_code, 'wms_sku_code' => 'W-A', 'barcode' => '6901'],
            ['sku_code' => 'NOT-EXIST-CODE', 'wms_sku_code' => 'W-X'],
            ['sku_code' => $skuB->sku_code, 'wms_sku_code' => 'W-B'],
        ],
    ], $this->adminAuth)->assertOk()->json('data');

    expect($resp['total'])->toBe(3)
        ->and($resp['success_count'])->toBe(2)
        ->and($resp['failed_count'])->toBe(1)
        ->and($resp['results'][1]['success'])->toBeFalse()
        ->and($resp['results'][1]['line'])->toBe(2)
        ->and($resp['results'][1]['message'])->toContain('不存在');

    expect(WmsSkuMapping::where('warehouse_id', $this->warehouse->id)->count())->toBe(2);

    // 重复导入同 sku → 覆盖而非新增（unique(warehouse_id, sku_id)）
    $this->postJson("/api/admin/wms/warehouses/{$this->warehouse->id}/sku-mappings/batch", [
        'rows' => [['sku_code' => $skuA->sku_code, 'wms_sku_code' => 'W-A2']],
    ], $this->adminAuth)->assertOk();

    expect(WmsSkuMapping::where('warehouse_id', $this->warehouse->id)->count())->toBe(2)
        ->and(WmsSkuMapping::where('warehouse_id', $this->warehouse->id)->where('sku_id', $skuA->id)->value('wms_sku_code'))
        ->toBe('W-A2');
});

test('TC-WMS-011 映射列表返回商品信息；删除映射按 sku public_id', function () {
    $sku = createTestSku(stock: 5, price: '10.00');
    $this->postJson("/api/admin/wms/warehouses/{$this->warehouse->id}/sku-mappings/batch", [
        'rows' => [['sku_code' => $sku->sku_code, 'wms_sku_code' => 'W-DEL']],
    ], $this->adminAuth)->assertOk();

    $list = $this->getJson("/api/admin/wms/warehouses/{$this->warehouse->id}/sku-mappings", $this->adminAuth)->json('data');
    expect($list['pagination']['total'])->toBe(1)
        ->and($list['list'][0]['wms_sku_code'])->toBe('W-DEL')
        ->and($list['list'][0]['product_title'])->toBe($sku->product->title)
        ->and($list['list'][0]['sku_id'])->toBe($sku->public_id);

    // 关键词筛选
    $hit = $this->getJson("/api/admin/wms/warehouses/{$this->warehouse->id}/sku-mappings?keyword=W-DEL", $this->adminAuth)->json('data');
    expect($hit['pagination']['total'])->toBe(1);

    // 删除（前端传 public_id）
    $this->deleteJson("/api/admin/wms/warehouses/{$this->warehouse->id}/sku-mappings/{$sku->public_id}", [], $this->adminAuth)
        ->assertOk();

    expect(WmsSkuMapping::where('warehouse_id', $this->warehouse->id)->count())->toBe(0);
});

// ---------- 审计 ----------

test('TC-WMS-012 写操作落 sys_operation_log（module=wms）', function () {
    $this->postJson('/api/admin/wms/warehouses', ['code' => 'WH_LOG', 'name' => '审计仓', 'status' => 1], $this->adminAuth)->assertOk();
    $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload(), $this->adminAuth)->assertOk();

    $actions = SysOperationLog::where('module', 'wms')->pluck('action')->all();

    expect($actions)->toContain('warehouse_created')
        ->toContain('config_created');

    // 变更日志不含凭证明文
    $log = SysOperationLog::where('module', 'wms')->where('action', 'config_created')->first();
    expect((string) $log->content)->not->toContain('cn-app-secret')
        ->and($log->actor_type)->toBe(SysOperationLog::ACTOR_ADMIN);

    // 再次保存 → config_saved（而非 created）
    $this->putJson("/api/admin/wms/warehouses/{$this->warehouse->id}/config", wmsConfigPayload(), $this->adminAuth)->assertOk();
    expect(SysOperationLog::where('module', 'wms')->pluck('action')->all())->toContain('config_saved');
});

// ---------- Seeder ----------

test('TC-WMS-013 WarehouseSeeder 幂等预置默认仓', function () {
    $this->seed(Database\Seeders\WarehouseSeeder::class);
    $this->seed(Database\Seeders\WarehouseSeeder::class);

    expect(Warehouse::where('code', 'WH_DEFAULT')->count())->toBe(1)
        ->and(Warehouse::where('code', 'WH_DEFAULT')->value('status'))->toBe(1);
});

// ---------- 权限码落地 ----------

test('TC-WMS-014 超管与运营均持有 wms 权限码，客服不持有', function () {
    $superAdmin = SysUser::where('username', 'admin')->first();
    $operator = SysUser::where('username', 'operator')->first();

    foreach (['wms.config.manage', 'wms.order.view', 'wms.order.manage', 'wms.return.manage'] as $permission) {
        expect($superAdmin->hasPermissionTo($permission))->toBeTrue()
            ->and($operator->hasPermissionTo($permission))->toBeTrue();
    }

    expect(SysUser::where('username', 'cs_wms_test')->first()->hasPermissionTo('wms.config.manage'))->toBeFalse();
});
