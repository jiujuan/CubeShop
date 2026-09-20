<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\HasPublicId;

/**
 * 商品分类（二级）
 */
class Category extends Model
{
    use SoftDeletes, HasPublicId;

    protected $table = 'categories';
    protected $fillable = ['parent_id', 'name', 'sort', 'status'];

    /**
     * 子分类（二级）
     * ⚠️ 本模型用 SoftDeletes，关系查询自动排除已软删的子分类
     *    —— 子分类被删干净后父分类即可删除，不必等物理清理。
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
