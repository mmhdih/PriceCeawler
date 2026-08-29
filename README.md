<div align="right">

# کراولر قیمت — PriceCeawler

**گزارش روزانه قیمت طلا، سکه، ارز و رمزارز از [TGJU](https://www.tgju.org)**
با رابط کاربری فارسی، خروجی اکسل، و نسخه پرتابل ویندوز.

[![تست‌ها](https://github.com/mmhdih/PriceCeawler/actions/workflows/ci.yml/badge.svg)](https://github.com/mmhdih/PriceCeawler/actions/workflows/ci.yml)
[![انتشار نسخه پرتابل](https://github.com/mmhdih/PriceCeawler/actions/workflows/release.yml/badge.svg)](https://github.com/mmhdih/PriceCeawler/actions/workflows/release.yml)

</div>

---

## دریافت سریع

آخرین فایل `PriceCeawler-vX.Y.Z-windows-x64.exe` را از بخش
[Releases](https://github.com/mmhdih/PriceCeawler/releases/latest) دانلود و اجرا کنید.

* نیازی به نصب پایتون یا هیچ پیش‌نیاز دیگری نیست.
* رابط کاربری به‌طور خودکار در مرورگر پیش‌فرض شما باز می‌شود.
* تنظیمات، حافظه موقت و آرشیو در پوشه `PriceCeawler-Data` **کنار همان فایل exe** ساخته می‌شود؛
  بنابراین می‌توانید کل پوشه را روی فلش‌مموری ببرید.
* درستی فایل دانلودشده را می‌توانید با فایل `.sha256` کنار آن بررسی کنید.

---

## امکانات

| | |
| --- | --- |
| **۳۲ نماد آماده** | طلا و نقره، سکه، ارز، رمزارز، نفت و کالا — به‌همراه امکان افزودن هر نماد دلخواه TGJU |
| **تاریخ شمسی** | انتخاب بازه با میان‌برهای «۷ روز، ۱ ماه، ۳ ماه، ۶ ماه، ۱ سال، این ماه، از ابتدای سال» |
| **پرکردن روزهای تعطیل** | روزهای بدون معامله با قیمت آخرین روز کاری پر می‌شوند و در ستون «وضعیت» مشخص‌اند |
| **نمودار مقایسه‌ای** | نمایش «درصد تغییر» برای مقایسه نمادهایی با مقیاس‌های متفاوت، یا «قیمت مطلق» |
| **خروجی اکسل** | فایل `.xlsx` راست‌به‌چپ با یک شیت برای هر نماد و یک شیت «خلاصه گزارش» — به‌همراه CSV و JSON |
| **آرشیو روزانه** | هر بار دریافت داده، روی همین رایانه ذخیره می‌شود تا سابقه از دست نرود |
| **کراول خودکار** | در اولین اجرای هر روز، آرشیو نمادهای انتخابی به‌روز می‌شود |
| **حالت روشن و تاریک** | به‌همراه فونت **Vazirmatn** که داخل خود فایل exe بسته‌بندی شده (بدون نیاز به اینترنت برای فونت) |

### واحد قیمت‌ها

TGJU قیمت بازار داخلی را به **ریال** می‌دهد؛ این برنامه همه آن‌ها را به **تومان** تبدیل می‌کند.
نمادهای جهانی (انس طلا، رمزارزها، نفت) به **دلار** و بدون تبدیل نمایش داده می‌شوند.

---

## اجرا از روی کد

```bash
git clone https://github.com/mmhdih/PriceCeawler.git
cd PriceCeawler
pip install -r requirements.txt

python run.py                 # رابط کاربری روی http://127.0.0.1:8770
```

تنها وابستگی بیرونی `openpyxl` (برای ساخت فایل اکسل) است؛ بقیه برنامه فقط از کتابخانه استاندارد پایتون
استفاده می‌کند — تقویم شمسی، کلاینت TGJU و وب‌سرور همگی داخل همین مخزن پیاده‌سازی شده‌اند.
حداقل نسخه پایتون: **۳٫۱۱**

### خط فرمان

```bash
python run.py --port 9000 --no-browser        # اجرای سرور روی پورت دلخواه بدون باز کردن مرورگر
python run.py doctor                          # بررسی سلامت برنامه و اتصال به TGJU
python run.py crawl --symbols geram18 sekee   # کراول روزانه بدون رابط کاربری
python run.py export --symbols geram18 \
    --start 1404/01/01 --end 1404/06/31 --format xlsx --output gold.xlsx
```

با نسخه پرتابل هم دقیقاً همین دستورها کار می‌کنند:
`PriceCeawler.exe crawl --symbols geram18`

### کراول خودکار روزانه با Task Scheduler ویندوز

```powershell
schtasks /create /tn "PriceCeawler Daily" /tr "C:\Apps\PriceCeawler.exe crawl" /sc daily /st 09:00
```

---

## ساخت نسخه پرتابل به‌صورت محلی

```bash
pip install -r requirements-dev.txt
python scripts/version_info.py          # ساخت اطلاعات نسخه ویندوز
pyinstaller --clean --noconfirm PriceCeawler.spec
# نتیجه: dist/PriceCeawler.exe  (حدود ۹ مگابایت، تک‌فایل)
```

## انتشار خودکار

گردش‌کار [`.github/workflows/release.yml`](.github/workflows/release.yml) با هر push روی `main`
شماره نسخه را از `priceceawler/version.py` می‌خواند:

1. اگر تگ `v{نسخه}` از قبل وجود داشته باشد، هیچ کاری انجام نمی‌شود.
2. در غیر این صورت تست‌ها اجرا، فایل `.exe` روی `windows-latest` ساخته،
   سلامت آن بررسی (`--version`، `doctor --offline`، بالا آمدن سرور و سرو شدن فونت)،
   و به‌همراه چک‌سام SHA-256 در یک Release منتشر می‌شود.

بنابراین **برای انتشار نسخه تازه فقط کافی است `__version__` را در
[`priceceawler/version.py`](priceceawler/version.py) بالا ببرید و push کنید.**

روی شاخه‌های دیگر و Pull Requestها، گردش‌کار [`ci.yml`](.github/workflows/ci.yml) تست‌ها را
روی لینوکس و ویندوز اجرا می‌کند و یک `.exe` آزمایشی به‌عنوان artifact می‌سازد (بدون انتشار Release).

---

## ساختار پروژه

```
priceceawler/
├── version.py     شماره نسخه — تنها منبع حقیقت برای گردش‌کار انتشار
├── jalali.py      تقویم شمسی (پیاده‌سازی خالص پایتون، بدون وابستگی)
├── symbols.py     فهرست نمادهای TGJU و واحد قیمت هرکدام
├── tgju.py        کلاینت API با تلاش مجدد و تحلیل مقاوم پاسخ
├── crawler.py     هماهنگی دریافت موازی، حافظه موقت و آرشیو
├── report.py      ساخت سری روزانه و خروجی‌های xlsx / csv / json
├── storage.py     تنظیمات، حافظه موقت و آرشیو کنار فایل اجرایی
├── server.py      وب‌سرور محلی و API
├── fonts.py       سرو فونت Vazirmatn (بسته‌بندی‌شده، با پشتیبان CDN)
└── web/           رابط کاربری فارسی (HTML/CSS/JS بدون هیچ کتابخانه بیرونی)
```

### تست‌ها

```bash
python -m unittest discover -s tests -t . -v
```

---

## نکات امنیتی

سرور فقط روی `127.0.0.1` گوش می‌دهد، هر درخواست به `/api/` نیازمند توکن تصادفیِ همان اجراست،
هدر `Host` بررسی می‌شود (جلوگیری از DNS rebinding) و مسیرهای فایل استاتیک محدود شده‌اند.
هیچ داده‌ای جایی جز رایانه خودتان ذخیره یا ارسال نمی‌شود.

---

## مجوز

کد این پروژه تحت [MIT](LICENSE) منتشر شده است.
فونت **Vazirmatn** ساخته [صابر راستی‌کردار](https://github.com/rastikerdar/vazirmatn) و تحت
مجوز SIL Open Font License 1.1 است
(متن مجوز: [`priceceawler/web/assets/fonts/OFL.txt`](priceceawler/web/assets/fonts/OFL.txt)).

داده‌های قیمت از TGJU دریافت می‌شود و این پروژه هیچ وابستگی رسمی به آن ندارد.
