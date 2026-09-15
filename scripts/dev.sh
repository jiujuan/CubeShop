#!/usr/bin/env bash
#
# CubeShop 开发服务管理器（Linux / macOS）
#
# 一行命令启动 / 停止 / 列出 前端或后端服务，或单个服务。
# 前端：web(storefront)、admin(管理端)
# 后端：api(Laravel serve)、queue(队列 worker)、scheduler(定时调度)
#
# 用法：
#   ./dev.sh list                        列出所有服务及状态
#   ./dev.sh start                       启动全部服务
#   ./dev.sh start frontend              启动全部前端服务
#   ./dev.sh start backend               启动全部后端服务
#   ./dev.sh start web                   启动单个服务
#   ./dev.sh stop [all|frontend|backend|<name>]
#   ./dev.sh restart [all|frontend|backend|<name>]
#   ./dev.sh logs <name>                 查看某服务日志（tail -f 形式）
#
# 说明：
#   - 每个服务以 setsid 独立进程组方式后台运行，日志与 PID 存于 scripts/.runtime/
#   - 首次启动若检测不到依赖（node_modules / vendor）会自动安装（设 CS_NO_INSTALL=1 可跳过）
#   - 仅管理「本地直连开发」进程；数据库/缓存/反代等基础设施请另用 docker compose

set -uo pipefail

# ---------- 路径 ----------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
RUNTIME_DIR="$SCRIPT_DIR/.runtime"
mkdir -p "$RUNTIME_DIR"

# ---------- 颜色（非交互终端自动关闭） ----------
if [ -t 1 ]; then
  C_R="\033[31m"; C_G="\033[32m"; C_Y="\033[33m"; C_B="\033[34m"; C_RST="\033[0m"
else
  C_R=""; C_G=""; C_Y=""; C_B=""; C_RST=""
fi
ok()   { printf "${C_G}✓${C_RST} %s\n" "$1"; }
warn() { printf "${C_Y}!${C_RST} %s\n" "$1"; }
err()  { printf "${C_R}✗${C_RST} %s\n" "$1"; }
info() { printf "${C_B}•${C_RST} %s\n" "$1"; }

# ---------- 服务注册表 ----------
# register <name> <group> <dir> <port> <url> <cmd>
declare -A SVC_GROUP SVC_DIR SVC_PORT SVC_URL SVC_CMD
register() {
  SVC_GROUP[$1]="$2"; SVC_DIR[$1]="$3"; SVC_PORT[$1]="$4"
  SVC_URL[$1]="$5";   SVC_CMD[$1]="$6"
}
register web       frontend web       3000 "http://localhost:3000"            "npm run dev"
register admin     frontend admin     5173 "http://localhost:5173"            "npm run dev"
register api       backend  backend   8000 "http://127.0.0.1:8000/api/health" "php artisan serve --host=127.0.0.1 --port=8000"
register queue     backend  backend   ""   ""                                  "php artisan queue:work --tries=3 --sleep=1"
register scheduler backend  backend   ""   ""                                  "while true; do php artisan schedule:run --no-ansi; sleep 60; done"

ALL_NAMES="web admin api queue scheduler"

# ---------- 工具函数 ----------
pid_file() { echo "$RUNTIME_DIR/$1.pid"; }
log_file() { echo "$RUNTIME_DIR/$1.log"; }

is_running() {
  local pf; pf="$(pid_file "$1")"
  [ -f "$pf" ] && kill -0 "$(cat "$pf" 2>/dev/null)" 2>/dev/null
}

ensure_deps() {
  local dir="$1"
  if [ "${CS_NO_INSTALL:-0}" = "1" ]; then return; fi
  if [ -f "$dir/package.json" ] && [ ! -d "$dir/node_modules" ]; then
    warn "缺少 node_modules，自动执行 npm install ($dir) ..."
    ( cd "$dir" && npm install ) || warn "npm install 失败，请手动安装依赖"
  fi
  if [ -f "$dir/artisan" ] && [ ! -d "$dir/vendor" ]; then
    warn "缺少 vendor，自动执行 composer install ($dir) ..."
    ( cd "$dir" && composer install ) || warn "composer install 失败，请手动安装依赖"
  fi
}

start_service() {
  local name="$1"
  if is_running "$name"; then
    info "$name 已在运行 (pid $(cat "$(pid_file "$name")"))"; return 0
  fi
  local dir="$PROJECT_ROOT/${SVC_DIR[$name]}"
  if [ ! -d "$dir" ]; then err "$name: 目录不存在 $dir"; return 1; fi
  ensure_deps "$dir"
  local log pidf="$RUNTIME_DIR/$name.pid"
  log="$(log_file "$name")"
  info "启动 $name ..."
  # setsid 让命令成为新会话/进程组组长，停止时按进程组一键 kill
  setsid bash -c "cd '$dir' && ${SVC_CMD[$name]}" >"$log" 2>&1 &
  echo $! > "$pidf"
  sleep 1
  if is_running "$name"; then
    ok "$name 已启动 (pid $(cat "$pidf"))  → 日志: $log"
    [ -n "${SVC_URL[$name]}" ] && info "访问地址: ${SVC_URL[$name]}"
  else
    err "$name 启动失败，请查看日志: $log"
    rm -f "$pidf"
  fi
}

