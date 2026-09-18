# تركيب Matomo على خادمنا – دليل المشغّل

قرارك بتاريخ 18.09.2026: **Matomo على خادمنا**، وليس Google Analytics 4.

الجزء البرمجي **جاهز ومدموج**. لا ينقص إلا تركيب Matomo على الخادم
وإضافة سطرين في ملف `.env`. قبل ذلك: الموقع لا يرسل أي بايت لأي جهة،
ولا يظهر أي سكربت قياس في الصفحات.

> **ما يُقاس وما لا يُقاس:** يُقاس **الموقع العام فقط**
> (`www.dienstly24.de`). لا تُقاس أبداً بيئة الموظفين (`/admin`) ولا بوابة
> العملاء ولا بوابة الشركاء — هناك تظهر بيانات عملاء في عناوين الصفحات
> وفي الروابط (`/admin/customers/4711`)، وأي أداة قياس ستسجّلها. هذا ليس
> إعداداً يمكن لأحد أن ينساه: السكربت موجود حصراً في قوالب الموقع العام،
> واختبار يحرس ذلك.

---

## 1. لماذا Matomo وليس GA4 (باختصار، للتوثيق)

1. **الأمان**: GA4 يحتاج سكربتاً من `googletagmanager.com`. هذا يعني إعادة
   فتح سياسة المحتوى (CSP) التي أُغلقت بجهد كبير في تدقيق SEC-4. Matomo
   يعمل على نطاق فرعي **يخصّنا**، فتبقى الإذن بيدنا.
2. **اكتمال البيانات**: GA4 يضع معرّفات ويحتاج موافقة صريحة؛ من يرفض لا
   يُقاس. Matomo هنا يعمل **بلا معرّفات** (`disableCookies`)، فيُقاس كل
   الزوار.
3. **ملكية البيانات**: تبقى على خادمك. وهذه نفس سياسة باقي النظام (رمز QR
   خاص بنا، ختم PDF خاص بنا، قارئ XLSX خاص بنا).

**الثمن، ولا نخفيه:** Matomo تطبيق PHP إضافي بقاعدة بيانات خاصة على
الـ VPS، يحتاج تحديثات أمنية دورية مثل أي تطبيق آخر.

---

## 2. التركيب على الخادم

> الأوامر مكتوبة لخادم Ubuntu/Debian مع nginx — وهو الشائع على Hostinger.
> إن كان خادمك يستخدم Apache، الخطوات نفسها والاختلاف في ملف الموقع فقط.

### 2.1 نطاق فرعي

أنشئ في إعدادات DNS سجلاً من نوع A باسم:

```
statistik.dienstly24.de  →  عنوان IP للخادم
```

> استخدمنا `statistik` كمثال. أي اسم يعمل، المهم أن يكون **نطاقاً فرعياً
> لنا** وأن يُكتب لاحقاً كما هو في `.env`.

### 2.2 قاعدة بيانات

```
sudo mysql -u root -p
```

ثم داخل MySQL (استبدل كلمة المرور بكلمة قوية، ولا ترسلها في الشات):

```
CREATE DATABASE matomo DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'matomo'@'localhost' IDENTIFIED BY 'ضع-كلمة-مرور-قوية-هنا';
GRANT ALL PRIVILEGES ON matomo.* TO 'matomo'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 2.3 تنزيل Matomo

```
cd /var/www
sudo wget https://builds.matomo.org/matomo.zip
sudo unzip matomo.zip
sudo rm matomo.zip "How to install Matomo.html"
sudo chown -R www-data:www-data /var/www/matomo
```

### 2.4 موقع nginx + شهادة TLS

```
sudo nano /etc/nginx/sites-available/statistik.dienstly24.de
```

المحتوى:

```
server {
    listen 80;
    server_name statistik.dienstly24.de;
    root /var/www/matomo;
    index index.php;

    location / { try_files $uri $uri/ =404; }

    location ~ ^/(index|matomo|piwik|js/index|plugins/HeatmapSessionRecording/configs)\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # لا يُنفَّذ أي ملف PHP آخر
    location ~* ^/(?!index|matomo|piwik|js/index).*\.php$ { deny all; }
    location ~ /\.  { deny all; }
    location ~ ^/(config|tmp|core|lang)/ { deny all; }
}
```

ثم:

```
sudo ln -s /etc/nginx/sites-available/statistik.dienstly24.de /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d statistik.dienstly24.de
```

**بدون شهادة TLS لن يعمل القياس**، والنظام سيرفض العنوان أصلاً: لا يقبل
إلا `https`.

### 2.5 معالج التركيب

افتح `https://statistik.dienstly24.de` واتبع الخطوات:
بيانات قاعدة البيانات من 2.2، ثم حساب المدير، ثم بيانات الموقع:

- الاسم: `Dienstly24`
- العنوان: `https://www.dienstly24.de`

في النهاية سيعطيك **رقم الموقع (Site ID)** — غالباً `1`. احفظه.

**تجاهل كود التتبّع الذي يعرضه**: البرنامج يولّده بنفسه بالإعدادات
الصحيحة (بلا كوكيز، مع احترام "عدم التتبّع").

---

## 3. إعدادات Matomo المهمة (بعد التركيب)

