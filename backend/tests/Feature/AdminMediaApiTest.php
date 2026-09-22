<?php

use App\Models\MediaFile;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * 媒体库接口（图片资产治理 P2）集成测试
 *
 * 鉴权口径：admin（super_admin，持有 media.*）走成功路径；
 *           operator 持 media.view / media.upload，**不持 media.manage**
 *           —— 用于校验「运营能上传、能复用，但不能替换/删除」这一分层。
 */
beforeEach(function () {
    seedRoles();
    Storage::fake('public');

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $capOp = app(CaptchaService::class)->generate();
    $this->operatorAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'operator', 'password' => 'Operator@123',
        'captcha_id' => $capOp['captcha_id'], 'captcha_code' => $capOp['debug_code'],
    ])->json('data.token')];
});

/** 造一张登记表（不落真实文件，除非显式给 contents） */
function mediaRecord(array $attrs = [], ?string $contents = 'binary'): MediaFile
{
    $path = $attrs['path'] ?? 'uploads/test/'.uniqid().'.png';
    if ($contents !== null) {
        Storage::disk('public')->put($path, $contents);
    }

    return MediaFile::create(array_merge([
        'disk' => 'public',
        'path' => $path,
        'mime' => 'image/png',
        'size' => strlen((string) $contents),
        'original_name' => '测试图.png',
        'module' => 'products',
        'usage_count' => 0,
    ], $attrs));
}

it('分页检索：默认返回列表与模块字典', function () {
    mediaRecord(['original_name' => '主图A.png']);
    mediaRecord(['original_name' => '主图B.png', 'module' => 'banners']);

    $this->withHeaders($this->adminAuth)->getJson('/api/admin/media')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 2)
        ->assertJsonCount(2, 'data.list')
        ->assertJsonPath('data.modules', ['banners', 'products']);
});

it('筛选：按模块 / 按未使用 / 按最小体积', function () {
    mediaRecord(['module' => 'products', 'usage_count' => 3, 'size' => 100]);
    mediaRecord(['module' => 'banners', 'usage_count' => 0, 'size' => 5000]);

    $auth = $this->adminAuth;

    $this->withHeaders($auth)->getJson('/api/admin/media?module=banners')
        ->assertOk()->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.list.0.module', 'banners');

    $this->withHeaders($auth)->getJson('/api/admin/media?unused=1')
        ->assertOk()->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.list.0.usage_count', 0);

    $this->withHeaders($auth)->getJson('/api/admin/media?size_from=1000')
        ->assertOk()->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.list.0.size', 5000);
});

it('筛选：keyword 同时命中文件名与路径', function () {
    mediaRecord(['original_name' => '双十一主图.png', 'path' => 'uploads/products/20260101/a.png']);
    mediaRecord(['original_name' => '别的.png', 'path' => 'uploads/banners/20260101/b.png']);

    $auth = $this->adminAuth;

    $this->withHeaders($auth)->getJson('/api/admin/media?keyword='.urlencode('双十一'))
        ->assertOk()->assertJsonPath('data.pagination.total', 1);

    $this->withHeaders($auth)->getJson('/api/admin/media?keyword=banners')
        ->assertOk()->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.list.0.module', 'products');
});

it('排序：largest 按体积倒序', function () {
    mediaRecord(['size' => 100]);
    mediaRecord(['size' => 900]);

    $this->withHeaders($this->adminAuth)->getJson('/api/admin/media?sort=largest')
        ->assertOk()
        ->assertJsonPath('data.list.0.size', 900);
});

it('上传：登记成功并返回相对路径与绝对 URL', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/media', [
        'file' => UploadedFile::fake()->image('商品图.png', 8, 8),
        'module' => 'products',
    ]);

    $res->assertCreated()
        ->assertJsonPath('data.reused', false)
        ->assertJsonPath('data.media.module', 'products')
        ->assertJsonPath('data.media.original_name', '商品图.png');

    expect($res->json('data.path'))->toStartWith('uploads/products/')
        ->and($res->json('data.url'))->toContain('/storage/uploads/products/')
        ->and(MediaFile::count())->toBe(1);
});

