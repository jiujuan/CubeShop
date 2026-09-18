import subprocess, os

ROOT = 'D:/codeproject/PHP/CubeShop'
E = ROOT + '/docs/testing/evidence/cs'


def tinker(php):
    r = subprocess.run(['php', 'artisan', 'tinker', '--execute=' + php],
                       cwd=ROOT + '/backend', capture_output=True)
    out = r.stdout.decode('utf-8', 'replace') + r.stderr.decode('utf-8', 'replace')
    lines = [l for l in out.splitlines() if l.strip() and 'Psy Shell' not in l]
    return '\n'.join(lines).strip()


def write(rel, title, blocks):
    p = os.path.join(E, rel)
    os.makedirs(os.path.dirname(p), exist_ok=True)
    with open(p, 'w', encoding='utf-8', newline='\n') as f:
        f.write('# ' + title + '\n\n> 以下命令与输出均为在本机实测生成（开发库 PG `cubeshop`）。\n')
        for head, php, out in blocks:
            f.write('\n## ' + head + '\n\n```bash\nphp artisan tinker --execute="' + php.replace('"', '\\"') + '"\n```\n\n```\n' + out + '\n```\n')
    print('wrote', rel, len(blocks), 'blocks')


# ---------- CS-102 ----------
b = []
php = "foreach(App\\\\Models\\\\CsTicketType::orderBy('sort')->get(['name','code','require_order']) as $t){echo $t->code.' | '.$t->name.' | require_order='.($t->require_order?1:0).PHP_EOL;}"
php = php.replace('\\\\', '\\')
b.append(('默认工单类型（8 类）', php, tinker(php)))
php = "echo 'types='.App\\\\Models\\\\CsTicketType::count().' faqCat='.App\\\\Models\\\\CsFaqCategory::count().' faqArt='.App\\\\Models\\\\CsFaqArticle::count().' states='.count(App\\\\Models\\\\CsTicket::STATUS_LABELS);".replace('\\\\', '\\')
b.append(('种子数据总量 / 状态数', php, tinker(php)))
php = "foreach(App\\\\Models\\\\CsTicket::TRANSITIONS as $from=>$tos){echo $from.' -> '.(implode(',',$tos)?:'(终态)').PHP_EOL;}".replace('\\\\', '\\')
b.append(('状态机 TRANSITIONS 矩阵', php, tinker(php)))
write('CS-102/sql-CS-102.md', 'CS-102 种子数据与状态机常量核对', b)

# ---------- CS-103 ----------
b = []
php = "foreach(Spatie\\\\Permission\\\\Models\\\\Permission::where('name','like','cs.%')->orderBy('name')->get() as $p){echo $p->name.' | guard='.$p->guard_name.PHP_EOL;}".replace('\\\\', '\\')
b.append(('cs.* 权限码', php, tinker(php)))
php = "foreach(Spatie\\\\Permission\\\\Models\\\\Role::with('permissions')->get() as $r){$cs=$r->permissions->pluck('name')->filter(fn($n)=>str_starts_with($n,'cs.'))->values();echo $r->name.' => '.($cs->count()?$cs->implode(','):'(无 cs 权限)').PHP_EOL;}".replace('\\\\', '\\')
b.append(('角色 → cs 权限授权', php, tinker(php)))
write('CS-103/sql-CS-103.md', 'CS-103 客服权限码与角色授权核对', b)

# ---------- CS-107 ----------
b = []
php = "foreach(App\\\\Models\\\\Notification::where('type','like','cs_%')->orderByDesc('id')->limit(8)->get(['id','user_id','receiver_type','type','title']) as $n){echo $n->id.' | u'.$n->user_id.' | '.$n->receiver_type.' | '.$n->type.' | '.$n->title.PHP_EOL;}".replace('\\\\', '\\')
b.append(('客服相关通知记录（最近 8 条）', php, tinker(php)))
php = "echo 'internal_notes='.App\\\\Models\\\\CsTicketMessage::where('is_internal',1)->count().' total_msgs='.App\\\\Models\\\\CsTicketMessage::count();".replace('\\\\', '\\')
b.append(('内部备注计数（核对「内部备注不通知用户」）', php, tinker(php)))
write('CS-107/sql-CS-107.md', 'CS-107 上传与站内通知记录核对', b)

# ---------- CS-108 ----------
b = []
php = "echo 'pending='.App\\\\Models\\\\CsTicket::where('status','pending')->count().' processing='.App\\\\Models\\\\CsTicket::where('status','processing')->count().' waiting_user='.App\\\\Models\\\\CsTicket::where('status','waiting_user')->count();".replace('\\\\', '\\')
b.append(('按状态计数（与后台 meta.pending_count 对齐）', php, tinker(php)))
php = "foreach(App\\\\Models\\\\CsTicket::orderByDesc('id')->limit(10)->get(['id','ticket_no','status','type_id','user_id']) as $t){echo $t->id.' | '.$t->ticket_no.' | '.$t->status.' | type='.$t->type_id.' | u'.$t->user_id.PHP_EOL;}".replace('\\\\', '\\')
b.append(('列表数据（筛选维度：状态 / 类型 / 用户）', php, tinker(php)))
write('CS-108/sql-CS-108.md', 'CS-108 后台筛选与统计核对', b)

# ---------- CS-110 ----------
b = []
php = "foreach(App\\\\Models\\\\CsFaqArticle::orderBy('id')->get(['id','title','status','published_at']) as $a){echo $a->id.' | '.$a->status.' | '.($a->published_at?'published_at='.$a->published_at->toDateTimeString():'published_at=NULL').' | '.$a->title.PHP_EOL;}".replace('\\\\', '\\')
b.append(('文章状态与 published_at 联动', php, tinker(php)))
php = "foreach(App\\\\Models\\\\CsFaqCategory::withCount(['publishedArticles as published_count'])->orderBy('sort')->get() as $c){echo $c->id.' | '.$c->name.' | published_count='.$c->published_count.PHP_EOL;}".replace('\\\\', '\\')
b.append(('分类 × 已发布文章数', php, tinker(php)))
write('CS-110/sql-CS-110.md', 'CS-110 FAQ 分类/文章统计核对', b)
