<?php

/**
 * DEUTSCHE Formularfehler (Betreiber-Meldung 10.09.2026).
 *
 * Es gab lang/ar/validation.php, aber KEINE deutsche Datei - und die
 * Fallback-Sprache ist Englisch. Jeder Mitarbeiter bekam bei einem
 * Formularfehler also einen englischen Satz MIT dem technischen Feldnamen:
 *
 *   "The signers.1.name field must be a string."
 *
 * Das nennt weder, WAS falsch ist, noch WO. Genau daran endet die Arbeit
 * an einem Formular, weil niemand raten will, welche Zeile gemeint war.
 *
 * Nicht uebersetzte Schluessel fallen automatisch auf Englisch zurueck;
 * die Datei muss also nicht jede Regel abdecken - aber jede, die im Alltag
 * vorkommt.
 */
return [
    'accepted' => ':attribute muss bestätigt werden.',
    'active_url' => ':attribute ist keine gültige Internetadresse.',
    'after' => ':attribute muss ein Datum nach dem :date sein.',
    'after_or_equal' => ':attribute muss ein Datum nach dem :date oder gleich diesem sein.',
    'alpha' => ':attribute darf nur Buchstaben enthalten.',
    'alpha_dash' => ':attribute darf nur Buchstaben, Zahlen, Binde- und Unterstriche enthalten.',
    'alpha_num' => ':attribute darf nur Buchstaben und Zahlen enthalten.',
    'array' => ':attribute muss eine Liste sein.',
    'before' => ':attribute muss ein Datum vor dem :date sein.',
    'before_or_equal' => ':attribute muss ein Datum vor dem :date oder gleich diesem sein.',
    'between' => [
        'array' => ':attribute muss zwischen :min und :max Einträge haben.',
        'file' => ':attribute muss zwischen :min und :max Kilobyte groß sein.',
        'numeric' => ':attribute muss zwischen :min und :max liegen.',
        'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => ':attribute muss ja oder nein sein.',
    'confirmed' => 'Die Wiederholung von :attribute stimmt nicht überein.',
    'current_password' => 'Das Passwort ist nicht korrekt.',
    'date' => ':attribute ist kein gültiges Datum.',
    'date_equals' => ':attribute muss der :date sein.',
    'date_format' => ':attribute hat nicht das erwartete Format (:format).',
    'declined' => ':attribute muss abgelehnt werden.',
    'different' => ':attribute und :other dürfen nicht gleich sein.',
    'digits' => ':attribute muss :digits Ziffern haben.',
    'digits_between' => ':attribute muss zwischen :min und :max Ziffern haben.',
    'dimensions' => ':attribute hat unzulässige Bildabmessungen.',
    'distinct' => ':attribute ist doppelt vorhanden.',
    'doesnt_end_with' => ':attribute darf nicht mit einem der folgenden Werte enden: :values.',
    'doesnt_start_with' => ':attribute darf nicht mit einem der folgenden Werte beginnen: :values.',
    'email' => ':attribute ist keine gültige E-Mail-Adresse.',
    'ends_with' => ':attribute muss mit einem der folgenden Werte enden: :values.',
    'enum' => 'Der gewählte Wert für :attribute ist ungültig.',
    'exists' => 'Der gewählte Wert für :attribute existiert nicht.',
    'file' => ':attribute muss eine Datei sein.',
    'filled' => ':attribute muss ausgefüllt sein.',
    'gt' => [
        'array' => ':attribute muss mehr als :value Einträge haben.',
        'file' => ':attribute muss größer als :value Kilobyte sein.',
        'numeric' => ':attribute muss größer als :value sein.',
        'string' => ':attribute muss länger als :value Zeichen sein.',
    ],
    'gte' => [
        'array' => ':attribute muss mindestens :value Einträge haben.',
        'file' => ':attribute muss mindestens :value Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :value sein.',
        'string' => ':attribute muss mindestens :value Zeichen lang sein.',
    ],
    'image' => ':attribute muss ein Bild sein.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'in_array' => ':attribute kommt in :other nicht vor.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'ip' => ':attribute muss eine gültige IP-Adresse sein.',
    'ipv4' => ':attribute muss eine gültige IPv4-Adresse sein.',
    'ipv6' => ':attribute muss eine gültige IPv6-Adresse sein.',
    'json' => ':attribute muss ein gültiger JSON-Text sein.',
    'lowercase' => ':attribute darf nur Kleinbuchstaben enthalten.',
    'lt' => [
        'array' => ':attribute muss weniger als :value Einträge haben.',
        'file' => ':attribute muss kleiner als :value Kilobyte sein.',
        'numeric' => ':attribute muss kleiner als :value sein.',
        'string' => ':attribute muss kürzer als :value Zeichen sein.',
    ],
    'lte' => [
        'array' => ':attribute darf höchstens :value Einträge haben.',
        'file' => ':attribute darf höchstens :value Kilobyte groß sein.',
        'numeric' => ':attribute darf höchstens :value sein.',
        'string' => ':attribute darf höchstens :value Zeichen lang sein.',
    ],
    'max' => [
        'array' => ':attribute darf höchstens :max Einträge haben.',
        'file' => ':attribute darf höchstens :max Kilobyte groß sein.',
        'numeric' => ':attribute darf höchstens :max sein.',
        'string' => ':attribute darf höchstens :max Zeichen lang sein.',
    ],
    'mimes' => ':attribute muss eine Datei vom Typ :values sein.',
    'mimetypes' => ':attribute muss eine Datei vom Typ :values sein.',
    'min' => [
        'array' => ':attribute muss mindestens :min Einträge haben.',
        'file' => ':attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'not_in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'not_regex' => ':attribute hat ein ungültiges Format.',
    'numeric' => ':attribute muss eine Zahl sein.',
    'present' => ':attribute muss vorhanden sein.',
    'prohibited' => ':attribute ist nicht erlaubt.',
    'prohibited_if' => ':attribute ist hier nicht erlaubt. Grund: :other hat den Wert :value.',
    'prohibits' => ':attribute schließt :other aus.',
    'regex' => ':attribute hat ein ungültiges Format.',
    'required' => ':attribute muss ausgefüllt werden.',
    'required_array_keys' => ':attribute muss Einträge für :values enthalten.',
    'required_if' => ':attribute muss ausgefüllt werden. Grund: :other hat den Wert :value.',
    'required_if_accepted' => ':attribute muss ausgefüllt werden. Grund: :other ist gewählt.',
    'required_unless' => ':attribute muss ausgefüllt werden. Ausnahme: :other hat den Wert :values.',
    'required_with' => ':attribute muss ausgefüllt werden. Grund: :values ist angegeben.',
    'required_with_all' => ':attribute muss ausgefüllt werden. Grund: :values sind angegeben.',
    'required_without' => ':attribute muss ausgefüllt werden. Grund: :values fehlt.',
    'required_without_all' => ':attribute muss ausgefüllt werden. Grund: :values fehlen alle.',
    'same' => ':attribute und :other müssen übereinstimmen.',
    'size' => [
        'array' => ':attribute muss genau :size Einträge haben.',
        'file' => ':attribute muss genau :size Kilobyte groß sein.',
        'numeric' => ':attribute muss genau :size sein.',
        'string' => ':attribute muss genau :size Zeichen lang sein.',
    ],
    'starts_with' => ':attribute muss mit einem der folgenden Werte beginnen: :values.',
    'string' => ':attribute muss ein Text sein.',
    'timezone' => ':attribute muss eine gültige Zeitzone sein.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden.',
    'uppercase' => ':attribute darf nur Großbuchstaben enthalten.',
    'url' => ':attribute muss eine gültige Internetadresse sein.',
    'uuid' => ':attribute muss eine gültige Kennung sein.',

    'custom' => [],

    /**
     * FELDNAMEN IM KLARTEXT.
     *
     * Ohne sie steht in der Meldung der technische Schluessel, und bei
     * Listen sogar mit Index: "signers.1.name". Der Mitarbeiter soll aber
     * lesen koennen, WELCHE Zeile gemeint ist - deshalb sind die
     * Listenfelder einzeln benannt (Laravel zaehlt ab 0, der Mensch ab 1;
     * die Nummer im Text ist deshalb um eins hoeher).
     */
    'attributes' => [
        'name' => 'Der Name',
        'first_name' => 'Der Vorname',
        'last_name' => 'Der Nachname',
        'email' => 'Die E-Mail-Adresse',
        'identifier' => 'Die E-Mail-Adresse oder Kundennummer',
        'password' => 'Das Passwort',
        'password_confirmation' => 'Die Passwort-Wiederholung',
        'current_password' => 'Das aktuelle Passwort',
        'phone' => 'Die Telefonnummer',
        'mobile' => 'Die Mobilnummer',
        'birth_date' => 'Das Geburtsdatum',
        'address' => 'Die Anschrift',
        'message' => 'Die Nachricht',
        'subject' => 'Der Betreff',
        'consent' => 'Die Einwilligung',
        'file' => 'Die Datei',
        'files' => 'Die Dateien',
        'title' => 'Der Titel',
        'document' => 'Die PDF-Datei',
        'code' => 'Der Bestätigungscode',
        'grund' => 'Der Grund',
        'geburtsdatum' => 'Das Geburtsdatum',
        'zustimmung' => 'Die Zustimmung',

        // Signaturanfrage - die Zeilen des Unterzeichner-Blocks.
        'signers.0.name' => 'Der Name des 1. Unterzeichners',
        'signers.0.email' => 'Die E-Mail-Adresse des 1. Unterzeichners',
        'signers.0.date_of_birth' => 'Das Geburtsdatum des 1. Unterzeichners',
        'signers.0.locale' => 'Die Sprache des 1. Unterzeichners',
        'signers.1.name' => 'Der Name des 2. Unterzeichners',
        'signers.1.email' => 'Die E-Mail-Adresse des 2. Unterzeichners',
        'signers.1.date_of_birth' => 'Das Geburtsdatum des 2. Unterzeichners',
        'signers.1.locale' => 'Die Sprache des 2. Unterzeichners',
        'signers.2.name' => 'Der Name des 3. Unterzeichners',
        'signers.2.email' => 'Die E-Mail-Adresse des 3. Unterzeichners',
        'signers.3.name' => 'Der Name des 4. Unterzeichners',
        'signers.3.email' => 'Die E-Mail-Adresse des 4. Unterzeichners',
        'signers.*.name' => 'Der Name des Unterzeichners',
        'signers.*.email' => 'Die E-Mail-Adresse des Unterzeichners',
        'signers.*.date_of_birth' => 'Das Geburtsdatum des Unterzeichners',
        'signers.*.locale' => 'Die Sprache des Unterzeichners',
        'identity_check' => 'Die zusätzliche Identitätsprüfung',
        'consent_text' => 'Der Hinweis vor der Unterschrift',
        'expires_at' => 'Das Ablaufdatum',
        'reference' => 'Die Referenz',
        'document_type' => 'Die Dokumentart',
    ],
];
