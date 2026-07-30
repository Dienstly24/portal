# نقل www.dienstly24.de إلى السيرفر — دليل خطوة بخطوة

الهدف: أن يصبح `www.dienstly24.de` مخدوماً من نظام Laravel على الـ VPS
بدل الاستضافة الثابتة على Hostinger. عندها تنتقل صفحات الخدمات الست من
`portal.dienstly24.de` إلى النطاق الرئيسي، وتُولَّد خريطة الموقع تلقائياً
بكل الصفحات (ألماني + عربي) بدل رابط واحد.

**مدة التنفيذ الفعلية**: 60–90 دقيقة يوم النقل + انتظار انتشار DNS.
**من ينفّذ**: الخطوات 1–5 تحتاج وصول SSH للسيرفر؛ الخطوات 6–10 من hPanel
ومن المتصفح.

---

## قبل أن تبدأ — ثلاث معلومات تحتاجها

| المعلومة | كيف تحصل عليها |
|---|---|
| **عنوان IP للـ VPS** | hPanel ← VPS ← لوحة السيرفر؛ أو من إعدادات DNS الحالية لـ `portal.dienstly24.de` |
| **وصول SSH** | اسم المستخدم + كلمة المرور أو مفتاح SSH من hPanel ← VPS ← SSH-Zugang |
| **نوع خادم الويب** | نفّذ الأمر في الخطوة 0 |

إن لم تكن لديك هذه، لا تكمل — اطلبها من مسؤول السيرفر أولاً.

---

## الخطوة 0 — تعرّف على السيرفر (دقيقة واحدة)

اتصل بالسيرفر:
```bash
ssh root@عنوان-الـIP
```

ثم نفّذ:
```bash
which nginx apache2 httpd 2>/dev/null; echo "---"; ls /etc/nginx/sites-available/ /etc/apache2/sites-available/ 2>/dev/null
```

- ظهر `nginx` ← اتبع **مسار A** في الخطوة 3
- ظهر `apache2` أو `httpd` ← اتبع **مسار B**
- ظهرت لوحة تحكم (CyberPanel / Plesk) ← الإعداد بالنقر لا بالأوامر؛ أضف
  النطاقات الأربعة كـ Domains/Aliases على نفس الموقع، وتخطَّ الخطوة 3

---

## الخطوة 1 — حدّث الكود على السيرفر

```bash
cd /var/www/dienstly24/portal
git fetch --all --prune
git reset --hard origin/main
bash scripts/deploy.sh
```

يجب أن ينتهي بـ `Deploy fertig`. إن ظهر خطأ، **توقف هنا** وأرسل لي
الرسالة — لا تكمل النقل بنظام غير مكتمل.

---

## الخطوة 2 — إعدادات `.env` (الأهم)

```bash
nano /var/www/dienstly24/portal/.env
```

عدّل/أضف هذه الأسطر:
```ini
APP_URL=https://www.dienstly24.de
ADMIN_BASIC_AUTH=اسم-تختاره:كلمة-سر-قوية
```

⚠️ `APP_URL` هو سبب مشكلة روابط الـ IP المكشوفة سابقاً (البند P0-6) —
لا تتجاوزه.
⚠️ `ADMIN_BASIC_AUTH` هو الشرط الأمني: طبقة حماية ثانية أمام لوحة
الإدارة إلى أن تُبنى المصادقة الثنائية. اكتب كلمة السر في مكان آمن.

**لا تضع** `WEBSITE_MARKETING_REDIRECT` بعد — سيأتي دورها في الخطوة 8.

احفظ (Ctrl+O ثم Ctrl+X) ثم:
```bash
cd /var/www/dienstly24/portal && php artisan config:cache
```

---

## الخطوة 3 — أضف النطاقات إلى خادم الويب

### مسار A — Nginx
```bash
nano /etc/nginx/sites-available/dienstly24
```
ابحث عن سطر `server_name` وأضف النطاقات الأربعة إليه:
```nginx
server_name portal.dienstly24.de admin.dienstly24.de
            www.dienstly24.de dienstly24.de
            www.dienstly24.com dienstly24.com;
```
ثم:
```bash
nginx -t && systemctl reload nginx
```
`nginx -t` يجب أن يقول `syntax is ok`. إن قال غير ذلك، تراجع عن التعديل.

