#!/bin/bash
set -e

cd /var/www/html

# ── Primera instalación: crear proyecto Laravel base ──────────────
if [ ! -f artisan ]; then
    echo "=== Primera instalación: creando proyecto Laravel base... ==="
    composer create-project laravel/laravel /tmp/laravel-base --prefer-dist
    cp -rn /tmp/laravel-base/. /var/www/html/
    rm -rf /tmp/laravel-base
    echo "=== Proyecto Laravel base creado OK ==="
fi

# ── Dependencias ──────────────────────────────────────────────────
composer install --no-interaction --prefer-dist

# ── .env: crear si no existe ──────────────────────────────────────
if [ ! -f .env ]; then
    cp .env.example .env
fi

# ── Forzar BD a MySQL con los valores del entorno Docker ──────────
# Laravel 11 genera .env con DB_CONNECTION=sqlite por defecto.
# Reemplazamos todas las líneas DB_* con los valores correctos.
{
    grep -v "^DB_" .env
    echo "DB_CONNECTION=${DB_CONNECTION:-mysql}"
    echo "DB_HOST=${DB_HOST:-db}"
    echo "DB_PORT=${DB_PORT:-3306}"
    echo "DB_DATABASE=${DB_DATABASE:-sysmon}"
    echo "DB_USERNAME=${DB_USERNAME:-sysmon}"
    echo "DB_PASSWORD=${DB_PASSWORD:-sysmon_pass}"
} > .env.tmp && mv .env.tmp .env

php artisan key:generate --force
php artisan config:clear

# ── Base de datos ─────────────────────────────────────────────────
php artisan migrate --force
php artisan db:seed --class=SysMonSeeder --force

# ── Scheduler en background ───────────────────────────────────────
php artisan schedule:work &

# ── Servidor ──────────────────────────────────────────────────────
exec php artisan serve --host=0.0.0.0 --port=8000
