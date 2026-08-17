<?php
namespace App\Services\Ai\Assistant;

/**
 * FESTE Antworttexte des Assistenten (Spezifikation Abschnitte 3/4/31).
 *
 * Warum fest und nicht vom Modell: genau in diesen Faellen wird das Modell
 * NICHT gefragt (Anfrage ausserhalb des Bereichs, Regel-Umgehungsversuch,
 * KI-Dienst gestoert, Grenze erreicht). Die Antwort muss trotzdem hoeflich
 * und in der Sprache des Kunden kommen - deshalb hinterlegt, dreisprachig,
 * ohne API-Aufruf und ohne Kosten.
 *
 * Diese Texte lesen KUNDEN - sie sind daher in korrektem Deutsch mit
 * Umlauten formuliert (die ASCII-Regel des Projekts gilt fuer Code,
 * Kommentare und Terminal-Befehle, nicht fuer Kundentexte).
 *
 * Bewusst KEIN `__()`: der Text folgt der ERKANNTEN Nachrichtensprache,
 * nicht der eingestellten Oberflaechensprache (ein Kunde mit deutscher
 * Oberflaeche kann arabisch schreiben).
 */
class AssistantReplies
{
    /** Anfrage liegt ausserhalb des Kundenservice-Bereichs. */
    public const OUT_OF_SCOPE = [
        'de' => 'Diese Anfrage liegt außerhalb unseres Kundenservice-Bereichs. Gerne kann unser '
            . 'Team Ihre Anfrage persönlich bearbeiten – ich habe sie an unser zuständiges Team '
            . 'weitergeleitet.',
        'en' => 'This request is outside our customer service scope. Our team will be happy to '
            . 'help you personally – I have forwarded your request to the responsible team.',
        'ar' => 'هذا الطلب خارج نطاق خدمة العملاء لدينا. يسعد فريقنا بمساعدتك شخصياً – لقد أحلت '
            . 'طلبك إلى الفريق المختص.',
    ];

    /** Uebergabe an das Team (Unsicherheit, sensibler Fall, Kundenwunsch). */
    public const HANDOVER = [
        'de' => 'Diese Anfrage möchte ich sicherheitshalber von unserem zuständigen Team prüfen '
            . 'lassen. Ich habe Ihre Anfrage weitergeleitet – unser Team meldet sich bei Ihnen.',
        'en' => 'To be on the safe side, I would like our responsible team to review this request. '
            . 'I have forwarded it – our team will get back to you.',
        'ar' => 'أفضل أن يقوم فريقنا المختص بمراجعة هذا الطلب للتأكد. لقد أحلت طلبك – وسيتواصل '
            . 'معك فريقنا.',
    ];

    /** KI-Dienst nicht erreichbar (Abschnitt 31). */
    public const FALLBACK = [
        'de' => 'Unser automatischer Assistent ist momentan nicht verfügbar. Ihre Anfrage wurde '
            . 'aufgenommen und wird von unserem Team bearbeitet.',
        'en' => 'Our automated assistant is currently unavailable. Your request has been recorded '
            . 'and will be handled by our team.',
        'ar' => 'مساعدنا الآلي غير متاح حالياً. تم تسجيل طلبك وسيتولى فريقنا معالجته.',
    ];

    /** Grenze automatischer Antworten erreicht (Abschnitt 30). */
    public const LIMIT = [
        'de' => 'Damit Ihr Anliegen richtig bearbeitet wird, übernimmt ab hier ein Mitarbeiter '
            . 'unseres Teams. Ihre Anfrage liegt bereits vor – wir melden uns bei Ihnen.',
        'en' => 'To make sure your request is handled properly, a member of our team will take '
            . 'over from here. Your request has been recorded – we will get back to you.',
        'ar' => 'لضمان معالجة طلبك بشكل صحيح، سيتولى أحد موظفي فريقنا المتابعة من هنا. طلبك مسجل '
            . 'لدينا – وسنتواصل معك.',
    ];

    /**
     * Text in der erkannten Sprache; Deutsch ist der Rueckfall (Haussprache
     * des Portals).
     *
     * @param array<string,string> $texts eine der Konstanten
     */
    public static function pick(array $texts, string $language): string
    {
        return $texts[$language] ?? $texts['de'];
    }
}
