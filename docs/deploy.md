# Розгортання на хостингу

Покроково, для шаред-хостингу з SSH і панеллю керування.

> **Поточний сервер проєкту:** cPanel, акаунт `h78693c`, хост `185.174.173.22`,
> домен `food.zp.ua`. PHP 8.4, git і composer встановлені глобально,
> символьні посилання дозволені.
>
> Сумісність із PHP 8.4 перевірена: уся тестова сюїта (207 тестів) проходить.

---

## 0. Два різні SSH-ключі — не переплутайте

Це місце, де плутаються найчастіше.

| Ключ | Навіщо | Де створюється |
|---|---|---|
| **Ваш особистий** | Щоб *ви* заходили на сервер із комп'ютера | На вашому комп'ютері |
| **Ключ сервера** | Щоб *сервер* міг тягнути код із репозиторію (`git pull`) | На сервері, через SSH |

Ключ, який ви створили в панелі хостингу, — це перший тип. Другий доведеться створити окремо, коли дійдемо до кроку 3.

### Перевірка, що вхід працює

Дані для підключення панель показує в розділі SSH: хост, порт (часто не 22, а 2222 або інший) і користувача.

```bash
ssh -p ПОРТ користувач@хост
```

Якщо панель дала вам файл ключа (`.pem`, `.key` або `id_rsa`), покладіть його в `~/.ssh/` на своєму комп'ютері і вкажіть явно:

```bash
ssh -i ~/.ssh/імʼя_ключа -p ПОРТ користувач@хост
```

У Windows це робиться у звичайному терміналі — ключ лежатиме в `C:\Users\ваше_імʼя\.ssh\`.

Щоб не писати щоразу довгу команду, додайте в `~/.ssh/config`:

```
Host school
    HostName хост.хостера
    User користувач
    Port ПОРТ
    IdentityFile ~/.ssh/імʼя_ключа
```

Далі досить `ssh school`.

---

## 1. Що перевірити на сервері перш за все

Зайдіть по SSH і виконайте:

```bash
php -v                    # потрібен 8.3 або новіший
php -m | grep -i "pdo_mysql\|mbstring\|gd\|intl\|zip\|bcmath\|curl\|fileinfo\|exif"
which composer git node npm
ln -s /tmp/test_target /tmp/test_link && echo "симлінки дозволені" && rm /tmp/test_link
```

Що з цим робити:

- **PHP нижче 8.3** — у панелі зазвичай можна перемкнути версію. Якщо максимум 8.1, проєкт не запуститься.
- **Немає `composer`** — спробуйте `composer2` або `php composer.phar`. Якщо немає зовсім, доведеться залити папку `vendor` з локальної машини.
- **Немає `node`/`npm`** — це нормально. Саме тому зібрані асети (`public_html/build`) лежать у репозиторії й збираються локально.
- **Симлінки заборонені** — скажіть мені, я зміню спосіб зберігання фото (це правка одного конфіга).

---

## 2. База даних

У панелі хостингу створіть базу й користувача. Запишіть назву, ім'я користувача й пароль — вони підуть у `.env`.

Кодування: **utf8mb4**, порівняння **utf8mb4_unicode_ci**. Інакше українські назви страв збережуться некоректно.

---

## 3. Код на сервер

### Ключ для доступу сервера до репозиторію

На **сервері**:

```bash
ssh-keygen -t ed25519 -C "school-food-deploy" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Виведений рядок додайте в репозиторій:

- **GitHub** — Settings → Deploy keys → Add deploy key (достатньо доступу лише на читання);
- **GitLab** — Settings → Repository → Deploy keys.

Перевірка:

```bash
ssh -T git@github.com
```

### Клонування

Визначте, куди хостинг дивиться document root. Найпоширеніші варіанти — `~/public_html` або `~/domains/сайт/public_html`.

**Варіант А — можна змінити document root:**

```bash
cd ~
git clone git@github.com:користувач/репозиторій.git school-food
```

Далі в панелі вкажіть document root на `~/school-food/public_html`.

**Варіант Б — document root змінити не можна** (це наш випадок із `food.zp.ua`).

Проєкт кладемо **поруч** із публічною папкою, а не всередину:

```
/home/h78693c/
   school-food/     ← код: app, config, vendor, storage
   public_html/     ← document root: лише index.php і статика
```

