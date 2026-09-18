# CS-110 FAQ 分类/文章统计核对

> 以下命令与输出均为在本机实测生成（开发库 PG `cubeshop`）。

## 文章状态与 published_at 联动

```bash
php artisan tinker --execute="foreach(App\Models\CsFaqArticle::orderBy('id')->get(['id','title','status','published_at']) as $a){echo $a->id.' | '.$a->status.' | '.($a->published_at?'published_at='.$a->published_at->toDateTimeString():'published_at=NULL').' | '.$a->title.PHP_EOL;}"
```

```
1 | published | published_at=2026-09-17 16:39:43 | 如何下单购买商品？
2 | published | published_at=2026-09-17 16:43:15 | 下单后多久发货？如何查看物流？
3 | published | published_at=2026-09-17 16:44:17 | 支持哪些支付方式？支付失败怎么办？
4 | published | published_at=2026-09-17 16:44:18 | 退换货政策与流程
5 | published | published_at=2026-09-17 16:44:19 | 如何修改登录密码？
```

## 分类 × 已发布文章数

```bash
php artisan tinker --execute="foreach(App\Models\CsFaqCategory::withCount(['publishedArticles as published_count'])->orderBy('sort')->get() as $c){echo $c->id.' | '.$c->name.' | published_count='.$c->published_count.PHP_EOL;}"
```

```
1 | 购物指南 | published_count=1
2 | 物流配送 | published_count=1
3 | 支付问题 | published_count=1
4 | 售后政策 | published_count=1
5 | 账户安全 | published_count=1
```