stop_service() {
  local name="$1" pf pid
  pf="$(pid_file "$name")"
  if [ ! -f "$pf" ]; then info "$name 未运行"; return 0; fi
  pid="$(cat "$pf")"
  if kill -0 "$pid" 2>/dev/null; then
    kill -- -"$pid" 2>/dev/null || kill "$pid" 2>/dev/null
    local i
    for i in $(seq 1 10); do kill -0 "$pid" 2>/dev/null || break; sleep 0.5; done
    kill -9 -- -"$pid" 2>/dev/null || kill -9 "$pid" 2>/dev/null
  fi
  rm -f "$pf"
  ok "$name 已停止"
}

# 解析目标：空=全部，frontend/backend=分组，否则单个服务名
resolve_targets() {
  case "${1:-}" in
    ""|all)       echo "$ALL_NAMES" ;;
    frontend)     echo "web admin" ;;
    backend)      echo "api queue scheduler" ;;
    *)            echo "$1" ;;
  esac
}

validate() {
  local n name="$1" found=0
  for n in $ALL_NAMES; do [ "$n" = "$name" ] && found=1; done
  [ "$found" = "1" ]
}

show_status() {
  printf "\n${C_B}CubeShop 服务状态${C_RST}\n"
  printf "  %-11s %-9s %-9s %s\n" "NAME" "GROUP" "STATUS" "URL"
  printf "  %-11s %-9s %-9s %s\n" "----" "-----" "------" "---"
  local n st_txt st_color url
  for n in $ALL_NAMES; do
    if is_running "$n"; then st_txt="running"; st_color="$C_G"; else st_txt="stopped"; st_color="$C_Y"; fi
    url="${SVC_URL[$n]:-—}"
    # 先按固定宽度打印名称/分组，再单独打印带颜色的状态，避免颜色转义影响列宽
    printf "  %-11s %-9s " "$n" "${SVC_GROUP[$n]}"
    printf "%b%-9s%b%s\n" "$st_color" "$st_txt" "$C_RST" "$url"
  done
  echo
}

show_logs() {
  local name="$1"
  if [ -z "$name" ]; then err "请指定服务名，例如: ./dev.sh logs web"; return 1; fi
  if ! validate "$name"; then err "未知服务: $name"; return 1; fi
  local log; log="$(log_file "$name")"
  if [ ! -f "$log" ]; then err "暂无日志: $log"; return 1; fi
  info "追踪 $name 日志 (Ctrl+C 退出):"
  tail -n 50 -f "$log"
}

usage() {
  cat <<EOF

CubeShop 开发服务管理器
用法: ./dev.sh <动作> [目标]

动作:
  list                       列出所有服务及状态
  start [all|frontend|backend|<name>]   启动服务（默认 all）
  stop  [all|frontend|backend|<name>]   停止服务（默认 all）
  restart [all|frontend|backend|<name>] 重启服务（默认 all）
  logs <name>               查看某服务日志（tail -f）
  help                      显示本帮助

目标:
  all       全部服务（默认）
  frontend  web + admin
  backend   api + queue + scheduler
  <name>    单个服务：web | admin | api | queue | scheduler

环境变量:
  CS_NO_INSTALL=1   跳过依赖自动安装（node_modules / vendor）

示例:
  ./dev.sh start backend      # 仅启动后端（api + queue + scheduler）
  ./dev.sh start web          # 仅启动前端 storefront
  ./dev.sh stop frontend      # 停止全部前端
  ./dev.sh list               # 查看状态

EOF
}

# ---------- 主分发 ----------
ACTION="${1:-help}"
TARGET="${2:-}"

case "$ACTION" in
  list|status)
    show_status ;;
  start|stop|restart)
    TARGETS="$(resolve_targets "$TARGET")"
    for s in $TARGETS; do
      if ! validate "$s"; then err "未知服务或分组: $s"; continue; fi
      case "$ACTION" in
        start)   start_service "$s" ;;
        stop)    stop_service "$s" ;;
        restart) stop_service "$s"; start_service "$s" ;;
      esac
    done
    [ "$ACTION" != "restart" ] && show_status ;;
  logs)
    show_logs "$TARGET" ;;
  help|-h|--help)
    usage ;;
  *)
    err "未知动作: $ACTION"; usage; exit 1 ;;
esac
