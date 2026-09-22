<?php

use App\Models\Brand;
use App\Models\CsFaqArticle;
use App\Models\MediaFile;
use App\Models\Product;
use App\Models\Review;
use App\Services\Common\FileUploadService;
use App\Services\Common\MediaRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * 造一张内容固定的 PNG（同一个字节序列上传两次 → md5 相同 → 应当去重）
 */
function sameBytes(): string
{
    // 必须留着引用：UploadedFile 被回收会连带删掉它的临时文件
    $fake = UploadedFile::fake()->image('src.png', 8, 8);

    return (string) file_get_contents($fake->getRealPath());
}

beforeEach(function () {
    Storage::fake('public');
});

describe('上传登记与 md5 去重', function () {
    it('首次上传登记入 media_files，第二次同内容复用同一文件', function () {
        $bytes = sameBytes();
        $first = tmpCopy($bytes);
        $second = tmpCopy($bytes);

        $service = app(FileUploadService::class);

        $path = $service->uploadImagePath(new UploadedFile($first, 'a.png', 'image/png', null, true), 'products', 7);

        expect($path)->toStartWith('uploads/products/')
            ->and(MediaFile::count())->toBe(1);

        Storage::disk('public')->assertExists($path);
        $filesBefore = count(Storage::disk('public')->allFiles());

        $again = $service->uploadImagePath(new UploadedFile($second, 'b.png', 'image/png', null, true), 'products', 9);

        // 复用既有路径：磁盘文件数不增加，登记表也不多一条
        expect($again)->toBe($path)
            ->and(MediaFile::count())->toBe(1)
            ->and(count(Storage::disk('public')->allFiles()))->toBe($filesBefore);

        $media = MediaFile::first();

        expect($media->md5)->toBe(md5($bytes))
            ->and($media->original_name)->toBe('a.png')
            ->and($media->module)->toBe('products')
            ->and($media->uploaded_by)->toBe(7)
            ->and((int) $media->width)->toBe(8);
    });

    it('凭证上传不进媒体库（敏感单据不给后台浏览）', function () {
        $file = UploadedFile::fake()->image('voucher.png', 5, 5);

        app(FileUploadService::class)->uploadVoucher($file, 1);

        expect(MediaFile::count())->toBe(0);
    });
});

describe('引用计数扫描', function () {
    it('覆盖单值列 / JSON 数组 / 富文本内联 / 系统配置四类来源', function () {
        MediaFile::create(['path' => 'uploads/products/p.png', 'module' => 'product']);
        MediaFile::create(['path' => 'uploads/reviews/r.png', 'module' => 'review']);
        MediaFile::create(['path' => 'uploads/cms/c.png', 'module' => 'cms']);
        MediaFile::create(['path' => 'uploads/site/logo.png', 'module' => 'site']);

        $product = Product::create(['title' => '带图商品', 'price' => 9.9, 'main_image' => 'uploads/products/p.png']);
        Review::create([
            'order_id' => 1, 'order_item_id' => 9001, 'user_id' => 1, 'product_id' => $product->id,
            'rating' => 5, 'content' => 'ok', 'images' => ['uploads/reviews/r.png'],
        ]);
        $category = \App\Models\CsFaqCategory::create(['name' => '帮助栏目', 'sort' => 1, 'is_active' => true]);
        CsFaqArticle::create([
            'category_id' => $category->id,
            'title' => '正文带图', 'status' => CsFaqArticle::STATUS_PUBLISHED,
            'content_md' => '![](/storage/uploads/cms/c.png)',
        ]);
        \App\Models\SystemConfig::updateOrCreate(
            ['config_key' => 'site.logo'],
            ['config_value' => 'uploads/site/logo.png'],
        );

        $counts = app(MediaRegistry::class)->recomputeUsage();

        expect($counts['uploads/products/p.png'])->toBe(1)
            ->and($counts['uploads/reviews/r.png'])->toBe(1)
            // content_md（源）与 content（渲染产物）各算一次，同图两列引用 = 2
            ->and($counts['uploads/cms/c.png'])->toBe(2)
            ->and($counts['uploads/site/logo.png'])->toBe(1);
    });

    it('全量扫描把没人用的记录 usage_count 清零', function () {
        MediaFile::create(['path' => 'uploads/orphan.png', 'usage_count' => 5]);

        app(MediaRegistry::class)->recomputeUsage();

        expect(MediaFile::where('path', 'uploads/orphan.png')->value('usage_count'))->toBe(0);
    });
});

