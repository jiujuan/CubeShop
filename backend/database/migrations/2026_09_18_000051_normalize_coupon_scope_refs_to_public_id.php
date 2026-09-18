<?php

use App\Models\Coupon;
use App\Support\PublicId;
use Illuminate\Database\Migrations\Migration;

/**
 * P2-11 归一化：优惠券 scope_refs 由历史 int 主键改写为 public_id 字符串。
 *
 * 与「Seeder（新装）」保持一致：本迁移处理存量数据，幂等可重跑
 * （已为 public_id 的引用走 else 分支原样保留，无变化则不写库）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $coupons = Coupon::query()
            ->whereNotNull('scope_refs')
            ->whereIn('scope', [Coupon::SCOPE_CATEGORY, Coupon::SCOPE_PRODUCT])
            ->get();

        foreach ($coupons as $coupon) {
            $refs = $coupon->scope_refs;
            if (! is_array($refs) || $refs === []) {
                continue;
            }

            $pidScope = $coupon->scope === Coupon::SCOPE_CATEGORY
                ? PublicId::SCOPE_CATEGORY
                : PublicId::SCOPE_PRODUCT;

            $new = [];
            foreach ($refs as $r) {
                if (is_numeric($r)) {
                    $pid = PublicId::encode($pidScope, (int) $r);
                    if ($pid !== null) {
                        $new[] = $pid;
                    }
                } else {
                    // 已经是 public_id：原样保留（存在性交由业务层校验）
                    $new[] = (string) $r;
                }
            }

            if ($new !== $refs) {
                $coupon->scope_refs = $new;
                $coupon->save();
            }
        }
    }

    public function down(): void
    {
        // 不可逆：public_id 无法可靠还原为历史 int 主键（int 仅为过渡兼容壳）。
    }
};
