<div align="center">

# برقدار – ربات تلگرام اطلاع‌رسانی قطعی برق مازندران

ردیابی، جمع‌آوری و اطلاع‌رسانی زمان‌بندی قطعی برق بر اساس داده‌های رسمی شرکت توزیع نیروی برق مازندران (khamooshi.maztozi.ir).

</div>

## فهرست مطالب

- معرفی پروژه
- ویژگی‌ها
- نیازمندی‌ها
- نصب و راه‌اندازی
- پیکربندی تلگرام (Webhook)
- دستورات Artisan و زمان‌بندی
- مسیرها (Routes) و API
- ساختار کد و معماری
- طرح داده و جداول کلیدی
- توسعه، تست و کدنویسی
- نکات عیب‌یابی

## معرفی پروژه

این پروژه یک سرویس بک‌اند Laravel برای یک ربات تلگرام است که:
- برنامه‌های قطعی برق را از وب‌سایت رسمی استخراج می‌کند (Scraping).
- داده‌ها را استاندارد و ذخیره‌سازی می‌کند.
- از طریق ربات تلگرام، به کاربران درباره قطعی‌های امروز و آدرس‌های ذخیره‌شده‌شان اطلاع می‌دهد.

## ویژگی‌ها

- استخراج اطلاعات قطعی‌ها با `Symfony DomCrawler` و `HttpBrowser`.
- تبدیل تاریخ جلالی به میلادی و نرمال‌سازی زمان‌ها.
- شناسه یکتا برای هر قطعی (`outage_number`) بر اساس آدرس/تاریخ/ساعت.
- مدیریت چند آدرس برای هر کاربر و فعال/غیرفعال‌سازی اعلان برای هر آدرس.
- جریان گفت‌وگویی ربات: تایید شماره موبایل، افزودن/مدیریت آدرس‌ها، مشاهده وضعیت امروز.
- تست‌های واحد برای هسته‌های حیاتی (نرمال‌سازی موبایل، ذخیره‌ی وضعیت).

## نیازمندی‌ها

- PHP 8.2+
- Composer
- MySQL/MariaDB
- OpenSSL, cURL, mbstring, intl
- Node.js (اختیاری، فقط برای دارایی‌های فرانت‌اند)

## نصب و راه‌اندازی

1) نصب وابستگی‌ها:

```bash
composer install
```

2) ایجاد فایل محیط و کلید برنامه:

```bash
cp .env.example .env
php artisan key:generate
```

3) تنظیم متغیرهای محیطی ضروری در `.env`:

```dotenv
APP_NAME=BarghAlarm
APP_ENV=local
APP_URL=https://your-domain.example
APP_TIMEZONE=Asia/Tehran

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=barghalarm
DB_USERNAME=your_user
DB_PASSWORD=your_pass

TELEGRAM_BOT_TOKEN=123456:ABC-DEF...
```

4) اجرای مایگریشن‌ها:

```bash
php artisan migrate
```

5) پر کردن داده‌های پایه شهر/منطقه/آدرس:

- جدول‌های `cities` و `areas` باید با داده‌های واقعی استان/شهرستان‌ها پر شوند.
- جدول `addresses` باید شامل «متن آدرس» دقیقا منطبق با خروجی سایت رسمی باشد تا نگاشت آدرس‌ها درست انجام شود.
- برای توسعه، یک مسیر آزمایشی جهت استخراج و ثبت آدرس‌ها وجود دارد: `GET /save-address` (صرفاً برای محیط توسعه).

## پیکربندی تلگرام (Webhook)

1) دامنه‌ی HTTPS معتبر روی سرور تنظیم کنید و `APP_URL` را مطابق دامنه تنظیم نمایید.

2) ثبت Webhook با ربات:

- روش ۱: فراخوانی داخلی پروژه

  - مسیر: `GET /set` – URL وبهوک را با استفاده از هدر `X-Forwarded-Host` به صورت خودکار تنظیم می‌کند.
  - مسیر: `GET /info` – وضعیت وبهوک فعلی.

- روش ۲: دستی با Bot API

```bash
curl -X POST "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/setWebhook" \
     -d url="https://your-domain.example/telegram/bot"
```

3) آدرس دریافت بروزرسانی‌ها در این سرویس: `POST /telegram/bot`

برای تست محلی، یک نمونه‌ی ساده در `POST /api/telegram/webhook` نیز وجود دارد که پیام «Hello, world!» ارسال می‌کند.

## دستورات Artisan و زمان‌بندی

- وارد کردن برنامه‌های قطعی امروز (تاریخ جلالی):

```bash
php artisan blackouts:import
```

- ارسال اعلان به کاربران برای تاریخ مشخص (پیش‌فرض امروز، فرمت YYYY-MM-DD):