it('上传：内容重复时复用已有文件，不产生第二份磁盘文件', function () {
    $file = UploadedFile::fake()->image('same.png', 8, 8);
    $bytes = (string) file_get_contents($file->getRealPath());

    $this->withHeaders($this->adminAuth)->postJson('/api/admin/media', [
        'file' => UploadedFile::fake()->createWithContent('same.png', $bytes),
        'module' => 'products',
    ])->assertCreated()->assertJsonPath('data.reused', false);

    $second = $this->withHeaders($this->adminAuth)->postJson('/api/admin/media', [
        'file' => UploadedFile::fake()->createWithContent('copy.png', $bytes),
        'module' => 'products',
    ]);

    $second->assertCreated()->assertJsonPath('data.reused', true);

    // 复用 => 只有一条登记，且 path 与第一次一致
    expect(MediaFile::count())->toBe(1)
        ->and($second->json('data.path'))->toBe($second->json('data.media.path'))
        ->and(MediaFile::first()->path)->toBe($second->json('data.path'));
});

it('更新：改名与换模块（权限 media.manage）', function () {
    $media = mediaRecord(['original_name' => '旧名.png', 'module' => 'products']);

    $this->withHeaders($this->adminAuth)->patchJson('/api/admin/media/'.$media->id, [
        'original_name' => '新名.png',
        'module' => 'banners',
    ])->assertOk()
        ->assertJsonPath('data.original_name', '新名.png')
        ->assertJsonPath('data.module', 'banners');
});

it('替换：文件内容变了但 path 不变（换图不破引用）', function () {
    $media = mediaRecord(['path' => 'uploads/products/20260101/keep.png', 'contents' => 'old-bytes']);
    $oldMd5 = $media->md5;

    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/media/'.$media->id.'/replace', [
        'file' => UploadedFile::fake()->image('new.png', 12, 12),
    ]);

    $res->assertOk()
        ->assertJsonPath('data.path', 'uploads/products/20260101/keep.png')
        ->assertJsonPath('data.url', $res->json('data.url'));

    $fresh = $media->fresh();
    expect($fresh->path)->toBe('uploads/products/20260101/keep.png')
        ->and($fresh->md5)->not->toBe($oldMd5)
        ->and($fresh->original_name)->toBe('new.png')
        // 磁盘上就是新内容
        ->and(Storage::disk('public')->get('uploads/products/20260101/keep.png'))->not->toBe('old-bytes');
});

it('删除：无引用时软删，物理文件仍在盘上', function () {
    $media = mediaRecord(['path' => 'uploads/products/20260101/x.png', 'contents' => 'keep-me']);

    $this->withHeaders($this->adminAuth)->deleteJson('/api/admin/media/'.$media->id)
        ->assertOk()->assertJsonPath('message', '已删除（30 天内可恢复，物理文件暂留）');

    expect(MediaFile::count())->toBe(0)
        ->and(MediaFile::onlyTrashed()->count())->toBe(1)
        ->and(Storage::disk('public')->exists('uploads/products/20260101/x.png'))->toBeTrue();
});

it('删除：仍被引用时拒绝', function () {
    $media = mediaRecord(['usage_count' => 2]);

    $res = $this->withHeaders($this->adminAuth)->deleteJson('/api/admin/media/'.$media->id)->assertOk();

    expect($res->json('code'))->not->toBe(0)
        ->and($res->json('message'))->toContain('仍被 2 处引用')
        ->and(MediaFile::count())->toBe(1);
});

it('权限分层：operator 可浏览与上传，但不能替换/删除', function () {
    $media = mediaRecord();

    $this->withHeaders($this->operatorAuth)->getJson('/api/admin/media')->assertOk();

    $this->withHeaders($this->operatorAuth)->postJson('/api/admin/media', [
        'file' => UploadedFile::fake()->image('op.png', 8, 8),
    ])->assertCreated();

    $this->withHeaders($this->operatorAuth)->patchJson('/api/admin/media/'.$media->id, [
        'original_name' => 'hack.png',
    ])->assertForbidden();

    $this->withHeaders($this->operatorAuth)->deleteJson('/api/admin/media/'.$media->id)->assertForbidden();
});

it('未登录访问媒体库返回 401', function () {
    $this->getJson('/api/admin/media')->assertUnauthorized();
});
