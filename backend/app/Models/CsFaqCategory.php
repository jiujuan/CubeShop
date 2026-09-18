<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 帮助中心分类（CS-101 / CS-102）
 */
class CsFaqCategory extends Model
{
    protected $table = 'cs_faq_category';

    protected $fillable = ['name', 'sort', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
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
