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

    'lexoffice' => ['key' => env('LEXOFFICE_API_KEY')],

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
    ],
];