describe('删除联动：软删进 30 天回收窗口', function () {
    it('删除商品后它引用的图片被软删，但文件仍留在盘上', function () {
        MediaFile::create(['path' => 'uploads/products/p.png']);
        Storage::disk('public')->put('uploads/products/p.png', 'binary');

        $product = Product::create(['title' => '待删商品', 'price' => 9.9, 'main_image' => 'uploads/products/p.png']);
        $product->delete();

        expect(MediaFile::query()->count())->toBe(0)
            ->and(MediaFile::onlyTrashed()->count())->toBe(1);

        // 软删 ≠ 删除：物理文件还在，30 天窗口里随时可恢复
        Storage::disk('public')->assertExists('uploads/products/p.png');
    });

    it('仍被引用的图不会被软删（多点引用只去掉一处）', function () {
        MediaFile::create(['path' => 'uploads/brands/shared.png']);

        $brand = Brand::create(['name' => '品牌A', 'logo' => 'uploads/brands/shared.png']);
        Product::create(['title' => '同图商品', 'price' => 9.9, 'main_image' => 'uploads/brands/shared.png']);

        $brand->delete();

        expect(MediaFile::query()->count())->toBe(1)
            ->and(MediaFile::first()->usage_count)->toBe(1);
    });

    it('换图后旧图进入回收窗口，新图不受影响', function () {
        MediaFile::create(['path' => 'uploads/brands/old.png']);
        MediaFile::create(['path' => 'uploads/brands/new.png']);

        $brand = Brand::create(['name' => '换 logo 的品牌', 'logo' => 'uploads/brands/old.png']);
        $brand->update(['logo' => 'uploads/brands/new.png']);

        expect(MediaFile::onlyTrashed()->pluck('path')->all())->toBe(['uploads/brands/old.png'])
            // 新图不受牵连；局部重算只动旧图，全量扫描后新图才是 1
            ->and(MediaFile::where('path', 'uploads/brands/new.png')->sole()->trashed())->toBeFalse()
            ->and(app(MediaRegistry::class)->recomputeUsage()['uploads/brands/new.png'])->toBe(1);
    });

    it('重新被引用时自动离开回收窗口（自愈）', function () {
        MediaFile::create(['path' => 'uploads/brands/again.png']);

        $brand = Brand::create(['name' => '删了又用的品牌', 'logo' => 'uploads/brands/again.png']);
        $brand->delete();
        expect(MediaFile::onlyTrashed()->count())->toBe(1);

        Product::create(['title' => '复用这张图', 'price' => 9.9, 'main_image' => 'uploads/brands/again.png']);
        app(MediaRegistry::class)->recomputeUsage();

        expect(MediaFile::onlyTrashed()->count())->toBe(0)
            ->and(MediaFile::where('path', 'uploads/brands/again.png')->value('usage_count'))->toBe(1);
    });
});

describe('回收命令', function () {
    it('media:scan 把磁盘上的存量文件登记进表', function () {
        Storage::disk('public')->put('uploads/products/legacy.png', 'binary');

        $this->artisan('media:scan')->assertExitCode(0);

        expect(MediaFile::where('path', 'uploads/products/legacy.png')->exists())->toBeTrue();
    });

    it('未到期只通知不删除，满窗口才在 force 下真删', function () {
        $media = MediaFile::create(['path' => 'uploads/products/old.png']);
        Storage::disk('public')->put('uploads/products/old.png', 'binary');
        $media->delete();

        // 第 10 天：还没到 30 天窗口
        $this->travel(10)->days();
        $this->artisan('media:prune')->assertExitCode(0);

        Storage::disk('public')->assertExists('uploads/products/old.png');

        // 满 31 天：默认仍然只是通知
        $this->travel(21)->days();
        $this->artisan('media:prune')->assertExitCode(0);
        Storage::disk('public')->assertExists('uploads/products/old.png');

        // 显式 force 才真删
        $this->artisan('media:prune', ['--force' => true, '--yes' => true])->assertExitCode(0);

        Storage::disk('public')->assertMissing('uploads/products/old.png');
        expect(MediaFile::withTrashed()->count())->toBe(0);
    });

    it('dry-run 只预演，不动任何东西', function () {
        $media = MediaFile::create(['path' => 'uploads/products/dry.png']);
        Storage::disk('public')->put('uploads/products/dry.png', 'binary');
        $media->delete();

        $this->travel(31)->days();
        $this->artisan('media:prune', ['--dry-run' => true])->assertExitCode(0);

        Storage::disk('public')->assertExists('uploads/products/dry.png');
        expect(MediaFile::withTrashed()->count())->toBe(1);
    });

    it('--days=0 不被回落到默认 30 天（0 是有效值）', function () {
        $media = MediaFile::create(['path' => 'uploads/products/today.png']);
        Storage::disk('public')->put('uploads/products/today.png', 'binary');
        $media->delete();

        // 当天就应该出现在到期清单里，否则说明 --days=0 被 `?:` 当成了「没传」
        $this->artisan('media:prune', ['--dry-run' => true, '--days' => '0'])
            ->assertExitCode(0)
            ->expectsOutputToContain('将被物理删除')
            ->expectsOutputToContain('uploads/products/today.png');

        $this->travel(31)->days();
        $this->artisan('media:prune', ['--force' => true, '--yes' => true, '--days' => '0'])
            ->assertExitCode(0);

        Storage::disk('public')->assertMissing('uploads/products/today.png');
    });

    it('有引用的记录永远不会被回收，即使超过 30 天', function () {
        MediaFile::create(['path' => 'uploads/products/used.png']);
        $product = Product::create(['title' => '在用商品', 'price' => 9.9, 'main_image' => 'uploads/products/used.png']);
        expect($product->exists)->toBeTrue();

        Storage::disk('public')->put('uploads/products/used.png', 'binary');

        $this->travel(60)->days();
        $this->artisan('media:prune', ['--force' => true, '--yes' => true])->assertExitCode(0);

        Storage::disk('public')->assertExists('uploads/products/used.png');
    });
});

/** 把同一份字节写到一个临时文件里，供多次 UploadedFile 使用 */
function tmpCopy(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'media').'.png';
    file_put_contents($path, $bytes);

    return $path;
}
