# CS-108 后台筛选与统计核对

> 以下命令与输出均为在本机实测生成（开发库 PG `cubeshop`）。

## 按状态计数（与后台 meta.pending_count 对齐）

```bash
php artisan tinker --execute="echo 'pending='.App\Models\CsTicket::where('status','pending')->count().' processing='.App\Models\CsTicket::where('status','processing')->count().' waiting_user='.App\Models\CsTicket::where('status','waiting_user')->count();"
```

```
pending=0 processing=3 waiting_user=0
```

## 列表数据（筛选维度：状态 / 类型 / 用户）

```bash
php artisan tinker --execute="foreach(App\Models\CsTicket::orderByDesc('id')->limit(10)->get(['id','ticket_no','status','type_id','user_id']) as $t){echo $t->id.' | '.$t->ticket_no.' | '.$t->status.' | type='.$t->type_id.' | u'.$t->user_id.PHP_EOL;}"
```

```
7 | TK20260917000007 | processing | type=7 | u573
6 | TK20260917000006 | closed | type=2 | u572
5 | TK20260917000005 | closed | type=2 | u570
4 | TK20260917000004 | processing | type=8 | u569
3 | TK20260917000003 | closed | type=2 | u568
2 | TK20260917000002 | processing | type=8 | u566
1 | TK20260917000001 | closed | type=2 | u565
```