من داخل Matomo: **Administration (الترس) ← Privacy ← Anonymize data**

| الإعداد | القيمة | السبب |
| --- | --- | --- |
| Anonymize Visitors' IP addresses | **مفعّل، 2 bytes** | بدونها تُخزَّن عناوين IP كاملة = بيانات شخصية |
| Also anonymize User ID | مفعّل | لا نستخدمه أصلاً، فليبقَ مغلقاً من الجهتين |
| Support DoNotTrack preference | **مفعّل** | نفس ما يرسله سكربت الموقع |
| Regularly delete old raw data | مفعّل، مثلاً **180 يوماً** | تقليل البيانات (المادة 5 DSGVO). التقارير المجمّعة تبقى |

ثم **Administration ← System ← General settings**: فعّل التحديثات
التلقائية للإصدارات الأمنية.

وأخيراً مهمة مجدولة لمعالجة التقارير (وإلا صارت الصفحات بطيئة):

```
sudo crontab -u www-data -e
```

وأضف:

```
5 * * * * /usr/bin/php /var/www/matomo/console core:archive --url=https://statistik.dienstly24.de/ > /dev/null
```

---

## 4. تشغيل القياس في البوابة

على الخادم:

```
cd /var/www/dienstly24/portal
nano .env
```

أضف السطرين (بدون شرطة مائلة في النهاية):

```
MATOMO_URL=https://statistik.dienstly24.de
MATOMO_SITE_ID=1
```

ثم:

```
php artisan config:cache
```

> **مهم:** بدون `config:cache` لن يقرأ البرنامج القيم الجديدة في الإنتاج.

للإيقاف في أي وقت: أفرغ القيمتين وأعد `php artisan config:cache`. عندها
لا يخرج أي سكربت ولا أي إذن في سياسة المحتوى.

---

## 5. التحقق أنه يعمل

1. افتح `https://www.dienstly24.de/leistungen/kfz-versicherung` في نافذة
   خاصة.
2. في Matomo: **Visitors ← Visits Log** — يجب أن تظهر الزيارة خلال دقيقة.
3. اضغط زر الهاتف أو واتساب على الصفحة، ثم راجع
   **Behaviour ← Events**: يجب أن يظهر
   `Kontakt / telefon / kfz-versicherung`.
4. اضغط "بوابة العملاء"، ثم راجع **Behaviour ← Outlinks**: يظهر
   الانتقال إلى `portal.dienstly24.de`.

إن لم يظهر شيء، افتح أدوات المطوّر (F12) وتبويب Console. لو ظهر
`Refused to load the script` فالعنوان في `.env` لا يطابق النطاق الفعلي —
الإذن في سياسة المحتوى يُبنى من نفس القيمة.

---

## 6. ماذا نقيس فعلياً

| ما نقيسه | أين نراه في Matomo |
| --- | --- |
| زيارات الصفحات العامة والصفحات الأكثر طلباً | Behaviour ← Pages |
| نقرات الهاتف والواتساب والنموذج | Behaviour ← Events (الفئة `Kontakt`) |
| الانتقال إلى بوابة العملاء | Behaviour ← Outlinks |
| من أي محرّك بحث وبأي كلمة جاء الزائر | Acquisition |
| الألمانية مقابل العربية | Visitors ← Locations / صفحات `/ar/...` |

ما **لا** نقيسه: أي شيء داخل البوابة أو بيئة الموظفين، وأي معرّف شخصي،
وأي عنوان IP كامل.

---

## 7. الجانب القانوني — يبقى قراراً لك

القياس بلا كوكيز ومع تجهيل IP هو الشكل الذي **يُعتبر عملياً** غير محتاج
للافتة موافقة في ألمانيا. لكن هذه ليست استشارة قانونية، والأمر يخصّ
مكتب حماية البيانات لديك. قبل التشغيل الفعلي:

1. **أضف Matomo إلى بيان حماية البيانات**: المعالِج = نحن على خادمنا،
   الغرض = إحصاءات الاستخدام، الأساس = المادة 6 فقرة 1 حرف و،
   بلا كوكيز، مع تجهيل IP، ومدة الحفظ التي اخترتها في القسم 3.
2. **أضفه إلى سجل أنشطة المعالجة** (Verarbeitungsverzeichnis).
3. **أضف رابط الاعتراض (Opt-out)** الذي يولّده Matomo
   (Administration ← Privacy ← Users opt-out) في صفحة حماية البيانات.

لا حاجة لعقد معالجة بيانات (AVV) مع أي طرف ثالث — وهذه إحدى ميزات
الاستضافة الذاتية.

---

## 8. الصيانة

- تحديثات Matomo: من داخل لوحته، وراجعها كل شهر
- نسخة احتياطية: قاعدة `matomo` ليست ضمن نسخ البوابة الحالية. إن أردت
  حفظ الإحصاءات أضفها إلى `scripts/backup.sh` — وإن لم تفعل، فأسوأ ما
  يحدث فقدان الإحصاءات، لا بيانات العملاء
- المساحة: Matomo يكبر مع الزيارات؛ حدّ حذف البيانات الخام في القسم 3
  هو ما يبقيه تحت السيطرة
