#!/usr/bin/env bash
# 启动 CubeShop 后端开发服务（绝对路径，不依赖调用方工作目录）
cd "$(dirname "$0")/../../backend" || exit 1
mkdir -p storage/logs
# 若已在监听则跳过
if netstat -ano | grep ':8000' | grep -q LISTEN; then
  echo "ALREADY_LISTENING"
  exit 0
fi
(php artisan serve --host=127.0.0.1 --port=8000 > storage/logs/serve.log 2>&1 &)
for i in 1 2 3 4 5 6 7 8; do
  sleep 1
  if netstat -ano | grep ':8000' | grep -q LISTEN; then
    echo "SERVER_UP"
    curl -s --noproxy '*' http://127.0.0.1:8000/api/health | head -c 80
    echo
    exit 0
  fi
done
echo "SERVER_FAILED"
tail -5 storage/logs/serve.log 2>/dev/null
exit 1
