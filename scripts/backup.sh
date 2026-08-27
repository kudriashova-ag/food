#!/bin/bash
#
# Щоденна резервна копія: база даних і завантажені файли (ТЗ, п. 15.3).
# Копії старші за 30 днів прибираються автоматично.
#
# Запуск із cron:
#   0 3 * * * cd ~/school-food && ./scripts/backup.sh >> storage/logs/backup.log 2>&1

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${APP_DIR}/../backups"
KEEP_DAYS=30
STAMP="$(date +%Y-%m-%d_%H%M)"

cd "$APP_DIR"

# Дані підключення читаємо з .env, щоб не дублювати паролі в скрипті.
get_env() {
    grep -E "^$1=" .env | head -1 | cut -d '=' -f2- | tr -d '"' | tr -d "'"
}

DB_NAME="$(get_env DB_DATABASE)"
DB_USER="$(get_env DB_USERNAME)"
DB_PASS="$(get_env DB_PASSWORD)"
DB_HOST="$(get_env DB_HOST)"

if [ -z "$DB_NAME" ]; then
    echo "[$(date)] ПОМИЛКА: не вдалося прочитати DB_DATABASE з .env"
    exit 1
fi

mkdir -p "$BACKUP_DIR"

# --- База ---
DUMP_FILE="${BACKUP_DIR}/db_${STAMP}.sql.gz"

mysqldump \
    --host="${DB_HOST:-127.0.0.1}" \
    --user="$DB_USER" \
    --password="$DB_PASS" \
    --single-transaction \
    --quick \
    --default-character-set=utf8mb4 \
    "$DB_NAME" | gzip > "$DUMP_FILE"

echo "[$(date)] База: $(basename "$DUMP_FILE") ($(du -h "$DUMP_FILE" | cut -f1))"

# --- Завантажені файли ---
# Саме storage/app/public: фото страв і логотипи. Зібрані асети не потрібні —
# вони відновлюються з репозиторію.
FILES_FILE="${BACKUP_DIR}/files_${STAMP}.tar.gz"

if [ -d storage/app/public ]; then
    tar -czf "$FILES_FILE" -C storage/app public
    echo "[$(date)] Файли: $(basename "$FILES_FILE") ($(du -h "$FILES_FILE" | cut -f1))"
fi

# --- Прибирання старих копій ---
DELETED=$(find "$BACKUP_DIR" -name "db_*.sql.gz" -o -name "files_*.tar.gz" | wc -l)
find "$BACKUP_DIR" -name "db_*.sql.gz" -mtime "+${KEEP_DAYS}" -delete
find "$BACKUP_DIR" -name "files_*.tar.gz" -mtime "+${KEEP_DAYS}" -delete
REMAINING=$(find "$BACKUP_DIR" -name "db_*.sql.gz" -o -name "files_*.tar.gz" | wc -l)

echo "[$(date)] Готово. Копій у сховищі: ${REMAINING} (було ${DELETED})"
