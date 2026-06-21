#!/bin/bash

echo "=== MOQ直发打单系统 - 快速启动脚本 ==="
echo ""

cd "$(dirname "$0")"

echo "[1/4] 初始化 SQLite 数据库..."
php backend/init_sqlite.php
echo ""

echo "[2/4] 安装前端依赖..."
cd frontend
if [ ! -d "node_modules" ]; then
    npm install
fi
cd ..
echo ""

echo "[3/4] 启动 PHP 后端服务 (端口 8080)..."
cd backend
php -S 0.0.0.0:8080 index_sqlite.php > /tmp/php_moq.log 2>&1 &
PHP_PID=$!
cd ..
echo "PHP 后端已启动，PID: $PHP_PID"
echo "API 地址: http://localhost:8080/api"
echo ""

sleep 1

echo "[4/4] 启动 Vue 前端开发服务 (端口 3000)..."
cd frontend
npm run dev > /tmp/vue_moq.log 2>&1 &
VUE_PID=$!
cd ..
echo "Vue 前端已启动，PID: $VUE_PID"
echo "前端地址: http://localhost:3000"
echo ""

echo "服务启动完成！"
echo "  - 前端: http://localhost:3000"
echo "  - 后端: http://localhost:8080/api"
echo ""
echo "停止服务请运行: kill $PHP_PID $VUE_PID"
echo "按 Ctrl+C 保持运行..."

trap "echo '正在停止服务...'; kill $PHP_PID $VUE_PID 2>/dev/null; exit 0" INT TERM

while true; do
    sleep 1
done
