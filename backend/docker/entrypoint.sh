#!/bin/sh
set -e

# 等待 PostgreSQL 就绪
until php -r '
$try = 0;
while ($try < 30) {
    if (@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT"), $errno, $errstr, 2)) { exit(0); }
    sleep(1); $try++;
}
exit(1);
'; do
  echo "Waiting for database..."
  sleep 2
done

if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --no-ansi
fi

# 执行数据库迁移（生产环境强制）
php artisan migrate --force --no-ansi

# 启动
exec "$@"
