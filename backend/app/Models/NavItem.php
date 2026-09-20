<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 前台顶部导航条目
 *
 * 两类条目（真源见迁移 000099 的注释）：
 * - `category`：引用商品分类，标题与链接**由分类派生**（分类改名/停用自动跟随）
 * - `custom`：自定义标题 + 任意 URL
 *
 * ⚠️ 与分类是「引用」而非「拷贝」关系，故不缓存 title/url ——
 * 一旦缓存，分类改名后导航就会停留在旧名字上。
 */
class NavItem extends Model
{
    public const TYPE_CATEGORY = 'category';
    public const TYPE_CUSTOM = 'custom';

    public const TYPE_LABELS = [
        self::TYPE_CATEGORY => '商品分类',
        self::TYPE_CUSTOM => '自定义链接',
    ];

    public const TARGETS = ['_self', '_blank'];

    protected $table = 'nav_items';

    protected $fillable = [
        'type', 'title', 'url', 'category_id', 'target', 'sort', 'is_active',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function isCategory(): bool
    {
        return $this->type === self::TYPE_CATEGORY;
    }
}
