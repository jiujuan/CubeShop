<?php

use App\Casts\MediaPath;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\CsFaqArticle;
use App\Support\MediaUrl;

/**
 * MediaUrl：图片路径换算的唯一真源（媒体治理 P0）
 *
 * 覆盖三条约定：
 * 1. **写**：任意形态 → 存储态相对路径（append 域名前先剥干净）
 * 2. **读**：任意形态 → 绝对 URL（前端只代理 /api，根相对地址会 404，必须拼回去）
 * 3. 外链 / data:base64 / 普通文案：**一律原样透传**
 */
describe('MediaUrl 换算', function () {
    it('写入方向：绝对 URL / 根相对 / 存储态都归一成 uploads/...', function () {
        expect(MediaUrl::toPath('http://localhost:8000/storage/uploads/products/x.png'))
            ->toBe('uploads/products/x.png')
            ->and(MediaUrl::toPath('/storage/uploads/products/x.png'))
            ->toBe('uploads/products/x.png')
            ->and(MediaUrl::toPath('uploads/products/x.png'))
            ->toBe('uploads/products/x.png');
    });

    it('写入方向幂等：已归一的值再跑一次不变', function () {
        $once = MediaUrl::toPath('http://localhost:8000/storage/uploads/a.png');

        expect(MediaUrl::toPath($once))->toBe($once);
    });

    it('读取方向：三种形态都拼出绝对 URL（APP_URL 参与拼接）', function () {
        $expect = rtrim(config('app.url'), '/').'/storage/uploads/products/x.png';

        expect(MediaUrl::to('uploads/products/x.png'))->toBe($expect)
            ->and(MediaUrl::to('/storage/uploads/products/x.png'))->toBe($expect)
            ->and(MediaUrl::to('http://localhost:8000/storage/uploads/products/x.png'))->toBe($expect);
    });

    it('空值与 null 原样返回，不拼任何东西', function () {
        expect(MediaUrl::to(null))->toBeNull()
            ->and(MediaUrl::to(''))->toBe('')
            ->and(MediaUrl::toPath(null))->toBeNull()
            ->and(MediaUrl::toPath(''))->toBe('');
    });

    it('外链与 base64 原样透传', function () {
        expect(MediaUrl::to('https://cdn.example.com/a.png'))->toBe('https://cdn.example.com/a.png')
            ->and(MediaUrl::toPath('https://cdn.example.com/a.png'))->toBe('https://cdn.example.com/a.png')
            ->and(MediaUrl::to('data:image/png;base64,iVBORw0KGgo='))->toBe('data:image/png;base64,iVBORw0KGgo=');
    });

    it('保守版本只认资源形态：普通文案不被拼域名', function () {
        expect(MediaUrl::toIfAsset('hero'))->toBe('hero')
            ->and(MediaUrl::toIfAsset('uploads/cms/a.png'))->toStartWith('http')
            ->and(MediaUrl::toPathIfAsset('hero'))->toBe('hero');
    });

    it('富文本写入方向：正文里的域名被剥掉，保留根相对', function () {
        $md = '![封面](http://localhost:8000/storage/uploads/cms/a.png) 正文';

        expect(MediaUrl::normalizeEmbedded($md))
            ->toBe('![封面](/storage/uploads/cms/a.png) 正文')
            ->and(MediaUrl::normalizeEmbedded('![](/storage/uploads/cms/a.png)'))
            ->toBe('![](/storage/uploads/cms/a.png)');
    });

    it('富文本读取方向：src/href 与 markdown 语法都拼回绝对 URL', function () {
        $html = '<p><img src="/storage/uploads/cms/a.png"></p>';

        expect(MediaUrl::absoluteEmbedded($html))
            ->toContain(rtrim(config('app.url'), '/').'/storage/uploads/cms/a.png')
            ->and(MediaUrl::absoluteEmbedded('![](</storage/uploads/cms/a.png>)'))
            ->toContain(rtrim(config('app.url'), '/').'/storage/uploads/cms/a.png');
    });

    it('提取路径：富文本只抽出图片路径，不抽普通文案', function () {
        expect(MediaUrl::extractPaths('<img src="/storage/uploads/cms/a.png"> 普通文案 hero'))
            ->toBe(['uploads/cms/a.png']);
    });
});

describe('MediaPath cast 在模型上的行为', function () {
    it('写入存相对路径，读出给绝对 URL', function () {
        $item = new OrderItem();
        $item->sku_image = 'http://localhost:8000/storage/uploads/products/a.png';

        expect($item->getAttributes()['sku_image'])->toBe('uploads/products/a.png')
            ->and($item->sku_image)->toBe(rtrim(config('app.url'), '/').'/storage/uploads/products/a.png');
    });

    it('toArray 出口同样带绝对 URL（不漏拼）', function () {
        $product = new Product(['main_image' => 'uploads/products/b.png']);

        expect($product->toArray()['main_image'])
            ->toBe(rtrim(config('app.url'), '/').'/storage/uploads/products/b.png');
    });

    it('嵌套 JSON 里只换算图片，不动普通文案', function () {
        $article = new CsFaqArticle();
        $article->blocks = [[
            'type' => 'hero',
            'data' => ['title' => '品牌故事', 'image' => 'http://localhost:8000/storage/uploads/cms/a.png'],
        ]];

        expect(json_decode($article->getAttributes()['blocks'], true)[0]['data'])
            ->toBe(['title' => '品牌故事', 'image' => 'uploads/cms/a.png']);

        $blocks = $article->blocks;

        expect($blocks[0]['type'])->toBe('hero')
            ->and($blocks[0]['data']['title'])->toBe('品牌故事')
            ->and($blocks[0]['data']['image'])->toBe(rtrim(config('app.url'), '/').'/storage/uploads/cms/a.png');
    });

    it('媒体 cast 清单可被模型自动识别（删除联动的真源）', function () {
        expect((new Product())->mediaColumns())->toContain('main_image', 'description')
            ->and((new CsFaqArticle())->mediaColumns())->toContain('cover_image', 'blocks', 'page_fields');
    });
});
