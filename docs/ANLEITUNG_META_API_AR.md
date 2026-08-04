# دليل ربط النظام مع فيسبوك وإنستغرام (Meta Graph API)

هذا الدليل موجّه لمشغّل Dienstly24. بعد تنفيذه مرة واحدة، يستطيع النظام
نشر بانرات التسويق **تلقائياً** على صفحة فيسبوك وحساب إنستغرام Business —
بزر واحد («Jetzt per API posten») أو بجدولة زمنية («Automatisch
veröffentlichen»).

> **مهم:** كل القيم السرية (الـ Token) تُحفظ فقط في ملف `.env` على
> السيرفر — **أبداً** لا تُرسل في الشات ولا تُحفظ في GitHub.

---

## المتطلبات (موجودة لديك غالباً)

1. صفحة فيسبوك للنشاط التجاري (Dienstly24) وأنت **Admin** عليها.
2. حساب إنستغرام محوّل إلى **Business** (وليس شخصي) ومربوط بصفحة
   الفيسبوك: إعدادات إنستغرام → Konto → «Zu einem professionellen Konto
   wechseln»، ثم في الصفحة: Einstellungen → Verknüpfte Konten → Instagram.
3. **Meta Business Manager** ([business.facebook.com](https://business.facebook.com))
   والصفحة + حساب الإنستغرام مضافان كأصول (Assets) فيه.

> لا حاجة لمراجعة تطبيق (App Review) من Meta: أنت تنشر على **أصولك
> الخاصة** فقط، وصلاحية «Standard Access» تكفي لذلك.

---

## الخطوة 1: إنشاء تطبيق (App) — مرة واحدة

1. افتح [developers.facebook.com](https://developers.facebook.com) وسجّل
   الدخول بنفس حساب الفيسبوك.
2. «Meine Apps» → **«App erstellen»**.
3. اختر حالة الاستخدام **«Sonstiges»** ثم النوع **«Business»**.
4. اسم التطبيق مثلاً: `Dienstly24 Portal` — واربطه بحساب الـ Business
   Manager الخاص بك عندما يُسأل.

## الخطوة 2: إنشاء System User وتوليد الـ Token

الـ System User يعطي Token **لا تنتهي صلاحيته** (أفضل من token شخصي
ينتهي كل 60 يوم).

1. افتح [business.facebook.com](https://business.facebook.com) →
   **Business-Einstellungen** (رمز الترس).
2. القائمة اليسرى: **Benutzer → Systembenutzer** → «Hinzufügen».
   - الاسم مثلاً: `portal-publisher`، الدور: **Admin**.
3. عند الـ System User اضغط **«Assets zuweisen»**:
   - **Seiten**: اختر صفحة Dienstly24 → فعّل «Inhalte veröffentlichen»
     (أو Volle Kontrolle).
   - **Apps**: اختر التطبيق من الخطوة 1 → Volle Kontrolle.
   - (حساب الإنستغرام المربوط بالصفحة يتبعها تلقائياً.)
4. اضغط **«Token generieren»**:
   - اختر التطبيق من الخطوة 1.
   - مدة الصلاحية: **«Läuft nie ab»**.
   - الصلاحيات (Berechtigungen) المطلوبة — فعّل هذه فقط:
     - `pages_show_list`
     - `pages_manage_posts`
     - `pages_read_engagement`
     - `business_management`
     - `instagram_basic`
     - `instagram_content_publish`
5. **انسخ الـ Token فوراً واحفظه** — لن يظهر مرة أخرى.

## الخطوة 3: معرفة الـ IDs

- **Page-ID**: في Business-Einstellungen → Konten → Seiten → اضغط على
  الصفحة، الـ ID يظهر تحت اسمها. (أو في صفحة الفيسبوك: Info/Über →
  «Seiten-ID».)
- **Instagram-User-ID**: في Business-Einstellungen → Konten →
  Instagram-Konten → اضغط على الحساب، الـ ID يظهر تحت اسمه.
  (هذا رقم طويل — وليس اسم المستخدم.)

## الخطوة 4: إدخال القيم على السيرفر

على السيرفر (VPS) عدّل ملف `/var/www/dienstly24/portal/.env` وأضف:

```
META_PAGE_ID=رقم_الصفحة
META_IG_USER_ID=رقم_حساب_انستغرام
META_ACCESS_TOKEN=التوكن_الطويل
```

ثم فعّل الإعدادات:

```
cd /var/www/dienstly24/portal && php artisan config:cache
```

---

## كيف تستخدمها بعد الربط

1. **Beraterwelt → Banner** → عند أي بانر زر **«📣 Social-Media»**.
2. اكتب نص المنشور (ألماني/عربي)، حدد الرابط، اختر المنصات، واحفظ.
3. للنشر الفوري: زر **«🚀 Jetzt per API posten»** عند فيسبوك أو إنستغرام.
4. للنشر المجدول: فعّل **«Automatisch veröffentlichen»** وحدد التاريخ
   والوقت — النظام ينشر تلقائياً (يفحص كل 15 دقيقة) ويصلك إشعار في
   الجرس بالنجاح أو الفشل.
5. المنشور يتكوّن من: النص الألماني + النص العربي + رابط التتبّع،
   مع صورة المقاس المربع (1:1).

**ملاحظات:**

- **تيك توك يبقى يدوياً**: واجهة TikTok البرمجية تتطلب مراجعة واعتماد
  رسمي للتطبيق — استخدم زر التحميل والمقاس 9:16 وانشر من التطبيق.
- **ستوري إنستغرام يدوي أيضاً**: ملصق الرابط (Link-Sticker) لا يُضاف
  إلا من تطبيق إنستغرام نفسه.
- **لا نشر مزدوج أبداً**: النشر المجدول يحاول **مرة واحدة فقط** لكل
  منصة؛ إذا فشل يظهر سبب الفشل على الصفحة وفي الجرس، وإعادة المحاولة
  تكون بضغطة زر منك.

## حل المشاكل الشائعة

| الرسالة | السبب والحل |
|---|---|
| `Meta-API nicht konfiguriert` | القيم غير موجودة في `.env` أو لم يُنفّذ `php artisan config:cache`. |
| خطأ فيه `permissions` أو `(#200)` | الصلاحيات ناقصة في الـ Token — أعد توليده مع كل الصلاحيات في الخطوة 2. |
| خطأ في جلب الصورة عند إنستغرام | إنستغرام يسحب الصورة من رابط عام — تأكد أن `APP_URL` في `.env` هو الدومين الحقيقي وأن `php artisan storage:link` منفّذ. |
| `Beitragstext für Instagram zu lang` | إنستغرام يسمح بـ 2200 حرف كحد أقصى شاملاً الرابط — قصّر النص. |