```bash
cd ~
git clone git@github.com:користувач/репозиторій.git school-food
cd school-food
bash scripts/link-public.sh
```

Скрипт покладе в `~/public_html` точку входу й посилання на зібрані асети.
Далі в `.env` обов'язково вкажіть:

```
APP_PUBLIC_PATH=/home/h78693c/public_html
```

Без цього рядка `storage:link` і асети Filament підуть у папку всередині
проєкту, куди вебсервер не дивиться — сайт відкриється без стилів,
а фото страв не показуватимуться.

> **Ніколи не клонуйте проєкт усередину `public_html`** — тоді `.env`
> із паролем до бази опиниться у відкритому доступі.

---

## 4. Налаштування

```bash
cd ~/school-food

composer install --no-dev --optimize-autoloader

cp .env.production.example .env
nano .env                 # база, пошта, APP_URL, APP_PUBLIC_PATH
php artisan key:generate

php artisan migrate --force
php artisan storage:link
php artisan filament:assets
php artisan db:seed --class=DatabaseSeeder --force
```

Перевірте, що посилання на фото створилося саме в публічній папці:

```bash
ls -la ~/public_html/storage
```

Має бути посилання на `~/school-food/storage/app/public`. Якщо його там немає,
а натомість з'явилося в `~/school-food/public_html/storage` — у `.env`
не заданий `APP_PUBLIC_PATH`.

Останній рядок створює адміністратора, класи, алергенів і двох тестових постачальників.
**Одразу після цього змініть пароль адміністратора** — у сідері він `secret`.

Права на запис:

```bash
chmod -R 775 storage bootstrap/cache
```

---

## 5. Кеш під бойовий режим

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:assets
```

Ці команди треба повторювати **після кожного оновлення коду**.

---

## 6. Cron

У панелі хостингу (розділ «Планувальник» або «Cron») додайте **один** запис із запуском щохвилини:

```
* * * * * cd ~/school-food && php artisan schedule:run >> /dev/null 2>&1
```

Через нього працює все: черга листів, вечірні зведення постачальникам, нагадування про дедлайни, повідомлення про скасування.

Якщо хостинг дозволяє мінімум раз на 5 хвилин — теж прийнятно, але вечірнє зведення надійде з похибкою до 5 хвилин.

Перевірка, що планувальник живий:

```bash
php artisan schedule:list
```

---

## 7. Telegram

Тільки після того, як сайт відкривається по HTTPS.

1. У [@BotFather](https://t.me/BotFather) — `/newbot`, отримайте токен.
2. Впишіть у `.env`: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`.
3. `php artisan config:cache && php artisan telegram:webhook`

Команда відмовиться реєструвати адресу без HTTPS — це обмеження Telegram, не наше.

---

## 8. Перевірка після запуску

- [ ] `https://домен` відкриває сторінку входу учня
- [ ] `/admin` і `/supplier` відкривають свої панелі
- [ ] у джерелі сторінки стилі підвантажуються (не «гола» верстка)
- [ ] фото страви відкривається за прямим посиланням `/storage/dishes/…`
- [ ] тестове замовлення проходить, лист приходить на пошту
- [ ] `https://домен/.env` віддає 403 або 404 — **не вміст файлу**
- [ ] `APP_DEBUG=false`: спеціально помилкова адреса показує звичайну сторінку помилки, а не трасування

---

## Оновлення коду надалі

Локально:

```bash
npm run build
git add . && git commit -m "опис змін" && git push
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

Публічну папку чіпати не треба: `index.php` не змінюється, а `build`
підключений посиланням, тож нова збірка підхоплюється сама.

Запускати `scripts/link-public.sh` повторно потрібно лише тоді, коли
змінився сам `index.php` або файли на кшталт `.htaccess`.

---

## Резервні копії

ТЗ вимагає щоденні копії зі зберіганням 30 днів (п. 15.3).

Якщо панель хостингу вміє робити бекапи сама — увімкніть і перевірте, що в копію входить **і база, і папка `storage/app/public`** (там фото страв).

Якщо ні — додайте другий запис у cron:

```
0 3 * * * cd ~/school-food && ./scripts/backup.sh >> storage/logs/backup.log 2>&1
```
