<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Bekannte, mehrseitige Formulare auf ihre fachlich relevanten Seiten
    // reduzieren (RelevantPageSelector), bevor Heuristik/KI sie sehen. So
    // fliegen Rechtstext/Anhang raus - weniger Rauschen, weniger KI-Tokens.
    // 'markers' = Stichwoerter zur Erkennung, 'pages' = 1-basierte Seiten.
    'document_profiles' => [
        // CHECK24-Beratungsprotokoll (Kfz): nur diese Seiten tragen Kunden-,
        // Fahrzeug- und Tarifdaten (Betreiber-Vorgabe).
        ['markers' => ['BERATUNGSPROTOKOLL'], 'pages' => [1, 2, 4, 5, 6, 7]],
    ],

    // Meta Graph API (Banner-Social-Publishing Phase 2): direktes Posten
    // auf die EIGENE Facebook-Seite + das verknuepfte Instagram-Business-
    // Konto. System-User-Token aus dem Business Manager (laeuft nicht ab);
    // Werte NUR in der Server-.env, nie im Repo/Chat. Einrichtung Schritt
    // fuer Schritt: docs/ANLEITUNG_META_API_AR.md
    'meta' => [
        'page_id' => env('META_PAGE_ID'),
        'ig_user_id' => env('META_IG_USER_ID'),
        'token' => env('META_ACCESS_TOKEN'),
        // PAGE Access Token: Pflicht fuer Seiten-Posts/-Insights (das
        // System-User-Token reicht dort nicht). Schreibt der Assistent;
        // fehlt es, leitet MetaGraphClient::pageToken() es zur Laufzeit ab.
        'page_token' => env('META_PAGE_ACCESS_TOKEN'),
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        // Werbekonto (act_...) fuer die Anzeigen-Steuerung aus dem System
        // (Phase 3). Der Assistent meta:einrichten findet es automatisch.
        'ad_account_id' => env('META_AD_ACCOUNT_ID'),
        // Schutzgrenze: hoechstes Tagesbudget in EUR, das aus dem System
        // heraus gesetzt werden kann (echtes Geld - bewusst gedeckelt).
        'ads_max_daily_budget' => (int) env('META_ADS_MAX_DAILY_BUDGET', 100),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'inquiry' => [
        'support_email' => env('INQUIRY_SUPPORT_EMAIL'),'token' => env('INQUIRY_TOKEN')],

    /*
    | Cloudflare Turnstile: Bot-Schutz der Selbst-Registrierung (Audit SEC-1).
    | Turnstile statt reCAPTCHA, weil Cloudflare ohnehin der Edge-Proxy ist -
    | kein zusaetzlicher Drittanbieter, kein zusaetzlicher AV-Vertrag, und die
    | Loesung kommt ohne Nutzer-Raetsel aus.
    |
    | Die Pruefung laeuft SERVERSEITIG gegen siteverify. Das Widget allein ist
    | wertlos: ein Bot spricht ohnehin nicht mit dem Browser-JavaScript,
    | sondern direkt mit POST /register.
    |
    | Ohne Secret laeuft die Registrierung im Nur-Honeypot-Modus (Entwicklung
    | und Tests). In Produktion ist das Secret Pflicht - `required()` in
    | TurnstileVerifier lehnt dort ohne Secret jede Registrierung ab, statt
    | den Schutz still zu ueberspringen.
    */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
        'timeout' => (int) env('TURNSTILE_TIMEOUT', 5),
    ],

    'lexoffice' => [
        'key' => env('LEXOFFICE_API_KEY'),
        // Ohne ausdrueckliches Zeitlimit wartet Laravel 30 s je Versuch -
        // mit retry(2) also bis zu 90 s, in denen ein Mitarbeiter vor einer
        // haengenden Seite sitzt und ein PHP-Prozess blockiert ist. Bei einer
        // Buchhaltungs-API ist ein Ausfall kein Sonderfall, sondern
        // gelegentlich Alltag.
        'timeout' => (int) env('LEXOFFICE_TIMEOUT', 10),
        'connect_timeout' => (int) env('LEXOFFICE_CONNECT_TIMEOUT', 5),
    ],

    /*
    | OAuth-Apps für die Postfach-Anbindung (Phase 2). Die Client-IDs
    | stammen aus der Google-Cloud-Console bzw. dem Microsoft-Entra-
    | Admin-Center; ohne Konfiguration zeigen die Provider eine klare
    | "nicht konfiguriert"-Meldung statt zu raten.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        // Dokument-Analyse (Vision/PDF) kann ein eigenes Modell nutzen;
        // ohne Angabe gilt das Standard-Modell.
        'document_model' => env('ANTHROPIC_DOCUMENT_MODEL', env('ANTHROPIC_MODEL', 'claude-sonnet-5')),
        // Obergrenze der Antwort-Tokens der Dokument-Analyse. Das JSON umfasst
        // Person, Vertrag, Fahrzeug/Tarif, Personenliste und Energie - zu
        // knappe Werte schneiden die Antwort mittendrin ab (ungueltiges JSON).
        'document_max_tokens' => env('ANTHROPIC_DOCUMENT_MAX_TOKENS', 4096),
        // HOST-Wurzel ohne Versionspfad (Konvention der offiziellen
        // Anthropic-SDKs); den Pfad /v1/messages haengt der Client an.
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),

        /*
        | KI-Kundenassistent ueber Claude (Betreiber-Entscheidung
        | 17.08.2026): nutzt DENSELBEN ANTHROPIC_API_KEY wie die
        | Dokumentanalyse - kein zweiter Zugang noetig.
        */
        'assistant_model' => env('ANTHROPIC_ASSISTANT_MODEL', 'claude-opus-5'),
        // Deckelt Denk- UND Antwort-Tokens gemeinsam: grosszuegig, damit die
        // kurze Antwort nicht mitten im Satz abbricht.
        'assistant_max_tokens' => env('ANTHROPIC_ASSISTANT_MAX_TOKENS', 4096),
        // Kundenservice-Auskunft ist eine Nachschlage-Aufgabe: wenig
        // Denkaufwand genuegt und haelt Kosten und Wartezeit niedrig.
        'assistant_effort' => env('ANTHROPIC_ASSISTANT_EFFORT', 'low'),
        'assistant_timeout' => env('ANTHROPIC_ASSISTANT_TIMEOUT', 45),
        'assistant_connect_timeout' => env('ANTHROPIC_ASSISTANT_CONNECT_TIMEOUT', 10),
    ],

    /*
    | Smart Document Upload: austauschbarer KI-Anbieter (aktuell nur
    | 'claude') - siehe App\Services\Ai\Contracts\DocumentAiProviderInterface
    | und die Registrierung in AppServiceProvider.
    */
    'ai_document_provider' => env('AI_DOCUMENT_PROVIDER', 'claude'),

    /*
    | Provider-unabhaengige LLM-Schicht der AI-Workflow-Engine (Saeule 8):
    | austauschbarer Text-/Vision-Anbieter (aktuell nur 'claude') - siehe
    | App\Services\Ai\Contracts\AiProviderInterface.
    */
    'ai_text_provider' => env('AI_TEXT_PROVIDER', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | KI-Kundenassistent (Portal-Chat) - Betreiber-Auftrag 17.08.2026
    |--------------------------------------------------------------------------
    |
    | Der API-Key gehoert AUSSCHLIESSLICH in die Server-`.env` (bzw. ein
    | GitHub Secret) - NIE ins Repository, ins HTML, in JavaScript oder in
    | Logs. Der Kunde spricht immer nur mit dem Portal, nie mit OpenAI.
    |
    |   OPENAI_API_KEY=sk-...        <- hier hinterlegen (Server-.env)
    |
    | Ohne Key bleibt der Assistent stumm und das Team bearbeitet die
    | Anfragen wie vorher (Fallback, Spezifikation Abschnitt 31).
    */
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        // Antwortlaenge: der Assistent soll KURZ antworten (Abschnitt 17).
        'max_output_tokens' => env('OPENAI_MAX_OUTPUT_TOKENS', 700),
        // Zeitgrenzen (Abschnitt 32): lieber Fallback als haengender Job.
        'timeout' => env('OPENAI_TIMEOUT', 45),
        'connect_timeout' => env('OPENAI_CONNECT_TIMEOUT', 10),
        // Manche Modelle akzeptieren nur den Standardwert - leer lassen
        // sendet den Parameter gar nicht mit.
        'temperature' => env('OPENAI_TEMPERATURE'),
        // Keine Speicherung der Konversation beim Anbieter (Datenschutz,
        // Abschnitt 21). Bewusst per Default aus.
        'store' => env('OPENAI_STORE', false),
    ],

    /*
    | Austauschbarer Anbieter des Kundenassistenten ('claude', 'openai',
    | 'none') - siehe
    | App\Services\Ai\Assistant\Contracts\AssistantProviderInterface.
    |
    | Voreinstellung 'claude' (Betreiber-Entscheidung 17.08.2026): der
    | Assistent nutzt damit DENSELBEN ANTHROPIC_API_KEY wie die
    | Dokumentanalyse - kein zweiter Anbieter-Zugang, keine zweite
    | Fakturierung, kein zweiter AV-Vertrag. 'openai' bleibt vollwertig
    | verfuegbar (dann zusaetzlich OPENAI_API_KEY setzen); 'none' schaltet
    | den Assistenten hart ab.
    */
    'ai_assistant_provider' => env('AI_ASSISTANT_PROVIDER', 'claude'),

    /*
    | Technische Obergrenzen des Assistenten (Abschnitt 32). Die
    | BETRIEBLICHEN Schalter (an/aus, Automatiken, Antworten je Vorgang)
    | pflegt der Betreiber in der Beraterwelt unter Einstellungen -
    | siehe App\Services\Ai\Assistant\AssistantSettings.
    */
    'ai_assistant' => [
        // Harte Obergrenze der Tool-Runden je Kundennachricht: verhindert
        // Endlosschleifen und unkontrolliertes Tool Calling.
        'max_tool_rounds' => env('AI_ASSISTANT_MAX_TOOL_ROUNDS', 5),
        'max_tool_calls' => env('AI_ASSISTANT_MAX_TOOL_CALLS', 10),
        // Nachrichten je Kunde und Stunde (Kostenbremse pro Kunde).
        'rate_per_hour' => env('AI_ASSISTANT_RATE_PER_HOUR', 20),
        // Gesamtzahl der KI-Antworten pro Tag ueber ALLE Kunden.
        'daily_reply_limit' => env('AI_ASSISTANT_DAILY_LIMIT', 500),
        // Laengere Kundennachrichten werden gekuerzt an das Modell gegeben
        // (Schutz gegen Token-Explosion durch eingefuegte Textwaende).
        'max_message_chars' => env('AI_ASSISTANT_MAX_MESSAGE_CHARS', 4000),
        // So viele vorangehende Chat-Nachrichten kommen als Verlauf mit.
        'history_messages' => env('AI_ASSISTANT_HISTORY_MESSAGES', 8),
    ],

    /*
    | Kostenlose OCR-Basisebene (Tesseract) fuer den Smart Document Upload.
    | Standardmaessig AUS: erst nach Installation von `tesseract-ocr`,
    | `tesseract-ocr-deu` und (fuer PDFs) `poppler-utils` auf dem Server
    | per OCR_ENABLED=true einschalten (siehe CLAUDE.md).
    */
    'ocr' => [
        'enabled' => env('OCR_ENABLED', false),
        'languages' => env('OCR_LANGUAGES', 'deu+eng'),
        'tesseract_binary' => env('OCR_TESSERACT_BINARY', 'tesseract'),
        'pdftoppm_binary' => env('OCR_PDFTOPPM_BINARY', 'pdftoppm'),
        'pdftotext_binary' => env('OCR_PDFTOTEXT_BINARY', 'pdftotext'),
        // Digitale PDFs (CHECK24-Protokolle, Versicherer-Portale, alles aus
        // einer Software) tragen eine perfekte Textebene. Sie VOR OCR/Vision
        // kostenlos per pdftotext zu lesen, spart die teure KI-Eskalation.
        // Teil der kostenlosen Basisebene und daher an dieselbe bewusste
        // Freischaltung wie OCR gekoppelt (Default = OCR_ENABLED); separat
        // per OCR_TEXT_LAYER abschaltbar. In Produktion ist OCR_ENABLED=true,
        // damit ist die Textebene aktiv (nur poppler-utils noetig).
        // Bildschirmfotos kommen mit ~150 dpi und feiner Schrift: Tesseract
        // verwechselt darin aehnliche Zeichen (1/I, f verschluckt). Bilder
        // UNTERHALB dieser Kantenlaenge werden vor der OCR verdoppelt -
        // gratis und deutlich genauer. 0 schaltet das Vergroessern ab.
        'upscale_below_px' => env('OCR_UPSCALE_BELOW_PX', 2600),
        'text_layer' => env('OCR_TEXT_LAYER', env('OCR_ENABLED', false)),
        'text_layer_max_pages' => env('OCR_TEXT_LAYER_MAX_PAGES', 15),
        // Oberhalb dieser Zeichenzahl ist ein Dokument fuer die einfache
        // Stichwort-/Regex-Heuristik zu komplex (mehrseitige Protokolle mit
        // vielen Abschnitten -> Falschtreffer). Solche Dokumente werden zur
        // genauen KI-Analyse eskaliert - aber auf dem billigen Textweg.
        'heuristic_max_chars' => env('OCR_HEURISTIC_MAX_CHARS', 2500),
        // Bei vorhandener Textebene bekommt die KI den TEXT (auf so viele
        // Zeichen gekuerzt) statt der teuren Bild-/PDF-Seiten - massiv
        // guenstiger bei gleicher Genauigkeit fuer digitale PDFs. Grosszuegig
        // genug, dass die auf relevante Seiten reduzierten Formulare (siehe
        // document_profiles) komplett hineinpassen.
        'ai_text_max_chars' => env('OCR_AI_TEXT_MAX_CHARS', 16000),
        // Leistungs-/Zeitgrenzen, damit OCR auf schwacher VPS-Hardware nie das
        // Job-Timeout sprengt (sonst haengt das Dokument in 'processing').
        'dpi' => env('OCR_DPI', 150),
        'max_pages' => env('OCR_MAX_PAGES', 10),
        'max_seconds' => env('OCR_MAX_SECONDS', 60),
        'page_timeout' => env('OCR_PAGE_TIMEOUT', 20),
        // Kostenbremse: wie oft ein einzelner Verwaltungs-Account pro Tag die
        // ERZWUNGENE (kostenpflichtige) KI-Analyse ausloesen darf. Der
        // kostenlose Re-Run (Parser/OCR) ist davon nicht betroffen.
        'force_ai_daily_limit' => env('OCR_FORCE_AI_DAILY_LIMIT', 40),
        // Rueckstau-Alarm: so viele seit >30 Min unbearbeitete (pending)
        // Dokumente deuten auf einen toten Queue-Worker hin -> Glocke an die
        // Verwaltung (INT-10). 0 schaltet den Alarm ab.
        'pending_backlog_alert' => env('OCR_PENDING_BACKLOG_ALERT', 10),
    ],
];