### مسار B — Apache
```bash
nano /etc/apache2/sites-available/dienstly24.conf
```
داخل `<VirtualHost *:443>` أضف تحت `ServerName`:
```apache
ServerAlias www.dienstly24.de
ServerAlias dienstly24.de
ServerAlias www.dienstly24.com
ServerAlias dienstly24.com
```
وتأكد من وجود هذا السطر (يمرّر كلمة سر الحماية للتطبيق):
```apache
CGIPassAuth On
```
ثم:
```bash
apache2ctl configtest && systemctl reload apache2
```

---

## الخطوة 4 — شهادة SSL للنطاقات الجديدة

```bash
certbot --nginx -d www.dienstly24.de -d dienstly24.de -d www.dienstly24.com -d dienstly24.com
```
(مع Apache: `certbot --apache` بنفس النطاقات)

⚠️ سينجح فقط **بعد** أن يشير الـ DNS إلى السيرفر. لذلك الترتيب العملي
هو: نفّذ الخطوة 7 (تحويل DNS) أولاً، انتظر 10 دقائق، ثم ارجع لهذه
الخطوة. أو استخدم تحقق DNS-01 إن كنت تريد الشهادة مسبقاً.

---

## الخطوة 5 (اختيارية لكن موصى بها) — جرّب قبل النقل

تريد أن تتصفح الموقع الجديد كاملاً قبل لمس الدومين الرئيسي؟

1. في hPanel ← Domains ← DNS لـ `dienstly24.de`: أضف سجلاً
   `A` باسم `neu` يشير لعنوان الـ VPS
2. أضف `neu.dienstly24.de` إلى `server_name` / `ServerAlias` (كالخطوة 3)
   وأصدر له شهادة: `certbot --nginx -d neu.dienstly24.de`
3. في `.env`:
```ini
WEBSITE_EXTRA_HOSTS=neu.dienstly24.de
STAGING_HOSTS=neu.dienstly24.de
STAGING_BASIC_AUTH=vorschau:كلمة-سر
```
ثم `php artisan config:cache`

الآن `https://neu.dienstly24.de` يعرض الموقع الجديد كاملاً خلف كلمة مرور،
محجوباً عن غوغل. تصفّحه، جرّب النموذج، وافحص الصفحات العربية. بعد
اقتناعك امسح الأسطر الثلاثة وأعد `config:cache`.

---

## الخطوة 6 — قبل النقل بيوم (الإثنين)

1. **خفّض TTL**: hPanel ← Domains ← DNS لكل من `dienstly24.de`
   و`dienstly24.com` ← عدّل سجلات `A` ← اجعل TTL = **300** ثانية.
   هذا يجعل التراجع يسري خلال دقائق بدل ساعات.
2. **نسخة احتياطية + اختبار استعادة**:
```bash
bash /var/www/dienstly24/portal/scripts/backup.sh
```
   ثم نفّذ اختبار الاستعادة الموصوف في `docs/WEBSITE_MERGE_UMSETZUNG.md`.
3. تأكد أن `ADMIN_BASIC_AUTH` مضبوطة (الخطوة 2).

---

## الخطوة 7 — يوم النقل: الثلاثاء صباحاً (9:00–11:00)

**لا تنقل يوم جمعة ولا مساءً** — إن حدث خلل تريد يوم عمل كاملاً أمامك.

في hPanel ← Domains ← DNS لـ `dienstly24.de`:

| النوع | الاسم | القيمة |
|---|---|---|
| A | `@` | عنوان IP للـ VPS |
| A | `www` | عنوان IP للـ VPS |

كرّر نفس الشيء لـ `dienstly24.com` (إن كنت أبقيت التحويل من hPanel كما هو
الآن، يمكنك تركه — التحويل يعمل بالفعل).

⚠️ **لا تلمس** سجلات `portal` و`admin` — هما يعملان أصلاً.
⚠️ **لا تلمس** سجلات `MX` و`TXT` — بريدك وإعدادات SPF/DKIM تعتمد عليها.
حذفها يوقف بريد الشركة.

انتظر 10–15 دقيقة، ثم أصدر شهادة SSL (الخطوة 4).

---

## الخطوة 8 — فعّل تحويل الروابط القديمة

بعد التأكد أن `https://www.dienstly24.de` يعمل من السيرفر الجديد:

```bash
nano /var/www/dienstly24/portal/.env
```
أضف:
```ini
WEBSITE_MARKETING_REDIRECT=true
```
ثم:
```bash
cd /var/www/dienstly24/portal && php artisan config:cache
```

