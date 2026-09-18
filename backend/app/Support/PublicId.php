<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CsTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ProductSku;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Database\Eloquent\Model;

/**
 * 对外公开标识（P2-11 终态）：统一使用数据库持久化的 ULID public_id，退役 sqids。
 *
 * 设计要点：
 * - 内部一律继续使用 bigint 自增主键（不动索引/外键/性能）。
 * - 出参：优先直接读取模型 `$model->public_id`（零额外查询）；本类的 encode() 仅作为不便直接取列时的兼容壳。
 * - 入参：resolve() 优先按 public_id 列查找，兼容历史纯数字 int 主键（过渡期），查不到返回 null（供调用方转 404）。
 * - ULID 由 Str::ulid() 生成（时间有序、含数字），不可被猜解还原为内部主键；后台仍使用内部 int 主键。
 */
final class PublicId
{
    /** 支持的 scope（与表名对应，便于 audit） */
    public const SCOPE_PRODUCT = 'products';

    public const SCOPE_SKU = 'product_skus';

    public const SCOPE_USER = 'users';

    public const SCOPE_ORDER = 'orders';

    public const SCOPE_ORDER_ITEM = 'order_items';

    public const SCOPE_ADDRESS = 'user_addresses';

    public const SCOPE_CATEGORY = 'categories';

    public const SCOPE_BRAND = 'brands';

    public const SCOPE_REFUND = 'refunds';

    public const SCOPE_TICKET = 'cs_tickets';

    /** scope => 模型类（用于 resolve/encode 反查） */
    private const MODEL_MAP = [
        self::SCOPE_PRODUCT => Product::class,
        self::SCOPE_SKU => ProductSku::class,
        self::SCOPE_USER => User::class,
        self::SCOPE_ORDER => Order::class,
        self::SCOPE_ORDER_ITEM => OrderItem::class,
        self::SCOPE_ADDRESS => UserAddress::class,
        self::SCOPE_CATEGORY => Category::class,
        self::SCOPE_BRAND => Brand::class,
        self::SCOPE_REFUND => Refund::class,
        self::SCOPE_TICKET => CsTicket::class,
    ];

    /**
     * 入参解析：返回内部 int 主键，查不到返回 null。
     * 优先 public_id 列；纯数字（<=19 位）兼容历史 int 主键。
     */
    public static function resolve(string $scope, mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $model = self::MODEL_MAP[$scope] ?? null;
        if ($model === null) {
            return null;
        }

        $query = $model::query()->where('public_id', $value);
        if (ctype_digit($value) && strlen($value) <= 19) {
            $query->orWhere('id', (int) $value);
        }

        return $query->first()?->id;
    }

    /**
     * 内部主键 -> 对外公开标识（兼容壳：按 scope 反查模型返回 public_id）。
     * 高频列表场景请直接读取 $model->public_id 以避免额外查询。
     */
    public static function encode(string $scope, ?int $id): ?string
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        $model = self::MODEL_MAP[$scope] ?? null;
        if ($model === null) {
            return null;
        }

        return $model::query()->find($id)?->public_id;
    }

    /**
     * 同 encode，但主键为 null/<=0 时返回 null（用于订单行里可能悬空的 product_id / sku_id）。
     */
    public static function encodeNullable(string $scope, ?int $id): ?string
    {
        return self::encode($scope, $id);
    }

    /**
     * 由模型实例直接取 public_id（推荐出参用法，零额外查询）。
     */
    public static function of(Model $model): ?string
    {
        return $model->public_id ?? null;
    }
}
