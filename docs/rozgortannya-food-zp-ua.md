# Розгортання food.zp.ua — покроково

Готова послідовність для вашого сервера: cPanel, акаунт `h78693c`,
PHP 8.4, document root змінити не можна.

Виконуйте блоками, звіряючи вивід. Якщо щось пішло не так — зупиніться
й покажіть мені вивід, не йдіть далі.

---

## Крок 1. Перевірка оточення

```bash
php -v
which php composer git
php -m | grep -iE "pdo_mysql|mbstring|gd|intl|zip|bcmath|curl|fileinfo|exif"
```

Очікуємо PHP 8.4 і всі дев'ять розширень.

Якщо **немає `exif`** — cPanel → MultiPHP INI Editor → оберіть домен →
увімкніть `exif` → Apply. Без нього не працюватиме обробка фотографій страв.

---

## Крок 2. Ключ для доступу до репозиторію

Репозиторій приватний, тож серверу потрібен власний ключ.

```bash
ssh-keygen -t ed25519 -C "food-deploy" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Скопіюйте виведений рядок і додайте його на GitHub:

**Репозиторій → Settings → Deploy keys → Add deploy key.**
Назва — `hosting`, галочку «Allow write access» **не ставте** — серверу
потрібно лише читати.

Перевірка:

```bash
ssh -T git@github.com
```

Має відповісти `Hi kudriashova-ag/food! You've successfully authenticated`.

---

## Крок 3. Клонування

```bash
cd ~
git clone git@github.com:kudriashova-ag/food.git school-food
cd school-food
composer install --no-dev --optimize-autoloader
```

Встановлення залежностей триває кілька хвилин.

---

## Крок 4. Публічна папка

```bash
bash scripts/link-public.sh
ls -la ~/public_html/
```

Має з'явитися `index.php` і посилання `build`.

> Якщо в `~/public_html` уже лежить інший сайт — **зупиніться і скажіть мені**.
> Скрипт перезапише `index.php`.

---

## Крок 5. Налаштування

```bash
cp .env.production.example .env
nano .env
```

Заповніть:

```
APP_URL=https://food.zp.ua
APP_PUBLIC_PATH=/home/h78693c/public_html

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=h78693c_food
DB_USERNAME=h78693c_food
DB_PASSWORD=новий_пароль_бази
```

Пошту поки можна лишити як є — налаштуємо після того, як сайт відкриється.

Збереження в `nano`: `Ctrl+O`, `Enter`, `Ctrl+X`.

Далі:

```bash
php artisan key:generate
```

---

## Крок 6. База даних

```bash
php artisan migrate --force
php artisan db:seed --class=DatabaseSeeder --force
```

Створяться таблиці, адміністратор, класи, алергени й два тестові постачальники.

---

## Крок 7. Статика й кеш

```bash
php artisan storage:link
php artisan filament:assets
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Перевірка, що посилання пішло в правильну папку:

```bash
ls -la ~/public_html/storage
```

Має вказувати на `/home/h78693c/school-food/storage/app/public`.
Якщо його там немає — у `.env` не заданий `APP_PUBLIC_PATH`.

---

## Крок 8. Права

```bash
chmod -R 775 storage bootstrap/cache
```

---

## Крок 9. Перевірка в браузері

Відкрийте **https://food.zp.ua**

- [ ] сторінка входу учня зі стилями, не «гола» верстка
- [ ] `/admin` — панель адміністратора
- [ ] `/supplier` — панель постачальника
- [ ] `https://food.zp.ua/.env` → **403 або 404**, а не текст файлу

Вхід: `admin@school.local` / `secret` — **одразу змініть пароль**
у cPanel не вийде, зробіть це так:

```bash
php artisan tinker --execute="
\App\Models\User::where('email','admin@school.local')
    ->update(['password' => bcrypt('ВАШ_НОВИЙ_ПАРОЛЬ')]);
echo 'готово';
"
```

---

## Крок 10. Розклад

cPanel → **Cron Jobs** → Add New Cron Job, інтервал «Once Per Minute»:

```
cd /home/h78693c/school-food && php artisan schedule:run >> /dev/null 2>&1
```

Через нього працює все відкладене: листи, вечірні зведення постачальникам,
нагадування про дедлайни.

Перевірка:

```bash
cd ~/school-food && php artisan schedule:list
```

---

## Крок 11. Пошта

У `.env` впишіть дані SMTP (видає хостинг або поштовий сервіс школи):

```
MAIL_MAILER=smtp
MAIL_HOST=mail.food.zp.ua
MAIL_PORT=587
MAIL_USERNAME=harchuvannya@food.zp.ua
MAIL_PASSWORD=пароль
MAIL_FROM_ADDRESS="harchuvannya@food.zp.ua"
```

Після зміни `.env` **обов'язково**:

```bash
php artisan config:cache
```

Перевірка:

```bash
php artisan tinker --execute="
\Illuminate\Support\Facades\Mail::raw('Перевірка', fn(\$m) =>
    \$m->to('ваша@пошта')->subject('Тест'));
echo 'надіслано';
"
```

---

## Крок 12. Telegram

Тільки після того, як сайт відкривається по HTTPS.

1. У [@BotFather](https://t.me/BotFather) — `/newbot`, отримайте токен
2. У `.env`:
   ```
   TELEGRAM_BOT_TOKEN=токен
   TELEGRAM_BOT_USERNAME=імʼя_бота_без_@
   TELEGRAM_WEBHOOK_SECRET=довгий_випадковий_рядок
   ```
   Згенерувати секрет: `php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"`
3. ```bash
   php artisan config:cache
   php artisan telegram:webhook
   ```

---

## Оновлення надалі

Локально:

```bash
npm run build
git add . && git commit -m "опис" && git push
```

На сервері:

```bash
cd ~/school-food
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan filament:assets
```
