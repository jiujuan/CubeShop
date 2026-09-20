<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 内容中心 CMS 栏目（原帮助中心分类，CS-101 / CS-102 / CMS-101）
 *
 * 树形结构：parent_id（0=根）+ level + path（物化路径，如 /1/5/ —— 用于子树查询与防环）。
 * 类型：
 * - `channel`：栏目，下挂文章列表（既有 FAQ 能力）
 * - `page`   ：单页，内容走 CmsPageTemplate 定义的字段（cs_faq_article.page_fields）
 */
class CsFaqCategory extends Model
{
    public const TYPE_CHANNEL = 'channel';
    public const TYPE_PAGE = 'page';

    public const TYPE_LABELS = [
        self::TYPE_CHANNEL => '栏目',
        self::TYPE_PAGE => '单页',
    ];

    /**
     * 「公告」承载栏目名（CMS-204：服务公告软并入内容中心）
     *
     * 公告没有独立的表结构，而是寄存在一个同名 `channel` 栏目下（见迁移 000098）。
     * 它以**名字**作为锚点：迁移播种、用户端 `AnnouncementController` 取数、
     * 帮助中心/sitemap 排除，三处都认这一个名字。
     *
     * ⚠️ 公告有自己的前台入口（`/announcements`），因此**不能**作为帮助中心类目出现，
     * 其文章也不该以 `/service-center/faq/{id}` 的身份进 sitemap（同一内容两个 URL）。
     */
    public const ANNOUNCEMENT_CATEGORY_NAME = '公告';

    protected $table = 'cs_faq_category';

    protected $fillable = [
        'name', 'parent_id', 'level', 'path', 'type', 'slug', 'template',
        'show_in_nav', 'icon', 'sort', 'is_active',
        'seo_title', 'seo_keywords', 'seo_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_in_nav' => 'boolean',
        'sort' => 'integer',
        'parent_id' => 'integer',
        'level' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    public function scopeRoot($query)
    {
        return $query->where('parent_id', 0);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeInNav($query)
    {
        return $query->where('show_in_nav', true);
    }

    public function isPage(): bool
    {
        return $this->type === self::TYPE_PAGE;
    }

    public function isChannel(): bool
    {
        return $this->type === self::TYPE_CHANNEL;
    }

    /**
     * 「公告」承载栏目的 id；未播种（迁移没跑 / 被删）返回 null
     *
     * 需要「用户端类目列表」的地方用它算出要排除的 id
     * （`CmsCategoryService::tree(['exclude_ids' => …])`）。
     * 刻意**不做进程内缓存**：测试库每个用例都会重建，缓存一个 id 会跨用例串味。
     */
    public static function announcementCarrierId(): ?int
    {
        $id = self::query()
            ->where('name', self::ANNOUNCEMENT_CATEGORY_NAME)
            ->where('type', self::TYPE_CHANNEL)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(CsFaqArticle::class, 'category_id');
    }

    public function publishedArticles(): HasMany
    {
        return $this->articles()->where('status', CsFaqArticle::STATUS_PUBLISHED);
    }
}
