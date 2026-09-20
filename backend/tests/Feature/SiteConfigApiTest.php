<?php

use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * 站点配置（P-SiteConfig）
 *
 * 覆盖：配置分组返回与聚拢顺序、公开站点信息默认值、保存后即时生效（缓存失效）、
 *       logo 上传落 site 目录、清空配置（空串）、校验与鉴权分支。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $this->adminAuth = ['Authorization' => 'Bearer '.siteConfigLoginToken('admin', 'Admin@123')];
    $this->operatorAuth = ['Authorization' => 'Bearer '.siteConfigLoginToken('operator', 'Operator@123')];
});

/**
 * 取登录 token（每次重新生成验证码，避免复用被消费）
 *
 * ⚠️ 函数名不可叫 loginToken：AccountRoleApiTest 已定义同名全局函数，
 * 全量跑会因重复声明直接 fatal；且单文件运行时拿不到别的测试文件的函数，故各自定义。
 */
function siteConfigLoginToken(string $username, string $password): string
{
    $cap = app(CaptchaService::class)->generate();

    return test()->postJson('/api/auth/login', [
        'username' => $username,
        'password' => $password,
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token');
}

test('TC-CFG-001 配置列表带分组标签，按 Tab 顺序聚拢且站点信息置首', function () {
    $res = $this->getJson('/api/admin/configs', $this->adminAuth);
    $res->assertOk()->assertJsonPath('code', 0);

    $list = collect($res->json('data'));

    // site.* 三项归入「站点信息」
    expect($list->where('group', '站点信息')->pluck('config_key')->all())
        ->toContain('site.name', 'site.logo', 'site.logo_small');

    // payment.* 归入「支付与充值」，不得落到兜底分组
    expect($list->where('group', '支付与充值')->pluck('config_key')->all())
        ->toContain('payment.default_channel');

    // 所有前缀均已登记：不应有任何配置落到「其它设置」（防新增前缀漏登记）
    expect($list->where('group', '其它设置'))->toBeEmpty();

    // 同组条目连续出现（不交错），且首次出现的分组顺序 = Tab 顺序
    $encounter = [];
    $prev = null;
    foreach ($list->all() as $item) {
        if ($item['group'] !== $prev) {
            $encounter[] = $item['group'];
            $prev = $item['group'];
        }
    }
    expect($encounter)->toBe(array_values(array_unique($list->pluck('group')->all())))
        ->and($encounter[0])->toBe('站点信息');
});

test('TC-CFG-002 公开站点信息无需登录，未配置时回落默认值', function () {
    $this->getJson('/api/site/config')
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('data.name', 'CubeShop')
        ->assertJsonPath('data.logo', '')
        ->assertJsonPath('data.logo_small', '');
});

test('TC-CFG-003 保存站点名称后公开接口即时生效（缓存已失效）', function () {
    // 先读一次，确保配置缓存已填充
    $this->getJson('/api/site/config')->assertJsonPath('data.name', 'CubeShop');

    $this->putJson('/api/admin/configs', [
        'configs' => [['config_key' => 'site.name', 'config_value' => '星尘商城']],
    ], $this->adminAuth)->assertOk()->assertJsonPath('code', 0);

    $this->getJson('/api/site/config')->assertJsonPath('data.name', '星尘商城');
});

test('TC-CFG-004 logo 上传落在 site 目录，可写入配置并下发前台', function () {
    Storage::fake('public');

    $up = $this->postJson('/api/admin/configs/upload', [
        'file' => UploadedFile::fake()->image('logo.png', 120, 40),
    ], $this->adminAuth)->assertOk();

    $url = $up->json('data.url');
    expect($url)->toContain('/storage/uploads/site/');

    $this->putJson('/api/admin/configs', [
        'configs' => [
            ['config_key' => 'site.logo', 'config_value' => $url],
            ['config_key' => 'site.logo_small', 'config_value' => $url],
        ],
    ], $this->adminAuth)->assertOk();

    $this->getJson('/api/site/config')
        ->assertJsonPath('data.logo', $url)
        ->assertJsonPath('data.logo_small', $url);
});

test('TC-CFG-005 空串可提交（用于清空已上传 logo）', function () {
    $this->putJson('/api/admin/configs', [
        'configs' => [['config_key' => 'site.logo', 'config_value' => '']],
    ], $this->adminAuth)->assertOk();

    $this->getJson('/api/site/config')->assertJsonPath('data.logo', '');
});

test('TC-CFG-006 上传校验：非图片与超限被拒', function () {
    Storage::fake('public');

    $this->postJson('/api/admin/configs/upload', [
        'file' => UploadedFile::fake()->create('a.txt', 10, 'text/plain'),
    ], $this->adminAuth)->assertStatus(422);

    $this->postJson('/api/admin/configs/upload', [
        'file' => UploadedFile::fake()->image('big.png')->size(6000), // 6000KB > 5MB
    ], $this->adminAuth)->assertStatus(422);
});

test('TC-CFG-007 鉴权：未登录 401，运营无 config.manage 返回 403', function () {
    $this->getJson('/api/admin/configs')->assertStatus(401);
    $this->putJson('/api/admin/configs', [
        'configs' => [['config_key' => 'site.name', 'config_value' => 'x']],
    ])->assertStatus(401);

    $this->getJson('/api/admin/configs', $this->operatorAuth)->assertStatus(403);
    $this->postJson('/api/admin/configs/upload', [
        'file' => UploadedFile::fake()->image('logo.png', 10, 10),
    ], $this->operatorAuth)->assertStatus(403);
});

test('TC-CFG-008 更新校验：未知键与缺失值均 422', function () {
    $this->putJson('/api/admin/configs', [
        'configs' => [['config_key' => 'not.exist', 'config_value' => 'x']],
    ], $this->adminAuth)->assertStatus(422);

    $this->putJson('/api/admin/configs', [
        'configs' => [['config_key' => 'site.name']],
    ], $this->adminAuth)->assertStatus(422);
});
