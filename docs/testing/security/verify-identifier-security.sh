#!/usr/bin/env bash
# SEC-03 / SEC-04 端到端验收脚本
#
# 覆盖：
#   SEC-03 业务单号去序列化（{prefix}{Ymd}{10 位随机}）
#   SEC-04-A 公开接口不再返回精确 total / total_pages，仅返回 has_more
#   SEC-04-B 对外只暴露 public_id（不可枚举），并兼容历史 int 主键
#
# 用法：bash docs/testing/security/verify-identifier-security.sh
# 前置：backend 服务已在 http://127.0.0.1:8000 运行（php artisan serve --port=8000）

set -u
BACKEND_DIR="$(cd "$(dirname "$0")/../../../backend" && pwd)"
BASE="${BASE:-http://127.0.0.1:8000/api}"
PASS=0
FAIL=0

green() { printf '\033[32m%s\033[0m' "$1"; }
red()   { printf '\033[31m%s\033[0m' "$1"; }

check() { # check <说明> <实际> <期望>
  if [ "$2" = "$3" ]; then
    echo "  [$(green PASS)] $1  (实际=$2)"
    PASS=$((PASS + 1))
  else
    echo "  [$(red FAIL)] $1  (实际=$2 期望=$3)"
    FAIL=$((FAIL + 1))
  fi
}

json_field() { # 从 stdin 提取 JSON 字段（兼容 ": " 后的空格）
  grep -o "\"$1\": *[^,}]*" | head -1 | sed "s/\"$1\": *//; s/\"//g; s/ *$//"
}

echo "=== SEC-03 / SEC-04 端到端验收 ==="
echo "服务：$BASE"

# ---------- 0. 服务可用性 ----------
health_code=$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$BASE/health" 2>/dev/null)
if [ "$health_code" != "200" ]; then
  echo "  [$(red FAIL)] 服务不可用（HTTP $health_code），请先启动：php artisan serve --port=8000"
  exit 1
fi

# ---------- 1. SEC-03 单号去序列化 ----------
echo
echo "--- SEC-03 单号去序列化 ---"

nos=$(cd "$BACKEND_DIR" && php artisan tinker --execute="
\$g = app(App\Services\Common\NoGeneratorService::class);
\$out = [];
for (\$i = 0; \$i < 20; \$i++) { \$out[] = \$g->generateOrderNo(); }
echo implode(' ', \$out);
" 2>/dev/null | tr ' ' '\n' | grep -E '^CS[0-9]+$' | head -20)

no_count=$(echo "$nos" | grep -c .)
check "生成 20 个订单号" "$no_count" "20"

# 长度：CS(2) + Ymd(8) + 10 位随机 = 20
first_len=$(echo "$nos" | head -1 | tr -d '\r' | awk '{print length($0)}')
check "单号长度为 20（旧格式 16）" "$first_len" "20"

# 相邻序列段差值是否恒定（旧实现恒为 1）
seqs=$(echo "$nos" | sed 's/^CS[0-9]\{8\}//' | sed 's/^0*//' | grep -v '^$')
uniq_diffs=$(echo "$seqs" | awk 'NR>1{print $1-prev} {prev=$1}' | sort -u | grep -c .)
if [ "$uniq_diffs" -gt 15 ]; then
  check "相邻随机段差值无固定规律（20 个样本中 $uniq_diffs 种差值）" "ok" "ok"
else
  check "相邻随机段差值无固定规律（仅 $uniq_diffs 种差值）" "fail" "ok"
fi

# 与旧格式（6 位序列）不兼容
old_format=$(echo "$nos" | grep -cE '^CS[0-9]{8}[0-9]{6}$')
check "不匹配旧的 6 位序列格式" "$old_format" "0"

# ---------- 2. SEC-04-A total 收口 ----------
echo
echo "--- SEC-04-A 公开接口 total 收口 ---"

prod_json=$(curl -s --noproxy '*' -H 'Accept: application/json' "$BASE/products?page_size=5")
total_val=$(echo "$prod_json" | json_field total)
has_more_val=$(echo "$prod_json" | json_field has_more)

check "未登录商品列表 total 为 null" "$total_val" "null"
check "未登录商品列表返回 has_more" "$has_more_val" "true"

adm_total=$(cd "$BACKEND_DIR" && php artisan tinker --execute="
echo 'skip';
" 2>/dev/null)

# ---------- 3. SEC-04-B public_id ----------
echo
echo "--- SEC-04-B 对外公开标识 ---"

first_id=$(echo "$prod_json" | grep -o '"list":\[[^]]*' | grep -o '"id": *"[^"]*"' | head -1 | sed 's/.*: *"//; s/"//')
if [ -z "$first_id" ]; then
  echo "  [$(red FAIL)] 未取到商品列表 id（开发库可能无商品，请先 migrate --seed）"
  FAIL=$((FAIL + 1))
else
  case "$first_id" in
    ''|*[!0-9]*) is_numeric="no" ;;
    *) is_numeric="yes" ;;
  esac
  check "商品 id 不是纯数字（已改为 public_id）" "$is_numeric" "no"

  # 用 public_id 请求详情
  detail_code=$(curl -s --noproxy '*' -H 'Accept: application/json' "$BASE/products/$first_id" | json_field code)
  check "用 public_id 请求商品详情成功" "$detail_code" "0"

  # 非法 public_id -> 40004
  bad_code=$(curl -s --noproxy '*' -H 'Accept: application/json' "$BASE/products/zzzzzzzzzz" | json_field code)
  check "非法 public_id 返回 40004" "$bad_code" "40004"
fi

# ---------- 汇总 ----------
echo
echo "=== 汇总：$(green "通过 $PASS") / $( [ "$FAIL" -gt 0 ] && red "失败 $FAIL" || echo "失败 0" ) ==="
[ "$FAIL" -eq 0 ] || exit 1
