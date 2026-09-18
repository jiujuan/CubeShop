<?php

namespace Database\Seeders;

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use Illuminate\Database\Seeder;

/**
 * CS-102：默认帮助中心分类（设计文档 §3.1）+ 每类一篇示例文章
 *
 * 示例文章为草稿态（draft），供 CS-115 验证「发布 → 用户端可见」流程。
 * 幂等：按 name 判重，可重复执行。
 */
class CsFaqCategorySeeder extends Seeder
{
    /** @var array<int, array{name: string, sort: int, article: array{title: string, summary: string, content_md: string}}> */
    public const CATEGORIES = [
        [
            'name' => '购物指南', 'sort' => 10,
            'article' => [
                'title' => '如何下单购买商品？',
                'summary' => '从挑选商品到提交订单的完整流程说明。',
                'content_md' => "1. 浏览商品，选择规格与数量后点击「加入购物车」；\n2. 在购物车确认商品，点击「去结算」；\n3. 选择收货地址、优惠券与配送方式，确认金额后提交订单；\n4. 在收银台完成支付，等待商家发货。",
            ],
        ],
        [
            'name' => '物流配送', 'sort' => 20,
            'article' => [
                'title' => '下单后多久发货？如何查看物流？',
                'summary' => '发货时效说明与物流轨迹查询入口。',
                'content_md' => "一般情况下，付款成功后 **48 小时内**完成发货（节假日顺延）。\n\n发货后可在「我的订单 → 订单详情 → 查看物流」查看实时轨迹。",
            ],
        ],
        [
            'name' => '支付问题', 'sort' => 30,
            'article' => [
                'title' => '支持哪些支付方式？支付失败怎么办？',
                'summary' => '支付方式与常见支付异常处理。',
                'content_md' => "目前支持 **余额支付** 与 **线下转账** 等方式，具体可用渠道以收银台展示为准。\n\n如支付失败，请确认余额充足或稍后重试；重复扣款请联系客服处理。",
            ],
        ],
        [
            'name' => '售后政策', 'sort' => 40,
            'article' => [
                'title' => '退换货政策与流程',
                'summary' => '退换货条件、时效与申请流程。',
                'content_md' => "商品支持在签收后约定天数内申请退换货，需保证商品完好、配件齐全。\n\n申请路径：**我的订单 → 选择订单 → 申请售后**。",
            ],
        ],
        [
            'name' => '账户安全', 'sort' => 50,
            'article' => [
                'title' => '如何修改登录密码？',
                'summary' => '密码修改与账号安全建议。',
                'content_md' => "路径：**个人中心 → 账号安全 → 修改密码**。\n\n建议定期更换密码，不要与其他平台使用相同密码。",
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $index => $item) {
            $category = CsFaqCategory::updateOrCreate(
                ['name' => $item['name']],
                ['sort' => $item['sort'], 'is_active' => true],
            );

            CsFaqArticle::updateOrCreate(
                ['category_id' => $category->id, 'title' => $item['article']['title']],
                [
                    'summary' => $item['article']['summary'],
                    // 写 markdown 源，HTML 产物由 CsFaqArticle 的 writing 钩子渲染 + 净化派生
                    'content_md' => $item['article']['content_md'],
                    'sort' => ($index + 1) * 10,
                    'status' => CsFaqArticle::STATUS_DRAFT,
                ],
            );
        }
    }
}
