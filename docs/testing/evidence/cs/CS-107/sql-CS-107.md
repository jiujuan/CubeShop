# CS-107 上传与站内通知记录核对

> 以下命令与输出均为在本机实测生成（开发库 PG `cubeshop`）。

## 客服相关通知记录（最近 8 条）

```bash
php artisan tinker --execute="foreach(App\Models\Notification::where('type','like','cs_%')->orderByDesc('id')->limit(8)->get(['id','user_id','receiver_type','type','title']) as $n){echo $n->id.' | u'.$n->user_id.' | '.$n->receiver_type.' | '.$n->type.' | '.$n->title.PHP_EOL;}"
```

```
129 | u573 | customer | cs_ticket_status | 工单状态更新
128 | u573 | customer | cs_ticket_reply | 客服回复了您的工单
127 | u2 | admin | cs_ticket_new | 新服务工单待处理
126 | u572 | customer | cs_ticket_status | 工单状态更新
125 | u572 | customer | cs_ticket_status | 工单状态更新
124 | u572 | customer | cs_ticket_status | 工单状态更新
123 | u572 | customer | cs_ticket_reply | 客服回复了您的工单
122 | u2 | admin | cs_ticket_new | 新服务工单待处理
```

## 内部备注计数（核对「内部备注不通知用户」）

```bash
php artisan tinker --execute="echo 'internal_notes='.App\Models\CsTicketMessage::where('is_internal',1)->count().' total_msgs='.App\Models\CsTicketMessage::count();"
```

```
internal_notes=6 total_msgs=40
```