```bash
php artisan blackouts:check --date=2025-08-22
```

- زمان‌بندی پیشنهادی (Linux cron):

```cron
# به‌روزرسانی برنامه‌های قطعی هر 2 ساعت
0 */2 * * * cd /path/to/app && php artisan blackouts:import >> storage/logs/cron.log 2>&1

# ارسال اعلان‌های روزانه ساعت 07:00
0 7 * * * cd /path/to/app && php artisan blackouts:check >> storage/logs/cron.log 2>&1
```

توجه: در `app/Console/Kernel.php` نمونه‌ی زمان‌بندی کامنت شده است؛ برای استفاده، آن را مطابق نیاز فعال کنید.

## مسیرها (Routes) و API

- وب‌هوک تلگرام: `POST /telegram/bot`
- ثبت وبهوک: `GET /set`
- اطلاعات وبهوک: `GET /info`
- مسیر آزمایشی اسکرپینگ: `GET /test?area=3&from=1404/05/30&to=1404/05/30`
- نمونه‌ی تست محلی تلگرام: `POST /api/telegram/webhook`

## ساختار کد و معماری

- Services
  - `App\Services\Scraper\OutageScraper`: استخراج نتایج از khamooshi.maztozi.ir با DomCrawler.
  - `App\Services\Blackout\BlackoutImporter`: پردازش/تبدیل و درج یا به‌روزرسانی رکوردهای قطعی.
  - `App\Services\Telegram\TelegramService`: کاینت Bot API (ارسال پیام/کیبورد/وبهوک...).
  - `App\Services\Telegram\TelegramUpdateDispatcher`: هماهنگ‌کننده‌ی منطق ربات (start/help/منو/تماس/کالبک‌ها).
  - `App\Services\Telegram\MenuService`: ساخت و ارسال منوها و کیبوردها.
  - `App\Services\Telegram\StateStore`: ذخیره‌ی موقت وضعیت مکالمه در Cache.
  - `App\Services\Telegram\PhoneNumberNormalizer`: نرمال‌سازی موبایل ایران (+989XXXXXXXXX).

- Commands
  - `blackouts:import` و `blackouts:check` برای ورود داده و اعلان.

- Controllers
  - `App\Http\Controllers\Api\TelegramController`: مدیریت ثبت وبهوک و دیسپچ به ربات.

## طرح داده و جداول کلیدی

- `cities(id, name_fa, name_en, code)` – شهرها.
- `areas(id, city_id, name, code)` – نواحی مرتبط با شهر.
- `addresses(id, city_id, address, code)` – متن آدرس دقیق؛ «باید» با متن اسکرپ‌شده منطبق باشد.
- `blackouts(id, area_id, city_id, address_id, outage_date, outage_start_time, outage_end_time, description, status, outage_number)` – برنامه‌های قطعی.
- `adress_user(user_id, address_id, name, is_active)` – آدرس‌های کاربر و تنظیم فعال/غیرفعال اعلان. (توجه: نام جدول «adress» است.)

نکته: مقدار `outage_number` به‌صورت قطعی و تکرارپذیر از ترکیب فیلدها تولید می‌شود تا از ثبت تکراری جلوگیری شود.

## توسعه، تست و کدنویسی

- اجرای تست‌ها:

```bash
phpunit
# یا
vendor/bin/phpunit
```

- فرمت کد PHP با Laravel Pint:

```bash
vendor/bin/pint --dirty
```

- نسخه‌ها: Laravel v12، PHP 8.2، Tailwind v4 (اختیاری برای فرانت). منطقه‌ی زمانی و زبان پیش‌فرض: `Asia/Tehran` و `fa`.

## نکات عیب‌یابی

- وبهوک کار نمی‌کند: مطمئن شوید دامنه HTTPS معتبر است و `TELEGRAM_BOT_TOKEN` درست تنظیم شده. مسیر صحیح وبهوک باید `https://YOUR_DOMAIN/telegram/bot` باشد.
- خالی بودن نتایج اسکرپینگ: بررسی کنید پارامترهای `from/to` (جلالی `Y/m/d`) و `area` درست باشند و سایت مرجع در دسترس باشد.
- نگاشت آدرس پیدا نمی‌شود: متن آدرس در `addresses.address` باید دقیقاً با خروجی سایت مرجع یکسان باشد.

---

سلب مسئولیت: این سرویس مستقل از شرکت توزیع نیروی برق مازندران است و صرفاً داده‌های عمومی را گردآوری و بازنشر می‌کند. در صورت تاخیر/نقص داده‌ی منبع رسمی، ممکن است اعلان‌ها کامل نباشند.
