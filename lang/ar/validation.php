<?php

/**
 * Arabische Formularfehler (Audit-Punkt 11 / Betreiber-Vorgabe 18.08.2026).
 *
 * Bisher gab es NUR lang/ar.json. Validierungsmeldungen liegen aber unter
 * lang/<locale>/validation.php - ohne diese Datei bekam ein arabischer
 * Kunde bei jedem Formularfehler eine ENGLISCHE Meldung ("The password
 * field must be at least 12 characters"). Genau an dieser Stelle steigen
 * Kunden aus, weil sie nicht verstehen, was falsch ist - und das trifft
 * ausgerechnet die Passwort-Formulare.
 *
 * Nicht uebersetzte Schluessel fallen automatisch auf die Fallback-Sprache
 * zurueck; die Datei muss also nicht jede Regel abdecken.
 */
return [
    'accepted' => 'يجب قبول :attribute.',
    'active_url' => ':attribute ليس رابطاً صحيحاً.',
    'after' => 'يجب أن يكون :attribute تاريخاً بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخاً بعد أو يساوي :date.',
    'alpha' => 'يجب أن يحتوي :attribute على حروف فقط.',
    'alpha_dash' => 'يجب أن يحتوي :attribute على حروف وأرقام وشرطات فقط.',
    'alpha_num' => 'يجب أن يحتوي :attribute على حروف وأرقام فقط.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'before' => 'يجب أن يكون :attribute تاريخاً قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخاً قبل أو يساوي :date.',
    'between' => [
        'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و :max.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و :max حرفاً.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute إما صحيحة أو خاطئة.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور الحالية غير صحيحة.',
    'date' => ':attribute ليس تاريخاً صحيحاً.',
    'date_equals' => 'يجب أن يكون :attribute تاريخاً مساوياً لـ :date.',
    'date_format' => 'لا يتوافق :attribute مع الصيغة :format.',
    'different' => 'يجب أن يكون :attribute مختلفاً عن :other.',
    'digits' => 'يجب أن يتكون :attribute من :digits رقماً.',
    'digits_between' => 'يجب أن يتكون :attribute من عدد أرقام بين :min و :max.',
    'email' => 'يجب أن يكون :attribute عنوان بريد إلكتروني صحيحاً.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد التالي: :values.',
    'exists' => 'القيمة المختارة في :attribute غير موجودة.',
    'file' => 'يجب أن يكون :attribute ملفاً.',
    'filled' => 'حقل :attribute مطلوب.',
    'gt' => [
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول :attribute أكبر من :value حرفاً.',
    ],
    'gte' => [
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكثر.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أكثر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفاً أو أكثر.',
    ],
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المختارة في :attribute غير صحيحة.',
    'integer' => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صحيحاً.',
    'json' => 'يجب أن يكون :attribute نصاً بصيغة JSON صحيحة.',
    'lt' => [
        'file' => 'يجب أن يكون حجم :attribute أقل من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أقل من :value.',
        'string' => 'يجب أن يكون طول :attribute أقل من :value حرفاً.',
    ],
    'lte' => [
        'file' => 'يجب ألّا يزيد حجم :attribute عن :value كيلوبايت.',
        'numeric' => 'يجب ألّا تزيد قيمة :attribute عن :value.',
        'string' => 'يجب ألّا يزيد طول :attribute عن :value حرفاً.',
    ],
    'max' => [
        'array' => 'يجب ألّا يحتوي :attribute على أكثر من :max عنصراً.',
        'file' => 'يجب ألّا يزيد حجم :attribute عن :max كيلوبايت.',
        'numeric' => 'يجب ألّا تزيد قيمة :attribute عن :max.',
        'string' => 'يجب ألّا يزيد طول :attribute عن :max حرفاً.',
    ],
    'mimes' => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصراً على الأقل.',
        'file' => 'يجب أن يكون حجم :attribute :min كيلوبايت على الأقل.',
        'numeric' => 'يجب ألّا تقل قيمة :attribute عن :min.',
        'string' => 'يجب أن يتكون :attribute من :min حرفاً على الأقل.',
    ],
    'not_in' => 'القيمة المختارة في :attribute غير صحيحة.',
    'numeric' => 'يجب أن يكون :attribute رقماً.',
    'password' => [
        'letters' => 'يجب أن يحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن يحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن يحتوي :attribute على رمز واحد على الأقل.',
        // Der wichtigste Satz des ganzen Formulars: erklaeren, WARUM
        // abgelehnt wurde, und was der Kunde jetzt tun soll.
        'uncompromised' => 'كلمة المرور هذه ظهرت في تسريبات بيانات معروفة. من فضلك اختر كلمة مرور أخرى لم تستخدمها في أي موقع آخر.',
    ],
    'present' => 'يجب إرسال حقل :attribute.',
    'prohibited' => 'حقل :attribute غير مسموح به.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without' => 'حقل :attribute مطلوب عند عدم وجود :values.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',
    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute :size.',
        'string' => 'يجب أن يتكون :attribute من :size حرفاً.',
    ],
    'starts_with' => 'يجب أن يبدأ :attribute بأحد التالي: :values.',
    'string' => 'يجب أن يكون :attribute نصاً.',
    'unique' => 'قيمة :attribute مستخدمة من قبل.',
    'uploaded' => 'فشل رفع :attribute.',
    'url' => 'يجب أن يكون :attribute رابطاً صحيحاً.',

    'custom' => [],

    /** Feldnamen, damit die Meldung nicht "password" auf Arabisch mischt. */
    'attributes' => [
        'name' => 'الاسم',
        'first_name' => 'الاسم الأول',
        'last_name' => 'اسم العائلة',
        'email' => 'البريد الإلكتروني',
        'identifier' => 'البريد الإلكتروني أو رقم العميل',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'current_password' => 'كلمة المرور الحالية',
        'phone' => 'رقم الهاتف',
        'mobile' => 'رقم الجوال',
        'birth_date' => 'تاريخ الميلاد',
        'address' => 'العنوان',
        'message' => 'الرسالة',
        'subject' => 'الموضوع',
        'consent' => 'الموافقة',
        'file' => 'الملف',
        'files' => 'الملفات',
    ],
];
