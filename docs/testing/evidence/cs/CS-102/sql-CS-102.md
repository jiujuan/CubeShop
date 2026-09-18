# CS-102 种子数据与状态机常量核对

> 以下命令与输出均为在本机实测生成（开发库 PG `cubeshop`）。

## 默认工单类型（8 类）

```bash
php artisan tinker --execute="foreach(App\Models\CsTicketType::orderBy('sort')->get(['name','code','require_order']) as $t){echo $t->code.' | '.$t->name.' | require_order='.($t->require_order?1:0).PHP_EOL;}"
```

```
pre_sale | 售前咨询 | require_order=0
logistics | 物流问题 | require_order=1
quality | 商品质量 | require_order=1
return | 退换货 | require_order=1
payment | 支付问题 | require_order=0
account | 账户问题 | require_order=0
complaint | 投诉建议 | require_order=0
other | 其他 | require_order=0
```

## 种子数据总量 / 状态数

```bash
php artisan tinker --execute="echo 'types='.App\Models\CsTicketType::count().' faqCat='.App\Models\CsFaqCategory::count().' faqArt='.App\Models\CsFaqArticle::count().' states='.count(App\Models\CsTicket::STATUS_LABELS);"
```

```
types=8 faqCat=5 faqArt=5 states=5
```

## 状态机 TRANSITIONS 矩阵

```bash
php artisan tinker --execute="foreach(App\Models\CsTicket::TRANSITIONS as $from=>$tos){echo $from.' -> '.(implode(',',$tos)?:'(终态)').PHP_EOL;}"
```

```
pending -> processing,closed
processing -> waiting_user,completed,closed
waiting_user -> processing,closed
completed -> processing,closed
closed -> (终态)
```