هذا يحوّل الروابط القديمة `portal.dienstly24.de/leistungen/...` إلى
النطاق الرئيسي بـ 301، فتنتقل قوة السيو المتراكمة بدل أن تضيع (البند
P1-4). بوابة العملاء وتسجيل الدخول لا تتأثر إطلاقاً.

⚠️ لا تفعّلها قبل نجاح النقل — قبله ستحوّل الزوار إلى موقع لا يحوي تلك
الصفحات.

---

## الخطوة 9 — قائمة التحقق (نفّذها كلها)

افتح في المتصفح وتأكد:

```
✅ https://www.dienstly24.de/                    → الموقع الجديد
✅ http://dienstly24.de/                         → تحويل إلى www + https
✅ https://dienstly24.com/                       → تحويل إلى www.dienstly24.de
✅ https://www.dienstly24.de/leistungen          → قائمة الخدمات الست
✅ https://www.dienstly24.de/leistungen/kfz-versicherung
✅ https://www.dienstly24.de/ar                  → النسخة العربية RTL
✅ https://www.dienstly24.de/impressum           → الصفحة القانونية
✅ https://www.dienstly24.de/sitemap.xml         → يحوي كل الصفحات الآن
✅ https://www.dienstly24.de/robots.txt          → يشير للـ sitemap
✅ https://portal.dienstly24.de/login            → بوابة العملاء تعمل
✅ https://admin.dienstly24.de/admin             → يطلب كلمة سر إضافية أولاً
```

**اختبار النموذج (الأهم)**: أرسل طلباً تجريبياً من جوالك عبر
`www.dienstly24.de` ← يجب أن تظهر تذكرة في الـ Beraterwelt خلال ثوانٍ،
ويصلك بريدان (إشعار لك + تأكيد للعميل). أغلق التذكرة التجريبية بعدها.

---

## الخطوة 10 — بعد النقل مباشرة

1. **Search Console**: أضف/اختر `https://www.dienstly24.de` ← Sitemaps
   ← أرسل `sitemap.xml`. ثم URL-Prüfung للصفحة الرئيسية ←
   `Indexierung beantragen`.
2. **Adressänderung**: إن كانت لديك خاصية قديمة لـ `dienstly24.de`
   بدون www، استخدم Einstellungen ← Adressänderung لنقلها رسمياً.
3. **Bing Webmaster Tools**: نفس الخريطة (مجاني، ويستورد من غوغل بنقرة).

---

## التراجع (إن ساء شيء)

أعِد سجلَّي `A` في hPanel إلى قيمتهما السابقة (استضافة Hostinger).
بفضل TTL = 300 يسري التغيير خلال دقائق. الموقع الثابت ما زال موجوداً كما
هو ولم يُمس.

**لهذا السبب** لا تحذف ملفات الاستضافة الثابتة ليوم النقل.

---

## بعد أسبوع مستقر — أغلق الازدواجية

هذه الخطوة ليست تجميلية: بقاء نسختين يعني تباعد المحتوى وتشتيت السيو.

1. تأكد أن كل شيء يعمل منذ 7 أيام بلا شكاوى
2. hPanel ← File Manager ← احذف محتويات `public_html` القديمة
   (أو انقلها لمجلد أرشيف خارج الويب)
3. أعِد TTL إلى 3600 أو أكثر
4. أزل بيئة التجربة (`neu.` وسجل الـ DNS الخاص بها)

من هذه اللحظة: **مصدر واحد فقط** — كل تعديل من لوحة الإدارة أو من Git،
ولا FTP بعد اليوم.

---

## ملخّص الأوامر (للنسخ السريع)

```bash
# 1. تحديث الكود
cd /var/www/dienstly24/portal && git fetch --all --prune && \
  git reset --hard origin/main && bash scripts/deploy.sh

# 2. بعد تعديل .env
php artisan config:cache

# 3. اختبار إعداد خادم الويب
nginx -t && systemctl reload nginx        # أو
apache2ctl configtest && systemctl reload apache2

# 4. شهادة SSL (بعد تحويل DNS)
certbot --nginx -d www.dienstly24.de -d dienstly24.de \
        -d www.dienstly24.com -d dienstly24.com

# 5. نسخة احتياطية
bash scripts/backup.sh
```

---

**عند أي خطوة تتعثر**: أرسل لي نص الخطأ كما هو (انسخه من الطرفية) وأحدد
لك السبب والحل. لا تجرّب أوامر عشوائية على سيرفر يعمل عليه نظام العملاء.
