<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 商品分类（二级）
 */
class Category extends Model
{
    use SoftDeletes;

    protected $table = 'categories';
    protected $fillable = ['parent_id', 'name', 'sort', 'status'];
}
