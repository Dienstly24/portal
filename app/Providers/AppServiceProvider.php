<?php

namespace App\Providers;

use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Activity\ActivityCatalog;
use App\Services\Activity\ActivityTracker;
use App\Services\Ai\Assistant\ClaudeAssistantProvider;
use App\Services\Ai\Assistant\Contracts\AssistantProviderInterface;
use App\Services\Ai\Assistant\NullAssistantProvider;
use App\Services\Ai\Assistant\OpenAiAssistantProvider;
use App\Services\Ai\Assistant\Sales\Offers\ManualOfferSource;
use App\Services\Ai\Assistant\Sales\Offers\OfferSourceInterface;
use App\Services\Ai\Assistant\Tools\AssistantToolRegistry;
use App\Services\Ai\Assistant\Tools\CreateTicketTool;
use App\Services\Ai\Assistant\Tools\EscalateToTeamTool;
use App\Services\Ai\Assistant\Tools\GetCustomerContractsTool;
use App\Services\Ai\Assistant\Tools\GetCustomerProfileTool;
use App\Services\Ai\Assistant\Tools\GetDocumentStatusTool;
use App\Services\Ai\Assistant\Tools\GetMissingDocumentsTool;
use App\Services\Ai\Assistant\Tools\GetOpenTicketsTool;
use App\Services\Ai\Assistant\Tools\GetProcessStatusTool;
use App\Services\Ai\Assistant\Tools\GetRelevantContractInformationTool;
use App\Services\Ai\Assistant\Tools\GetRequiredDocumentsTool;
use App\Services\Ai\Assistant\Tools\RequestDocumentTool;
use App\Services\Ai\Assistant\Tools\Sales\GetConversationStateTool;
use App\Services\Ai\Assistant\Tools\Sales\GetOffersTool;
use App\Services\Ai\Assistant\Tools\Sales\RecordOfferSelectionTool;
use App\Services\Ai\Assistant\Tools\Sales\RequestOfferFromTeamTool;
use App\Services\Ai\Assistant\Tools\Sales\SaveCollectedInformationTool;
use App\Services\Ai\Assistant\Tools\Sales\SetConversationIntentTool;
use App\Services\Ai\Assistant\Tools\Sales\SubmitContractDataTool;
use App\Services\Ai\Assistant\Tools\SearchKnowledgeTool;
use App\Services\Ai\Assistant\Website\LeadToolRegistry;
use App\Services\Ai\Assistant\Website\Tools\RequestHumanContactTool;
use App\Services\Ai\Assistant\Website\Tools\SaveLeadInformationTool;
use App\Services\Ai\Assistant\Website\Tools\SearchPublicKnowledgeTool;
use App\Services\Ai\ClaudeDocumentAiProvider;
use App\Services\Ai\ClaudeTextProvider;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\NullDocumentAiProvider;
use App\Services\Ai\TemplateParsers\AdacAutoversicherungParser;
use App\Services\Ai\TemplateParsers\AdacMitgliedschaftParser;
use App\Services\Ai\TemplateParsers\AdmiralDirektKfzParser;
use App\Services\Ai\TemplateParsers\AllianzKfzPoliceParser;
use App\Services\Ai\TemplateParsers\AndsafeGewerbePoliceParser;
use App\Services\Ai\TemplateParsers\AntragBestaetigungParser;
use App\Services\Ai\TemplateParsers\ArbeitsvertragParser;
use App\Services\Ai\TemplateParsers\AufenthaltstitelParser;
use App\Services\Ai\TemplateParsers\BayerischeEscooterParser;
use App\Services\Ai\TemplateParsers\BigGesundParser;
use App\Services\Ai\TemplateParsers\Check24KfzProtocolParser;
use App\Services\Ai\TemplateParsers\CompositeDocumentTemplateParser;
use App\Services\Ai\TemplateParsers\DaDirektKfzPoliceParser;
use App\Services\Ai\TemplateParsers\DeckungsauftragParser;
use App\Services\Ai\TemplateParsers\DialogFrachtfuehrerPoliceParser;
use App\Services\Ai\TemplateParsers\DslAuftragParser;
use App\Services\Ai\TemplateParsers\EnergieAuftragParser;
use App\Services\Ai\TemplateParsers\EnergiePortalAuftragParser;
use App\Services\Ai\TemplateParsers\ErsatzbescheinigungParser;
use App\Services\Ai\TemplateParsers\EuropaGoKfzParser;
use App\Services\Ai\TemplateParsers\EweVertragsbestaetigungParser;
use App\Services\Ai\TemplateParsers\FamilienversicherungParser;
use App\Services\Ai\TemplateParsers\GeburtsurkundeParser;
use App\Services\Ai\TemplateParsers\GehaltsabrechnungParser;
use App\Services\Ai\TemplateParsers\GesundheitskarteParser;
use App\Services\Ai\TemplateParsers\GewerbeBeratungsdokumentationParser;
use App\Services\Ai\TemplateParsers\GruenweltLieferbestaetigungParser;
use App\Services\Ai\TemplateParsers\InterlloydPoliceParser;
use App\Services\Ai\TemplateParsers\KkhBeitrittserklaerungParser;
use App\Services\Ai\TemplateParsers\KontaktdatenBlockParser;
use App\Services\Ai\TemplateParsers\KontaktSepaDatenParser;
use App\Services\Ai\TemplateParsers\LichtblickAuftragParser;
use App\Services\Ai\TemplateParsers\LichtblickVertragsbestaetigungParser;
use App\Services\Ai\TemplateParsers\MeldebestaetigungParser;
use App\Services\Ai\TemplateParsers\NafiKfzAntragParser;
use App\Services\Ai\TemplateParsers\NovitasBeitrittserklaerungParser;
use App\Services\Ai\TemplateParsers\OnlineProtokollAntragParser;
use App\Services\Ai\TemplateParsers\PlanBNetZeroAuftragParser;
use App\Services\Ai\TemplateParsers\PrivathaftpflichtAntragParser;
use App\Services\Ai\TemplateParsers\ReisepassMrzParser;
use App\Services\Ai\TemplateParsers\SparkasseDirektKfzParser;
use App\Services\Ai\TemplateParsers\VermittlerVorgangslisteHinweisParser;
use App\Services\Ai\TemplateParsers\WgvKfzPoliceParser;
use App\Services\Notifications\NotificationService;
use App\Services\Ocr\TesseractTextExtractor;
use App\Services\Ocr\TextExtractorInterface;
use App\Support\LocalTime;
use App\Support\PasswordPolicy;
use App\Support\ProductionDatabaseGuard;
use App\Support\SessionPasswordHash;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton, damit Punkte-Overrides pro Request nur einmal
        // aus den Einstellungen gelesen werden.
        $this->app->singleton(ActivityCatalog::class);

        // Zentraler Notification-Dienst (Glocke): eine Stelle fuer Kuerzen,
        // Duplikat-Vermeidung und Kategorisierung. Facade: App\Support\Facades\Notify.
        $this->app->singleton(NotificationService::class);

        // OCR-Basisebene des Smart Document Upload - austauschbar, falls
        // spaeter ein anderer OCR-Dienst als Tesseract eingesetzt wird.
        $this->app->bind(TextExtractorInterface::class, TesseractTextExtractor::class);

        // Gratis-Parser fuer bekannte, immer gleich aufgebaute Formulare
        // (CHECK24-Kfz-Beratungsprotokoll, KKH-Beitrittserklaerung, Familien-
        // versicherungs-Fragebogen). Weitere Templates: einfach in die Liste
        // aufnehmen. Trifft kein Template zu -> null, dann laeuft die normale
        // Analyse (Heuristik/KI).
        $this->app->bind(
            DocumentTemplateParser::class,
            fn ($app) => new CompositeDocumentTemplateParser([
                // ZUERST: die Vorgangsliste des Vermittlers ist kein
                // Kundendokument. Wird sie frueh erkannt, kostet sie keinen
                // KI-Aufruf und der Eingang nennt den richtigen Weg.
                $app->make(VermittlerVorgangslisteHinweisParser::class),
                $app->make(Check24KfzProtocolParser::class),
                $app->make(AdacAutoversicherungParser::class),
                $app->make(AdacMitgliedschaftParser::class),
                $app->make(DaDirektKfzPoliceParser::class),
                $app->make(AllianzKfzPoliceParser::class),
                $app->make(AdmiralDirektKfzParser::class),
                $app->make(EuropaGoKfzParser::class),
                $app->make(SparkasseDirektKfzParser::class),
                $app->make(WgvKfzPoliceParser::class),
                $app->make(NafiKfzAntragParser::class),
                $app->make(BayerischeEscooterParser::class),
                $app->make(KkhBeitrittserklaerungParser::class),
                $app->make(NovitasBeitrittserklaerungParser::class),
                $app->make(BigGesundParser::class),
                $app->make(FamilienversicherungParser::class),
                $app->make(ErsatzbescheinigungParser::class),
                $app->make(GesundheitskarteParser::class),
                $app->make(GehaltsabrechnungParser::class),
                $app->make(ArbeitsvertragParser::class),
                $app->make(GeburtsurkundeParser::class),
                $app->make(ReisepassMrzParser::class),
                $app->make(MeldebestaetigungParser::class),
                $app->make(AufenthaltstitelParser::class),
                // Energie-Parser (Strom/Gas) VOR dem DSL-Parser: ein echter
                // Energie-Auftrag wird von seinem spezifischen Parser erkannt;
                // der breitere DSL-Parser kommt erst danach zum Zug (frueher
                // beanspruchte er Energie-Auftraege faelschlich als Internet).
                $app->make(EweVertragsbestaetigungParser::class),
                $app->make(LichtblickVertragsbestaetigungParser::class),
                $app->make(GruenweltLieferbestaetigungParser::class),
                $app->make(LichtblickAuftragParser::class),
                $app->make(PlanBNetZeroAuftragParser::class),
                $app->make(EnergieAuftragParser::class),
                // Auftrags-Uebersicht aus dem Vertriebsportal (Screenshot) -
                // nach den PDF-Auftraegen der Versorger, vor dem DSL-Parser.
                $app->make(EnergiePortalAuftragParser::class),
                $app->make(DslAuftragParser::class),
                $app->make(PrivathaftpflichtAntragParser::class),
                $app->make(OnlineProtokollAntragParser::class),
                // Deckungsauftrag VOR der Beratungsdokumentation: beide sind
                // Fonds-Finanz-Schwesterdokumente (Vorgangsnummer). Kommt ein
                // BUENDEL-PDF mit beiden Teilen, gewinnt so der Teil mit den
                // VERTRAGSDATEN (Versicherer/Praemie, Stufe antrag) - die reine
                // Beratungsdoku ohne "Deckungsauftrag"-Wort bleibt unberuehrt
                // (der Deckungsauftrag-Parser weicht ihr nachweislich aus).
                $app->make(DeckungsauftragParser::class),
                $app->make(GewerbeBeratungsdokumentationParser::class),
                // Abschluss-Seite einer Online-Antragsstrecke (Screenshot):
                // Referenz-/eVB-Nummer, noch keine Vertragsnummer.
                $app->make(AntragBestaetigungParser::class),
                $app->make(AndsafeGewerbePoliceParser::class),
                $app->make(InterlloydPoliceParser::class),
                $app->make(DialogFrachtfuehrerPoliceParser::class),
                // Kontakt-/SEPA-Ansicht eines Antragsportals (beschriftete
                // Felder) - VOR dem generischen Kontaktdaten-Block.
                $app->make(KontaktSepaDatenParser::class),
                // Zuletzt: kompakter Kontaktdaten-Block (nur wenn kein echtes
                // Dokument passt - er triggert auf E-Mail+IBAN+PLZ in kurzem Text).
                $app->make(KontaktdatenBlockParser::class),
            ]),
        );

        // KI-Anbieter der Dokumentanalyse: per Konfiguration waehlbar, damit
        // ein weiterer Anbieter spaeter ohne Umbau des restlichen Systems
        // ergaenzt werden kann (siehe DocumentAiProviderInterface).
        $this->app->bind(DocumentAiProviderInterface::class, function ($app) {
            // WICHTIG: LEER/nicht gesetzt bedeutet STANDARD (Claude), NICHT
            // "abgeschaltet" - frueher ergab jeder Wert (auch '') per
            // `default => Claude` den Claude-Provider. Nur AUSDRUECKLICHE
            // Abschalt-Schluessel deaktivieren die KI, damit ein leeres
            // AI_DOCUMENT_PROVIDER in der Produktion die Analyse nicht
            // versehentlich stilllegt.
            $provider = strtolower(trim((string) config('services.ai_document_provider', 'claude')));
            if ($provider === '') {
                $provider = 'claude';
            }
            return match ($provider) {
                'claude' => $app->make(ClaudeDocumentAiProvider::class),
                // Ausdruecklich abgeschaltet: nur die kostenlose Basisebene.
                'none', 'off', 'disabled' => $app->make(NullDocumentAiProvider::class),
                // Unbekannter Wert: nicht still verschlucken - warnen und auf
                // den Standard (Claude) zurueckfallen (Verhalten wie zuvor).
                default => tap($app->make(ClaudeDocumentAiProvider::class), function () use ($provider) {
                    Log::warning(
                        'Unbekannter AI_DOCUMENT_PROVIDER "'.$provider.'" - faellt auf Claude zurueck '
                        .'(gueltig: claude, none).'
                    );
                }),
            };
        });

        // Provider-unabhaengige LLM-Schicht fuer Freitext (heute:
        // EmailDraftService). Ein weiterer Anbieter (OpenAI, Gemini, Azure)
        // braucht nur eine neue Implementierung + einen Zweig hier.
        $this->app->bind(AiProviderInterface::class, function ($app) {
            return match (config('services.ai_text_provider', 'claude')) {
                default => $app->make(ClaudeTextProvider::class),
            };
        });

        // KI-Kundenassistent (Betreiber-Auftrag 17.08.2026): austauschbarer
        // Anbieter mit Tool-Calling. Standard ist 'claude' - der Assistent
        // nutzt damit denselben ANTHROPIC_API_KEY wie die Dokumentanalyse.
        // 'none' schaltet ihn hart ab; ein leerer Wert bedeutet STANDARD,
        // nicht "aus" - gleiche Lehre wie beim Dokument-Anbieter oben.
        $this->app->bind(
            AssistantProviderInterface::class,
            function ($app) {
                $provider = strtolower(trim((string) config('services.ai_assistant_provider', 'claude')));
                if ($provider === '') {
                    $provider = 'claude';
                }
                return match ($provider) {
                    'claude', 'anthropic' => $app->make(ClaudeAssistantProvider::class),
                    'openai' => $app->make(OpenAiAssistantProvider::class),
                    'none', 'off', 'disabled' => $app->make(NullAssistantProvider::class),
                    default => tap($app->make(ClaudeAssistantProvider::class), function () use ($provider) {
                        Log::warning(
                            'Unbekannter AI_ASSISTANT_PROVIDER "'.$provider.'" - faellt auf Claude zurueck '
                            .'(gueltig: claude, openai, none).'
                        );
                    }),
                };
            }
        );

        // WHITELIST der Funktionen, die der Kundenassistent aufrufen darf
        // (Spezifikation Abschnitt 6/7). Was hier NICHT steht, kann die KI
        // nicht ausfuehren - es gibt bewusst kein Tool fuer freie
        // Datenbankabfragen, Vertragsaenderungen, Kuendigungen oder
        // Zahlungen. Neue Faehigkeit = hier eine Zeile, nach Freigabe des
        // Betreibers.
        $this->app->singleton(
            AssistantToolRegistry::class,
            fn ($app) => new AssistantToolRegistry([
                // Lesend (immer erlaubt)
                $app->make(GetCustomerProfileTool::class),
                $app->make(GetCustomerContractsTool::class),
                $app->make(GetRelevantContractInformationTool::class),
                $app->make(GetOpenTicketsTool::class),
                $app->make(GetProcessStatusTool::class),
                $app->make(GetRequiredDocumentsTool::class),
                $app->make(GetMissingDocumentsTool::class),
                $app->make(GetDocumentStatusTool::class),
                $app->make(SearchKnowledgeTool::class),
                // Schreibend (jedes prueft zusaetzlich seinen Schalter)
                $app->make(CreateTicketTool::class),
                $app->make(RequestDocumentTool::class),
                $app->make(EscalateToTeamTool::class),
                // Verkaufsassistent (Betreiber-Auftrag 18.08.2026):
                // Gespraechsfuehrung, Angebote, Vertragsdaten. Bewusst
                // dieselbe Whitelist - es gibt keinen zweiten Weg, auf
                // Daten zuzugreifen.
                $app->make(GetConversationStateTool::class),
                $app->make(GetOffersTool::class),
                $app->make(SetConversationIntentTool::class),
                $app->make(SaveCollectedInformationTool::class),
                $app->make(RequestOfferFromTeamTool::class),
                $app->make(RecordOfferSelectionTool::class),
                $app->make(SubmitContractDataTool::class),
            ])
        );

        // Whitelist des WEBSITE-Assistenten (Spezifikation Abschnitt 19):
        // bewusst nur drei Werkzeuge. Ein nicht angemeldeter Besucher hat
        // keinerlei Zugriff auf Kundendaten - es gibt technisch kein
        // Werkzeug dafuer.
        $this->app->singleton(
            LeadToolRegistry::class,
            fn ($app) => new LeadToolRegistry([
                $app->make(SearchPublicKnowledgeTool::class),
                $app->make(SaveLeadInformationTool::class),
                $app->make(RequestHumanContactTool::class),
            ])
        );

        // Woher kommen Angebote? Phase 1: ein Mitarbeiter hinterlegt sie.
        // Phase 2 tauscht HIER die Implementierung - sonst aendert sich
        // nichts (Spezifikation Abschnitte 6 und 25).
        $this->app->bind(
            OfferSourceInterface::class,
            ManualOfferSource::class
        );

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ARCH-2: SQLite darf in Produktion nicht starten - sonst laeuft das
        // Portal bei einer fehlenden .env still gegen eine leere Datei.
        ProductionDatabaseGuard::pruefen($this->app);

        $this->registerRateLimiters();

        /*
        | ARCH-7: strenge Eloquent-Regeln - aber NUR ausserhalb der Produktion.
        |
        | preventLazyLoading meldet eine Relation, die erst in der Schleife
        | nachgeladen wird (N+1). Das ist genau der Fehler, den man im Alltag
        | nicht sieht: die Seite funktioniert, sie wird nur mit jedem
        | Datensatz langsamer. In der Entwicklung soll sie deshalb LAUT
        | scheitern.
        |
        | In Produktion bewusst AUS: ein vergessenes with() wuerde dort sonst
        | aus einer langsamen Seite eine kaputte machen - der Nutzer saehe
        | einen 500er statt einer Liste. Eine Warnung ist dort das richtige
        | Mittel, kein Abbruch.
        |
        | BEWUSST NICHT eingeschaltet: preventSilentlyDiscardingAttributes.
        | Es klingt verwandt, ist aber eine andere Baustelle - der Bestand
        | uebergibt an rund 35 Stellen bewusst Felder an create()/fill(), die
        | nicht in $fillable stehen (etwa 'id' und 'added_by' beim Vertrag)
        | und sich darauf verlassen, dass sie verworfen werden. Das
        | einzuschalten hiesse, die Mass-Assignment-Freigaben des ganzen
        | Projekts anzufassen - eine Sicherheitsentscheidung, die nicht
        | nebenbei in einer Index-/Aufraeum-Aenderung fallen darf.
        */
        Model::preventLazyLoading(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            Model::handleLazyLoadingViolationUsing(function ($model, $relation) {
                Log::warning('Lazy Loading (N+1) in Produktion', [
                    'model' => $model::class,
                    'relation' => $relation,
                ]);
            });
        }

        /*
        | Content-Security-Policy (Audit SEC-4).
        |
        | @cspNonce setzt das nonce-Attribut auf ein <script>. Nur damit
        | laeuft ein eingebettetes Skript noch - genau das ist der Sinn:
        | ein per XSS eingeschleustes <script> kennt den Zufallswert
        | dieser Antwort nicht.
        |
        | Den Wert an Laravels Vite-Helfer zu haengen erledigt
        | SecurityHeaders je Anfrage - hier waere er einmalig fuer den
        | ganzen Prozess und passte ab der zweiten Antwort nicht mehr.
        */
        Blade::directive(
            'cspNonce',
            fn () => "<?php echo \App\Support\CspNonce::attribute(); ?>"
        );


        // EINE Passwort-Regel fuer alle Pfade, die Rules\Password::defaults()
        // benutzen (Registrierung, Reset, Profil-Aenderung). Die Laenge
        // richtet sich nach der Rolle des Kontos, das gerade ein Passwort
        // setzt - Personal sieht fremde personenbezogene Daten und braucht
        // daher mehr. Quelle: App\Support\PasswordPolicy.
        // Provisionsdaten sind INTERN: Zugriff hat der Admin - und sonst nur,
        // wer das Recht ausdruecklich bekommen hat. Bewusst KEINE Rolle als
        // Kriterium: eine Rolle waechst mit der Zeit um Aufgaben, ein Recht
        // wird einzeln vergeben. Die Regel steht hier an EINER Stelle und
        // wird von Routen, Controllern und Views gemeinsam benutzt.
        Gate::define(
            'provisionen-verwalten',
            fn ($user) => $user->role === 'admin' || (bool) ($user->can_manage_commissions ?? false)
        );

        Password::defaults(
            fn () => PasswordPolicy::for(auth()->user())
        );

        // Fail-fast, falls Produktion versehentlich auf SQLite laeuft (Audit DB-6):
        // der committete Default ist SQLite; ohne korrekte .env faellt die App
        // sonst still auf eine lokale database.sqlite zurueck (Daten-Divergenz,
        // verborgene MySQL-only-Fehler). Konsolenlauf (Migrationen/Tests) bleibt
        // ausgenommen, damit CI/Artisan nicht blockiert wird.
        if ($this->app->environment('production')
            && ! $this->app->runningInConsole()
            && config('database.default') !== 'mysql') {
            throw new \RuntimeException(
                'Ungueltige DB-Konfiguration: In Produktion muss DB_CONNECTION=mysql gesetzt sein '
                .'(aktuell: '.config('database.default').').'
            );
        }

        // Aktivitaetserfassung: Arbeitssitzungen an Login/Logout koppeln.
        // Fehler in der Erfassung duerfen Login/Logout nie blockieren.
        Event::listen(Login::class, function (Login $event): void {
            // Sitzungs-Passworthash bei JEDER Anmeldung neu setzen.
            //
            // Seit AuthenticateSession in der Web-Gruppe liegt, wirft die
            // Middleware jeden raus, dessen Sitzung einen anderen Hash
            // traegt als das Konto - genau so sterben fremde Sitzungen nach
            // einem Passwortwechsel. auth()->logout() entfernt aber NUR die
            // Anmelde-Schluessel, nicht den gemerkten Hash. Wechselt jemand
            // innerhalb derselben Sitzung das Konto (Magic-Login aus der
            // Willkommens-Mail, waehrend noch ein anderes Konto angemeldet
            // ist), bliebe der Hash des VORIGEN Kontos stehen - und der
            // frisch Angemeldete floege beim naechsten Klick wieder raus,
            // ohne erkennbaren Grund. Deshalb hier, an EINER Stelle fuer
            // alle Anmeldewege.
            try {
                SessionPasswordHash::refresh(request());
            } catch (\Throwable $e) {
                report($e);
            }

            try {
                if ($event->user instanceof User) {
                    app(ActivityTracker::class)->handleLogin($event->user, request());
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        Event::listen(Logout::class, function (Logout $event): void {
            try {
                if ($event->user instanceof User) {
                    app(ActivityTracker::class)->handleLogout($event->user, request());
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // Anzeige-Zeitzone: ->lokal() rechnet einen gespeicherten Zeitpunkt
        // (UTC) in deutsche Ortszeit um. Bewusst ein EXPLIZITER Aufruf in der
        // View und keine Umrechnung im Model-Cast: ein Cast wuerde auch
        // Werte umstellen, die in WHERE-Bedingungen gehen - aus einem
        // sichtbaren Anzeigefehler wuerde ein stiller Abfragefehler.
        // Beide Makros delegieren an LocalTime::for(). Das ist Absicht: dort
        // wird die Instanz nachweislich NEU aufgebaut. Weder copy() noch
        // clone haben den Aufrufer in dieser Carbon-Fassung zuverlaessig
        // verschont - ->lokal() verschob den Zeitpunkt am Original mit. Ein
        // eigener Test hat das gefangen, zweimal. Jetzt gibt es genau EINE
        // Stelle, an der die Umrechnung passiert, und sie ist getestet.
        // BEWUSST eine gewoehnliche Closure, KEINE Arrow-Function: eine
        // Arrow-Function bindet $this fest an die Stelle, an der sie
        // geschrieben wurde (hier den ServiceProvider) und laesst sich
        // nicht umbinden. Carbon muss $this aber auf die Zeit-Instanz
        // setzen koennen, sonst zeigt das Makro auf das falsche Objekt.
        $lokal = function () {
            return LocalTime::for($this);
        };
        Carbon::macro('lokal', $lokal);
        CarbonImmutable::macro('lokal', $lokal);

        $this->protokolliereGeplanteAufgaben();
    }

    /**
     * Jeden Lauf einer geplanten Aufgabe festhalten (Systemzustand-Seite).
     *
     * Laravel merkt sich das nicht. Faellt der Cron-Eintrag weg oder steht
     * der Planer auf der falschen Zeitzone, passiert einfach nichts - ohne
     * Fehlermeldung. Erst dieses Protokoll macht den stillen Ausfall auf
     * /admin/systemzustand sichtbar.
     *
     * Das Protokollieren darf den Betrieb NIE stoeren: jeder Fehler hier
     * (z.B. Tabelle noch nicht migriert) wird geschluckt, die Aufgabe selbst
     * laeuft weiter.
     */
    private function protokolliereGeplanteAufgaben(): void
    {
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            $this->schreibeAufgabenlauf($event->task, ['last_started_at' => now()]);
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            $exitCode = $event->task->exitCode;
            // Closures haben keinen Exitcode - kein Fehler ist dann Erfolg.
            $erfolg = $exitCode === null || (int) $exitCode === 0;

            $werte = [
                'last_finished_at' => now(),
                'runtime_ms' => (int) round(((float) $event->runtime) * 1000),
                'exit_code' => $exitCode === null ? null : (int) $exitCode,
            ];
            // Erfolg loescht den alten Fehler - sonst stuende auf der Seite
            // dauerhaft eine laengst behobene Meldung.
            $werte += $erfolg
                ? ['last_success_at' => now(), 'last_error' => null]
                : ['last_failed_at' => now(), 'last_error' => 'Exitcode '.$exitCode];

            $this->schreibeAufgabenlauf($event->task, $werte, zaehleLauf: true, zaehleFehler: ! $erfolg);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            $this->schreibeAufgabenlauf($event->task, [
                'last_finished_at' => now(),
                'last_failed_at' => now(),
                // Nur die Kurzfassung - kein Stacktrace in der Datenbank.
                'last_error' => mb_substr($event->exception->getMessage(), 0, 500),
            ], zaehleLauf: true, zaehleFehler: true);
        });
    }

    /** Eine Aufgabenzeile fortschreiben. Fehler werden bewusst geschluckt. */
    private function schreibeAufgabenlauf(object $task, array $werte, bool $zaehleLauf = false, bool $zaehleFehler = false): void
    {
        try {
            if (! Schema::hasTable('scheduled_task_runs')) {
                return;
            }

            $key = ScheduledTaskRun::keyFor($task->getSummaryForDisplay());
            $lauf = ScheduledTaskRun::firstOrNew(['task_key' => $key]);
            $lauf->fill($werte);

            if ($zaehleLauf) {
                $lauf->run_count = (int) $lauf->run_count + 1;
            }
            if ($zaehleFehler) {
                $lauf->fail_count = (int) $lauf->fail_count + 1;
            }

            $lauf->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Benannte Rate-Limiter fuer die sicherheitsrelevanten Formulare
     * (Audit SEC-1 / SEC-2).
     *
     * Warum nicht einfach 'throttle:8,1' je Route? Weil ein reiner
     * IP-Eimer zwei Luecken hat:
     *  1. Ein Botnetz verteilt seine Versuche auf viele IPs. Gegen das
     *     Zuspammen EINER fremden Adresse hilft nur ein Eimer, der die
     *     Adresse mitzaehlt.
     *  2. Umgekehrt sitzen hinter einer Firmen-IP viele echte Nutzer.
     *
     * Deshalb zaehlt jeder Limiter hier ZWEI Eimer gleichzeitig: einen
     * je IP und einen je Ziel-Adresse. Der striktere gewinnt.
     *
     * Die IP kommt aus $request->ip() und damit aus der vertrauens-
     * wuerdigen Proxy-Kette (config/trustedproxy.php). Ein selbst
     * gesetzter X-Forwarded-For-Header aendert den Schluessel NICHT mehr,
     * solange die Anfrage nicht ueber einen eingetragenen Proxy kam -
     * genau das war der Befund von SEC-2.
     */
    private function registerRateLimiters(): void
    {
        // Registrierung und "Bestaetigung erneut senden".
        RateLimiter::for('registrierung', function ($request) {
            return [
                Limit::perMinutes(10, 5)
                    ->by('reg-ip:'.$request->ip()),
                // Je Adresse deutlich enger: eine echte Person registriert
                // sich einmal, nicht fuenfmal in zehn Minuten.
                Limit::perMinutes(60, 3)
                    ->by('reg-mail:'.self::mailKey($request->input('email'))),
            ];
        });

        // Anmeldung. Zusaetzlich zum feineren email+IP-Limiter in
        // LoginRequest, der die Fehlversuche EINES Kontos zaehlt.
        RateLimiter::for('anmeldung', function ($request) {
            return [
                Limit::perMinute(20)
                    ->by('login-ip:'.$request->ip()),
                Limit::perMinutes(15, 10)
                    ->by('login-mail:'.self::mailKey($request->input('email'))),
            ];
        });

        // Passwort vergessen. Der Adress-Eimer ist hier der wichtigere:
        // ohne ihn laesst sich ein fremdes Postfach mit Reset-Mails
        // fluten, solange der Angreifer nur genug IPs hat.
        RateLimiter::for('passwort-reset', function ($request) {
            return [
                Limit::perMinutes(10, 6)
                    ->by('reset-ip:'.$request->ip()),
                Limit::perMinutes(60, 4)
                    ->by('reset-kennung:'.self::mailKey(
                        $request->input('email') ?? $request->input('kennung')
                    )),
            ];
        });
    }

    /**
     * Normalisierter Schluesselanteil aus einer Kennung. Gehasht, damit
     * keine E-Mail-Adresse im Klartext in den Cache-Schluessel (und damit
     * in Redis-Keys oder Logs) geraet.
     */
    private static function mailKey(mixed $value): string
    {
        $value = Str::lower(trim((string) $value));

        return $value === '' ? 'leer' : sha1($value);
    }

}
