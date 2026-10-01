# CloudTart Support Services Plugin

افزونه‌ی وردپرس **خدمات پشتیبانی کلادتارت** — ابزارهای پشتیبانی، شخصی‌سازی‌ها و ماژول‌های کاربردی که شرکت طراحی وب [کلادتارت](https://www.cloudtart.com) روی سایت مشتریانش نصب می‌کند.

این افزونه رایگان و متن‌باز است و هر سایت وردپرسی می‌تواند از ماژول‌های آن استفاده کند. ارتباط امن با «هسته‌ی پشتیبانی» کلادتارت (ارسال آپدیت‌های امضاشده، گزارش نسخه‌ها و لاگ خطاها) در افزونه‌ی جداگانه و خصوصی **CloudTart Support Connector** قرار دارد که فقط روی سایت مشتریان دارای قرارداد پشتیبانی نصب می‌شود؛ این افزونه به‌تنهایی هیچ endpoint راه‌دوری برای مدیریت سایت باز نمی‌کند.

> English summary below.

## امکانات

### پشتیبانی و نگهداری
- **یادآوری انقضای دامنه و هاست** — ثبت تاریخ انقضای دامنه‌ی اصلی، دامنه‌های جانبی و هاست، نمایش وضعیت در پیشخوان و ارسال ایمیل یادآوری چند روز قبل از انقضا.
- **مانیتورینگ** — بررسی دوره‌ای در دسترس بودن سایت، ثبت خطاهای مهم PHP (با محدودسازی تا روی سرعت سایت اثر نگذارد) و اطلاع‌رسانی ایمیلی خطاهای Fatal.
- **لاگ‌ها** — ثبت رویدادهای افزونه در فایل محافظت‌شده با امکان مشاهده، دانلود و پاک‌سازی از پیشخوان.
- **بروزرسانی خودکار** — دریافت نسخه‌های جدید از سرور آپدیت کلادتارت از طریق سیستم استاندارد آپدیت وردپرس.

### اینترنت داخلی (CDN داخلی / حالت اینترانت)
برای زمان‌هایی که اینترنت بین‌الملل قطع است و فقط شبکه‌ی ملی در دسترس است — همه‌ی گزینه‌ها به‌صورت پیش‌فرض خاموش‌اند:
- هدایت کتابخانه‌ها، فونت‌ها و فایل‌های CDN خارجی (Google Fonts، Font Awesome، cdnjs، jsDelivr و…) به نسخه‌های محلی همراه افزونه یا فایل‌های آپلودشده.
- مسدودسازی فایل‌های خارجی تعریف‌نشده در صفحات تا سایت و پیشخوان در «بارگذاری بی‌پایان» نمانند.
- **حالت اینترانت:** پاسخ فوری به درخواست‌های سرور به میزبان‌های خارجی (بررسی آپدیت وردپرس، افزونه‌ها، لایسنس‌ها و…) به‌جای انتظار تا پایان مهلت؛ دامنه‌های ‎.ir‎، آدرس‌های محلی و فهرست دامنه‌ها و کلیدواژه‌های مجاز (درگاه‌های پرداخت، پنل‌های پیامک) همچنان کار می‌کنند.
- جایگزینی آواتار Gravatar با تصویر محلی، غیرفعال‌کردن ایموجی راه‌دور و حذف preconnect/dns-prefetch به دامنه‌های خارجی.

### ماژول‌های ووکامرس و افزونه‌های جانبی (قابل فعال/غیرفعال‌سازی)
- **پخش آنلاین فایل‌های صوتی ووکامرس** — فایل‌های صوتی خریداری‌شده در «دانلودها»ی حساب کاربری با پلیر اختصاصی (لیست پخش، تم روشن/تیره، پشتیبانی از جلو/عقب‌بردن) پخش می‌شوند؛ دانلود مستقیم در صورت تنظیم مسدود است و فایل از طریق لینک امضاشده‌ی موقت استریم می‌شود.
- **درگاه پیامک IPPanel / ایران‌پیامک برای Digits**.
- **درگاه پیامک ایران‌پیامک (فراز اس‌ام‌اس) برای افزونه‌ی پیامک ووکامرس فارسی**.
- **اصلاح کوپن در Dokan Multivendor**.
- **ثبت درخواست‌های برداشت کیف پول FS WooCommerce Wallet به‌صورت سفارش**.

### سایر
- چندزبانه: فارسی، انگلیسی، عربی، آلمانی، فرانسوی و ترکی.
- سازگار با PHP 7.4 تا 8.5 و بدون هیچ کوئری دیتابیس اضافه در بازدیدهای عادی سایت.

## نصب

1. آخرین نسخه را از بخش Releases یا از `https://up.cloudtart.com/cloudtart-support.zip` دریافت کنید.
2. در وردپرس: افزونه‌ها ← افزودن ← بارگذاری افزونه.
3. تنظیمات در «تنظیمات ← CloudTart Support» قرار دارد.

نیازمندی‌ها: وردپرس 5.0 یا بالاتر، PHP 7.4 یا بالاتر. ماژول‌های ووکامرس فقط وقتی ووکامرس فعال است بارگذاری می‌شوند.

## پشتیبانی

این افزونه برای پشتیبانی از مشتریان کلادتارت توسعه داده می‌شود. برای قرارداد پشتیبانی، طراحی سایت و خدمات نگهداری به [cloudtart.com](https://www.cloudtart.com) مراجعه کنید. گزارش باگ و پیشنهاد از طریق Issues همین مخزن پذیرفته می‌شود.

---

## English

**CloudTart Support Services Plugin** is the WordPress plugin that the web design company [CloudTart](https://www.cloudtart.com) installs on its clients' sites. It is free and open source; any WordPress site can use its modules.

The secure link to CloudTart's support core (signed update delivery, version inventory and fatal error reports) lives in a separate, private companion plugin (*CloudTart Support Connector*) that is installed only on sites with a support contract. This plugin on its own registers no remote management endpoints.

**Features**
- Domain and hosting expiry reminders (dashboard notices and e-mail).
- Uptime checks, throttled PHP error logging and fatal error e-mail alerts.
- Protected log files with view/download/clear from the dashboard.
- Updates through the standard WordPress updater.
- *Internal CDN / intranet mode* (all off by default) for national-network-only periods: redirect external libraries and fonts to bundled local copies, block undefined external assets, answer outbound server requests to foreign hosts instantly instead of hanging, while `.ir` domains, local addresses and allow-listed payment/SMS hosts keep working.
- WooCommerce audio streaming player for purchased audio downloads (signed, expiring stream links; optional download blocking).
- SMS gateways for Digits (IPPanel/IranPayamak) and Persian WooCommerce SMS (IranPayamak/FarazSMS), a Dokan coupon fix and FS Wallet withdrawals-as-orders.
- Translations: Persian, English, Arabic, German, French, Turkish. PHP 7.4–8.5.

**Requirements:** WordPress 5.0+, PHP 7.4+.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). Bundled third-party assets in `assets/local-cdn-assets/` keep their own licenses (e.g. Font Awesome Free: OFL-1.1/MIT/CC BY 4.0; Google Fonts: OFL-1.1/Apache-2.0).
