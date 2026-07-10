<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#1e3a8a;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ $lang === 'ar' ? 'أهلاً بك في Dienstly24 👋' : 'Willkommen bei Dienstly24 👋' }}</h1>
</td></tr>
<tr><td style="padding:30px;">
@if($lang === 'ar')
<p style="font-size:15px;color:#333;">مرحباً <strong>{{ $customerName }}</strong>،</p>
<p style="font-size:15px;color:#333;">يسعدنا انضمامك إلينا! تم إنشاء حسابك في بوابة العملاء الخاصة بنا. هذه بيانات الدخول:</p>
@else
@include('emails._greeting', ['greetingName' => $customerName])
<p style="font-size:15px;color:#333;">wir freuen uns, Sie bei uns begrüßen zu dürfen! Ihr Zugang zu unserem Kundenportal wurde erstellt:</p>
@endif
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:15px 0;">
<tr><td style="padding:15px 20px;font-size:14px;color:#333;">
<strong>{{ $lang === 'ar' ? 'رابط البوابة:' : 'Portal:' }}</strong> <a href="https://portal.dienstly24.de" style="color:#1e3a8a;">portal.dienstly24.de</a><br><br>
<strong>{{ $lang === 'ar' ? 'البريد الإلكتروني:' : 'E-Mail:' }}</strong> {{ $customerEmail }}<br><br>
<strong>{{ $lang === 'ar' ? 'كلمة المرور:' : 'Passwort:' }}</strong> {{ $plainPassword }}
</td></tr></table>
@if($lang === 'ar')
<p style="font-size:13px;color:#b91c1c;"><strong>مهم:</strong> يرجى تغيير كلمة المرور بعد أول تسجيل دخول.</p>
<div style="background:#EFF6FF;border-radius:8px;padding:15px 20px;margin:20px 0;">
<p style="font-size:14px;color:#1e3a8a;margin:0;"><strong>📋 طلب صغير منك:</strong> بعد تسجيل الدخول، يرجى إكمال بياناتك الشخصية وإضافة أفراد عائلتك في البوابة. هذا يساعدنا على تقديم أفضل خدمة واستشارة تأمينية لك ولعائلتك.</p>
</div>
<p style="font-size:15px;color:#333;">مع أطيب التحيات،<br>فريق Dienstly24</p>
@else
<p style="font-size:13px;color:#b91c1c;"><strong>Wichtig:</strong> Bitte ändern Sie Ihr Passwort nach dem ersten Login.</p>
<div style="background:#EFF6FF;border-radius:8px;padding:15px 20px;margin:20px 0;">
<p style="font-size:14px;color:#1e3a8a;margin:0;"><strong>📋 Eine kleine Bitte:</strong> Vervollständigen Sie nach dem Login bitte Ihre persönlichen Daten und fügen Sie Ihre Familienmitglieder im Portal hinzu. So können wir Sie und Ihre Familie optimal beraten.</p>
</div>
<p style="font-size:15px;color:#333;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
@endif
</td></tr>
<tr><td style="background:#f8fafc;padding:15px 30px;border-top:1px solid #e2e8f0;">
<p style="font-size:12px;color:#94a3b8;margin:0;">Dienstly24 · Hamburg</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
