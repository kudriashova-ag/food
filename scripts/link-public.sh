#!/bin/bash
#
# Готує зовнішню публічну папку там, де document root змінити не можна.
#
# Розкладка, яку створює скрипт:
#   ~/school-food/     ← код проєкту (ця папка)
#   ~/public_html/     ← document root: index.php + посилання на статику
#
# Запуск із кореня проєкту:
#   bash scripts/link-public.sh
#
# Скрипт безпечно запускати повторно — він лише оновлює те, що змінилося.

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PUBLIC_DIR="${1:-$HOME/public_html}"

echo "Проєкт:          $APP_DIR"
echo "Публічна папка:  $PUBLIC_DIR"
echo ""

if [ ! -d "$PUBLIC_DIR" ]; then
    echo "ПОМИЛКА: папки $PUBLIC_DIR не існує."
    echo "Вкажіть правильний шлях аргументом: bash scripts/link-public.sh ~/шлях"
    exit 1
fi

# --- Точка входу ---
# Шлях до проєкту підставляємо на місці, щоб файл працював за будь-якої назви папки.
APP_FOLDER_NAME="$(basename "$APP_DIR")"

sed "s|__DIR__.'/../school-food'|__DIR__.'/../${APP_FOLDER_NAME}'|" \
    "${APP_DIR}/deploy/public_html/index.php" > "${PUBLIC_DIR}/index.php"

echo "✓ index.php"

# --- Статика, яку віддає вебсервер напряму ---
for item in .htaccess favicon.ico robots.txt; do
    if [ -f "${APP_DIR}/public_html/${item}" ]; then
        cp "${APP_DIR}/public_html/${item}" "${PUBLIC_DIR}/${item}"
        echo "✓ ${item}"
    fi
done

# --- Зібрані асети ---
# Саме посилання, а не копія: після git pull і npm run build
# нова збірка підхопиться сама.
if [ -d "${APP_DIR}/public_html/build" ]; then
    rm -rf "${PUBLIC_DIR}/build"
    ln -s "${APP_DIR}/public_html/build" "${PUBLIC_DIR}/build"
    echo "✓ build → посилання на проєкт"
fi

echo ""
echo "Далі, з кореня проєкту:"
echo "  php artisan storage:link      # фото страв"
echo "  php artisan filament:assets   # стилі адмінпанелі"
echo ""
echo "Переконайтеся, що в .env заданий шлях:"
echo "  APP_PUBLIC_PATH=${PUBLIC_DIR}"
