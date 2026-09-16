#!/usr/bin/env bash
# CubeShop T-033 优惠券领取并发防超发验证
#
# 说明：php artisan serve 为单 worker，HTTP 层无法产生真实竞态；
#       故本脚本以「多进程 + 同库」方式验证原子防超发原语（PostgreSQL 下为真实并发）：
#         - 场景 A：限量 10 的券，50 个不同用户并发领取 → 恰好 10 成功，issued_count=10
#         - 场景 B：同一用户对限领 1 的券并发领 10 次 → 仅 1 条记录
#         - 场景 D：仅剩 1 张，2 个用户并发领取 → 仅 1 成功，无负数
set -u
cd "$(dirname "$0")/../../backend" || exit 1

TMP="storage/logs/coupon_cc"
mkdir -p "$TMP"

tinker() { php artisan tinker --execute="$1" 2>/dev/null; }

echo "==== 优惠券并发放超发验证开始 $(date '+%F %T') ===="

# ---------- 场景 A：限量 10，50 个不同用户并发 ----------
echo "--- 场景 A：限量 10 的券，50 用户并发领取 ---"
CP_A=$(tinker "echo App\Models\Coupon::create(['name'=>'CC-A-'.uniqid(),'type'=>'fixed','amount'=>10,'min_spend'=>0,'scope'=>'all','scope_refs'=>[],'total_count'=>10,'issued_count'=>0,'used_count'=>0,'per_user_limit'=>1,'valid_type'=>'relative','valid_days'=>7,'status'=>'active'])->id;" | tail -1)
echo "券 A id=$CP_A total=10"

cat > "$TMP/receive_a.php" <<PHP
<?php
\$u = App\Models\User::create(['username'=>'cc_'.getmypid().'_'.uniqid(), 'password'=>bcrypt('x'), 'status'=>1]);
try {
    app(App\Services\Marketing\CouponService::class)->receive(\$u->id, $CP_A);
    echo "OK\\n";
} catch (\Throwable \$e) {
    echo "ERR:".\$e->getMessage()."\\n";
}
PHP

: > "$TMP/out_a.txt"
rm -f "$TMP"/a_*.txt
for i in $(seq 1 50); do
  ( php artisan tinker "$TMP/receive_a.php" > "$TMP/a_$i.txt" 2>/dev/null ) &
done
wait

cat "$TMP"/a_*.txt > "$TMP/out_a.txt" 2>/dev/null
OK_A=$(grep -c 'OK' "$TMP/out_a.txt" || true)
ERR_A=$(grep -c 'ERR' "$TMP/out_a.txt" || true)
ISSUED_A=$(tinker "echo App\Models\Coupon::find($CP_A)->issued_count;" | tail -1)
ROWS_A=$(tinker "echo App\Models\UserCoupon::where('coupon_id',$CP_A)->count();" | tail -1)

echo "成功=$OK_A 失败=$ERR_A issued_count=$ISSUED_A user_coupons=$ROWS_A"
[ "$OK_A" = "10" ] && [ "$ISSUED_A" = "10" ] && [ "$ROWS_A" = "10" ] && echo "场景A PASS（无超发）" || echo "场景A FAIL"

# ---------- 场景 B：同一用户并发领 10 次（限领 1） ----------
echo "--- 场景 B：同一用户并发领 10 次（限领 1） ---"
CP_B=$(tinker "echo App\Models\Coupon::create(['name'=>'CC-B-'.uniqid(),'type'=>'fixed','amount'=>10,'min_spend'=>0,'scope'=>'all','scope_refs'=>[],'total_count'=>100,'issued_count'=>0,'used_count'=>0,'per_user_limit'=>1,'valid_type'=>'relative','valid_days'=>7,'status'=>'active'])->id;" | tail -1)
USER_B=$(tinker "\$u = App\Models\User::create(['username'=>'ccb_'.uniqid(),'password'=>bcrypt('x'),'status'=>1]); echo \$u->id;" | tail -1)
echo "券 B id=$CP_B user=$USER_B"

cat > "$TMP/receive_b.php" <<PHP
<?php
try {
    app(App\Services\Marketing\CouponService::class)->receive($USER_B, $CP_B);
    echo "OK\\n";
} catch (\Throwable \$e) {
    echo "ERR\\n";
}
PHP

: > "$TMP/out_b.txt"
rm -f "$TMP"/b_*.txt
for i in $(seq 1 10); do
  ( php artisan tinker "$TMP/receive_b.php" > "$TMP/b_$i.txt" 2>/dev/null ) &
done
wait

cat "$TMP"/b_*.txt > "$TMP/out_b.txt" 2>/dev/null
OK_B=$(grep -c 'OK' "$TMP/out_b.txt" || true)
ROWS_B=$(tinker "echo App\Models\UserCoupon::where('coupon_id',$CP_B)->where('user_id',$USER_B)->count();" | tail -1)
echo "成功=$OK_B 记录数=$ROWS_B（期望 1）"
[ "$ROWS_B" = "1" ] && echo "场景B PASS（限领生效）" || echo "场景B FAIL"

# ---------- 场景 D：仅剩 1 张，2 用户并发 ----------
echo "--- 场景 D：仅剩 1 张，2 用户并发领取 ---"
CP_D=$(tinker "echo App\Models\Coupon::create(['name'=>'CC-D-'.uniqid(),'type'=>'fixed','amount'=>10,'min_spend'=>0,'scope'=>'all','scope_refs'=>[],'total_count'=>1,'issued_count'=>0,'used_count'=>0,'per_user_limit'=>1,'valid_type'=>'relative','valid_days'=>7,'status'=>'active'])->id;" | tail -1)

cat > "$TMP/receive_d.php" <<PHP
<?php
\$u = App\Models\User::create(['username'=>'ccd_'.getmypid().'_'.uniqid(), 'password'=>bcrypt('x'), 'status'=>1]);
try {
    app(App\Services\Marketing\CouponService::class)->receive(\$u->id, $CP_D);
    echo "OK\\n";
} catch (\Throwable \$e) {
    echo "ERR\\n";
}
PHP

: > "$TMP/out_d.txt"
rm -f "$TMP"/d_*.txt
for i in 1 2; do
  ( php artisan tinker "$TMP/receive_d.php" > "$TMP/d_$i.txt" 2>/dev/null ) &
done
wait

cat "$TMP"/d_*.txt > "$TMP/out_d.txt" 2>/dev/null
OK_D=$(grep -c 'OK' "$TMP/out_d.txt" || true)
ISSUED_D=$(tinker "echo App\Models\Coupon::find($CP_D)->issued_count;" | tail -1)
echo "成功=$OK_D issued_count=$ISSUED_D（期望 1 / 1）"
[ "$OK_D" = "1" ] && [ "$ISSUED_D" = "1" ] && echo "场景D PASS（无负数、无超发）" || echo "场景D FAIL"

# ---------- 数据清理 ----------
tinker "App\Models\UserCoupon::whereIn('coupon_id',[$CP_A,$CP_B,$CP_D])->delete(); App\Models\Coupon::whereIn('id',[$CP_A,$CP_B,$CP_D])->delete(); App\Models\User::where('username','like','cc_%')->orWhere('username','like','ccb_%')->orWhere('username','like','ccd_%')->forceDelete();" >/dev/null

echo "==== 验证结束 $(date '+%F %T') ===="
