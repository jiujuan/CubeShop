# CS-103 客服权限码与角色授权核对

> 以下命令与输出均为在本机实测生成（开发库 PG `cubeshop`）。

## cs.* 权限码

```bash
php artisan tinker --execute="foreach(Spatie\Permission\Models\Permission::where('name','like','cs.%')->orderBy('name')->get() as $p){echo $p->name.' | guard='.$p->guard_name.PHP_EOL;}"
```

```
cs.faq.manage | guard=web
cs.ticket.handle | guard=web
cs.ticket.view | guard=web
```

## 角色 → cs 权限授权

```bash
php artisan tinker --execute="foreach(Spatie\Permission\Models\Role::with('permissions')->get() as $r){$cs=$r->permissions->pluck('name')->filter(fn($n)=>str_starts_with($n,'cs.'))->values();echo $r->name.' => '.($cs->count()?$cs->implode(','):'(无 cs 权限)').PHP_EOL;}"
```

```
super_admin => cs.ticket.view,cs.ticket.handle,cs.faq.manage
operator => cs.ticket.view,cs.ticket.handle,cs.faq.manage
product_manage => (无 cs 权限)
```
