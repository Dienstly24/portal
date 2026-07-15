# Spam-Schutz fuer die Website-Anfragen ("Neue Anfrage ueber Dienstly24")

## Problem

Ueber das Kontaktformular der oeffentlichen Website (`dienstly24.de`)
trafen Bot-Nachrichten mit Werbe-/Gluecksspiel-Spam ein
(z. B. "888starz apk download", teils mit kaputtem Zeichensatz). Die Mail
kommt als **"Neue Kontaktanfrage von der Dienstly24-Website"** an
`info@dienstly24.de`.

Wichtig zur Einordnung:

- Diese Mail wird vom **WordPress-Formular auf der Website** erzeugt, nicht
  vom Portal. Das Portal sendet ein anderes Format
  ("Neue Kundenanfrage ueber die Webseite", HTML, Kundennummer usw.).
- Die **Portal-Endpunkte sind bereits geschuetzt**:
  - `/api/website-inquiry` (WordPress-Lead-API): Token `X-Inquiry-Token`
    plus `throttle:30,1`.
  - `/hilfe` und `/leistungen/{slug}/anfrage`: Honeypot-Feld `website`.
- Zusaetzlich verwerfen jetzt **alle drei Portal-Formularendpunkte**
  inhaltlich erkannten Spam still (kein Ticket, keine Mail) ueber
  `App\Services\SpamFilter`.

Der eigentliche Spam kommt also **vom WordPress-Formular** und muss dort
gestoppt werden. Zwei Wege:

## Weg A (schnell, empfohlen): WordPress-Formular absichern

1. **CAPTCHA aktivieren** (wichtigster Schritt):
   - Contact Form 7: Plugin **Cloudflare Turnstile** oder **reCAPTCHA v3**
     installieren und im Formular einbinden.
   - WPForms / Elementor Forms: reCAPTCHA/hCaptcha ist eingebaut -> in den
     Formular-Einstellungen aktivieren.
2. **Honeypot** zusaetzlich einschalten (verstecktes Feld; Bots fuellen es,
   Menschen nicht). Viele Formular-Plugins haben das als Option.
3. **Cloudflare** vor die Domain (falls dort verwaltet): "Bot Fight Mode"
   plus WAF-Regel, die Anfragen an die Formular-URL mit Woertern wie
   `888starz`, `apk`, `casino` oder vielen Links blockt.
4. **Akismet** oder Stichwort-Filter im Plugin ergaenzen.

## Weg B (robuster): WordPress-Formular an die Portal-API haengen

Statt selbst eine Mail zu senden, POSTet das WordPress-Formular an den
bereits geschuetzten Endpunkt `/api/website-inquiry`. Vorteile: Token +
Throttle + Inhaltsfilter greifen automatisch, und jede Anfrage wird als
**Ticket** im Portal erfasst (statt als lose Mail).

Snippet fuer `functions.php` des (Child-)Themes - Token vorher als
`INQUIRY_TOKEN` in der Portal-`.env` setzen und hier eintragen:

```php
// Leitet Kontaktformular-Eingaben an die Dienstly24-Portal-API weiter.
add_action('wpcf7_before_send_mail', function ($contact_form) {
    $submission = WPCF7_Submission::get_instance();
    if (!$submission) {
        return;
    }
    $d = $submission->get_posted_data();

    $payload = [
        'name'    => sanitize_text_field($d['your-name']    ?? ''),
        'email'   => sanitize_email($d['your-email']        ?? ''),
        'phone'   => sanitize_text_field($d['your-phone']   ?? ''),
        'subject' => sanitize_text_field($d['your-subject'] ?? ''),
        'message' => sanitize_textarea_field($d['your-message'] ?? ''),
    ];

    wp_remote_post('https://portal.dienstly24.de/api/website-inquiry', [
        'headers' => [
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
            'X-Inquiry-Token' => 'HIER_DAS_INQUIRY_TOKEN_EINTRAGEN',
        ],
        'body'    => wp_json_encode($payload),
        'timeout' => 8,
    ]);
});
```

Feldnamen (`your-name` usw.) an das tatsaechliche Formular anpassen.

## Was im Portal bereits umgesetzt ist

`App\Services\SpamFilter` bewertet Name + Nachricht (+ Betreff) konservativ:

- **Signalwoerter** (Gluecksspiel, Pillen, Crypto, Adult, SEO-Spam).
- **Viele Links** (ab 2 verdaechtig, ab 4 stark).
- **Mojibake** (kaputter Zeichensatz ab vielen Vorkommen).

Erst ab einer Punkt-Schwelle gilt eine Nachricht als Spam, damit echte
deutsche wie arabische Anfragen nicht faelschlich verworfen werden.
Verworfener Spam wird per `Log::info` protokolliert ("... als Spam
verworfen: score=...").
