<?php

use App\Http\Controllers\ActivityReportController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminCustomerChatController;
use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\MagicLoginController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\BannerSocialController;
use App\Http\Controllers\ChangeNotificationController;
use App\Http\Controllers\ChangeRequestReviewController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\ComposeEmailController;
use App\Http\Controllers\ContractCommissionController;
use App\Http\Controllers\CustomerFamilyRelationController;
use App\Http\Controllers\CustomerMessageController;
use App\Http\Controllers\DocumentRequestController;
use App\Http\Controllers\EmailAccountController;
use App\Http\Controllers\EmailInboxController;
use App\Http\Controllers\EmailMarketingController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ErrorEventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImportExportController;
use App\Http\Controllers\InternalChatController;
use App\Http\Controllers\InternalMessageController;
use App\Http\Controllers\InternalNotificationController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\LexofficeController;
use App\Http\Controllers\MediaLibraryController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\MetaAdsController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PartnerPortalController;
use App\Http\Controllers\PortalAccessController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalMessageController;
use App\Http\Controllers\ProvisionController;
use App\Http\Controllers\ProvisionsmanagementController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SelfServiceController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\ServicePageAdminController;
use App\Http\Controllers\ServicePageController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SmartDocumentUploadController;
use App\Http\Controllers\SocialLinkController;
use App\Http\Controllers\SupportFormController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\TarifrechnerController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\VermittlerAbrechnungController;
use App\Http\Controllers\WebsiteAssistantController;
use App\Http\Controllers\WebsiteContactController;
use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\WebsiteInquiryController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// ===================== Marketing-Website (www.dienstly24.de) =====================
// Serverseitig gerenderte Website (Merge 30.07.2026). '/' zeigt auf den
// Website-Hosts die Startseite (HomeController), auf portal./admin. weiter
// das Login-Verhalten. /website = Vorschau der Startseite auf jedem Host.
Route::get('/website', [WebsiteController::class, 'home'])->name('website.home');
Route::post('/kontakt', [WebsiteController::class, 'submitContact'])
    ->middleware('throttle:8,1')
    ->name('website.contact.submit');
Route::get('/kontakt/danke', [WebsiteController::class, 'thanks'])->name('website.thanks');

// SEO: dynamische robots.txt (hostabhaengig) + Sitemap aus echten Inhalten.
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');

// Alt-URLs der statischen Website (Google-Index/Backlinks): .html -> sauber.
Route::get('/index.html', fn () => redirect('/', 301));
Route::get('/{page}.html', fn (string $page) => redirect('/'.$page, 301))
    ->whereIn('page', array_keys(WebsiteController::LEGAL_PAGES));

// Arabische Sprachversion: ECHTE URLs unter /ar (hreflang in den Views).
Route::prefix('ar')->name('ar.')->middleware('forceLocale:ar')->group(function () {
    Route::get('/', [WebsiteController::class, 'home'])->name('website.home');
    Route::get('/kontakt/danke', [WebsiteController::class, 'thanks'])->name('website.thanks');
    Route::get('/leistungen', [ServicePageController::class, 'index'])->name('services.index');
    Route::get('/leistungen/{slug}', [ServicePageController::class, 'show'])->name('services.show');
    Route::get('/{page}', [LegalPageController::class, 'show'])
        ->whereIn('page', array_merge(array_keys(WebsiteController::LEGAL_PAGES), ['kontakt']))
        ->name('legal');
});
// ================================================================================

// Öffentliche Leistungsseiten (Definition + Kurzinfos + FAQ je Leistung).
// Das Anfrageformular erzeugt ein Ticket im System (source=website).
Route::get('/leistungen', [ServicePageController::class, 'index'])->name('services.index');
Route::get('/leistungen/{slug}', [ServicePageController::class, 'show'])->name('services.show');
Route::post('/leistungen/{slug}/anfrage', [ServicePageController::class, 'submit'])
    ->middleware('throttle:8,1')
    ->name('services.submit');

// Öffentliche Rechts-/Infoseiten (Impressum, AGB, Datenschutzerklärung,
// Cookie-Richtlinie, Kontakt, Widerruf, Erstinformation, Bildnachweise) –
// IMMER erreichbar. Auf den Website-Hosts rendern sie die Website-Versionen.
Route::get('/{page}', [LegalPageController::class, 'show'])
    ->whereIn('page', array_merge(array_keys(WebsiteController::LEGAL_PAGES), ['kontakt']))
    ->name('legal');

// Sprachumschalter (de/ar): für Gäste per Session, für eingeloggte Kunden
// zusätzlich dauerhaft in der Kundenakte (preferred_lang).
Route::get('/sprache/{locale}', function (string $locale) {
    abort_unless(in_array($locale, ['de', 'ar'], true), 404);
    session(['locale' => $locale]);
    $user = auth()->user();
    if ($user && $user->role === 'customer' && $user->customer) {
        $user->customer->update(['preferred_lang' => $locale]);
    }
    return back();
})->name('locale.switch');

// Social-Media-Kurzlinks (/s/{code}): oeffentlich, zaehlt den Klick je
// Plattform (Banner-Social-Publishing) und leitet zum Klick-Ziel weiter.
Route::get('/s/{code}', [SocialLinkController::class, 'redirect'])
    ->middleware('throttle:120,1')->name('social.redirect');

// Abmeldung von Marketing-Mails (UWG §7 / DSGVO): öffentlich, ohne Login,
// Token pro Kunde. Ratenbegrenzt gegen Token-Raten.
Route::get('/abmelden/{token}', [UnsubscribeController::class, 'handle'])
    ->middleware('throttle:30,1')
    ->name('unsubscribe');
// Ein-Klick-Abmeldung (RFC 8058): der native "Abmelden"-Button von
// Gmail/Yahoo/Apple sendet einen POST an die List-Unsubscribe-URL. CSRF-
// Ausnahme in bootstrap/app.php (kein Session-Kontext bei diesem Server-POST).
Route::post('/abmelden/{token}', [UnsubscribeController::class, 'oneClick'])
    ->middleware('throttle:30,1')
    ->name('unsubscribe.oneclick');

// Magischer Erst-Login aus der Willkommens-Mail: signiert (90 Tage),
// nur Kunden-Accounts, ratenbegrenzt. Details im MagicLoginController.
Route::get('/magic-login/{user}', MagicLoginController::class)
    ->middleware(['signed', 'throttle:10,1'])
    ->name('magic.login');

// Hilfe-/Kontaktformular: oeffentlich; der Button in der Willkommens-Mail
// bringt ein verschluesseltes Kunden-Token mit -> Formular ist vorbefuellt
// und die Anfrage wird automatisch als Ticket der Kundenakte zugeordnet.
Route::get('/hilfe', [SupportFormController::class, 'show'])->name('support.form');
Route::post('/hilfe', [SupportFormController::class, 'submit'])
    ->middleware('throttle:8,1')
    ->name('support.submit');

/*
|--------------------------------------------------------------------------
| Kundenportal (portal.dienstly24.de)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:customer'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/contracts', [PortalController::class, 'contracts'])->name('contracts');
    Route::get('/tickets', [PortalController::class, 'tickets'])->name('tickets');
    Route::get('/tickets/create', [PortalController::class, 'ticketsCreate'])->name('tickets.create');
    Route::post('/tickets', [PortalController::class, 'ticketsStore'])->middleware('throttle:20,10')->name('tickets.store');
    Route::get('/tickets/{id}', [PortalController::class, 'ticketsShow'])->name('tickets.show');
    Route::post('/tickets/{id}/reply', [PortalController::class, 'ticketsReply'])->middleware('throttle:30,10')->name('tickets.reply');
    Route::post('/tickets/{id}/close', [PortalController::class, 'ticketsClose'])->name('tickets.close');
    Route::post('/tickets/{id}/rate', [PortalController::class, 'ticketsRate'])->name('tickets.rate');
    Route::get('/attachments/{id}/download', [PortalController::class, 'downloadAttachment'])->name('attachment.download');
    Route::get('/documents', [PortalController::class, 'documents'])->name('documents');
    Route::post('/documents', [PortalController::class, 'documentUpload'])->middleware('throttle:20,10')->name('documents.upload');
    // Smart Document Upload: Mehrseiten-Scanner (Fotos/Bilder/PDF) + KI-Analyse
    Route::post('/documents/scan', [SmartDocumentUploadController::class, 'portalStore'])
        ->middleware('throttle:20,10')->name('documents.scan');
    Route::get('/documents/{id}/analyse-status', [SmartDocumentUploadController::class, 'portalStatus'])
        ->middleware('throttle:120,1')->name('documents.analyse_status');
    Route::post('/document-requests/{id}/upload', [PortalController::class, 'documentRequestUpload'])->middleware('throttle:20,10')->name('document_requests.upload');
    Route::get('/notifications', [PortalController::class, 'notifications'])->name('notifications');
    Route::post('/notifications/{id}/read', [PortalController::class, 'notificationRead'])->name('notifications.read');
    // Direktnachrichten Berater <-> Kunde (Portal-Chat mit Anhaengen)
    Route::get('/nachrichten', [PortalMessageController::class, 'index'])->name('messages');
    Route::get('/nachrichten/feed', [PortalMessageController::class, 'feed'])
        ->middleware('throttle:120,1')->name('messages.feed');
    Route::post('/nachrichten', [PortalMessageController::class, 'store'])->name('messages.store');
    Route::get('/nachrichten/anhang/{id}', [PortalMessageController::class, 'downloadAttachment'])->name('messages.attachment');
    Route::get('/nachrichten/anhang/{id}/ansehen', [PortalMessageController::class, 'viewAttachment'])->name('messages.attachment.view');
    Route::get('/banner/{id}/interesse', [PortalController::class, 'bannerInterest'])->name('banner.interest');
    Route::get('/banner/{id}/klick', [PortalController::class, 'bannerClick'])->name('banner.click');
    Route::post('/banner/{id}/schliessen', [PortalController::class, 'bannerDismiss'])->name('banner.dismiss');
    Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
    Route::get('/datenschutz', [PortalController::class, 'datenschutz'])->name('datenschutz');
    Route::get('/email-connection', [PortalController::class, 'emailConnection'])->name('email_connection');
    Route::post('/email-connection/grant', [PortalController::class, 'emailConnectionGrant'])->name('email_connection.grant');
    Route::post('/email-connection/revoke', [PortalController::class, 'emailConnectionRevoke'])->name('email_connection.revoke');

    // Self-Service (jede Aktion erzeugt nur einen Change Request)
    Route::get('/family', [SelfServiceController::class, 'family'])->name('family');
    Route::post('/family', [SelfServiceController::class, 'familyStore'])->name('family.store');
    Route::post('/family/{id}/change', [SelfServiceController::class, 'familyChange'])->name('family.change');
    Route::post('/family/{id}/delete', [SelfServiceController::class, 'familyDelete'])->name('family.delete');
    Route::get('/addresses', [SelfServiceController::class, 'addresses'])->name('addresses');
    Route::post('/addresses', [SelfServiceController::class, 'addressStore'])->name('addresses.store');
    Route::post('/addresses/{id}/change', [SelfServiceController::class, 'addressChange'])->name('addresses.change');
    Route::get('/contacts', [SelfServiceController::class, 'contacts'])->name('contacts');
    Route::post('/contacts', [SelfServiceController::class, 'contactStore'])->name('contacts.store');
    Route::post('/contacts/{id}/change', [SelfServiceController::class, 'contactChange'])->name('contacts.change');
    Route::get('/bank', [SelfServiceController::class, 'bank'])->name('bank');
    Route::post('/bank', [SelfServiceController::class, 'bankStore'])->name('bank.store');
    Route::post('/contracts/report', [SelfServiceController::class, 'contractReport'])->name('contracts.report');
    Route::post('/contracts/{id}/change', [SelfServiceController::class, 'contractChange'])->name('contracts.change');
    Route::get('/contracts/{id}', [PortalController::class, 'contractShow'])->name('contracts.show');
    // KFZ: Kunde meldet den aktuellen Kilometerstand (Historie bleibt erhalten)
    Route::post('/contracts/{id}/kilometerstand', [PortalController::class, 'contractMileageStore'])
        ->middleware('throttle:10,1')->name('contracts.mileage');
    // Energie: Kunde meldet den Zaehlerstand (Wert und/oder Zaehlerfoto)
    Route::post('/contracts/{id}/zaehlerstand', [PortalController::class, 'contractMeterStore'])
        ->middleware('throttle:10,1')->name('contracts.meter');
    Route::get('/change-requests', [SelfServiceController::class, 'changeRequests'])->name('change_requests');
    Route::get('/documents/{id}/download', [PortalController::class, 'documentDownload'])->name('documents.download');
    Route::get('/documents/{id}/view', [PortalController::class, 'documentView'])->name('documents.view');
    Route::post('/profile', [PortalController::class, 'profileUpdate'])->name('profile.update');
    Route::post('/profile/password', [PortalController::class, 'passwordUpdate'])->name('profile.password');
});

/*
|--------------------------------------------------------------------------
| Partnerportal (Grundgerüst) – nur role:partner, strikt gescoped
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:partner'])->prefix('partner')->name('partner.')->group(function () {
    Route::get('/', [PartnerPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/kunden', [PartnerPortalController::class, 'customers'])->name('customers');
    Route::get('/kunden/{id}', [PartnerPortalController::class, 'customerShow'])->name('customer');
    Route::get('/provisionen', [PartnerPortalController::class, 'commissions'])->name('commissions');
    Route::get('/profil', [PartnerPortalController::class, 'profile'])->name('profile');
    Route::post('/profil', [PartnerPortalController::class, 'profileUpdate'])->name('profile.update');
});

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Admin (admin.dienstly24.de/admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin,manager,support,employee'])->prefix('admin')->name('admin.')->group(function () {

    // Dashboard & Suche
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/search', [AdminController::class, 'globalSearch'])->name('search');

    // Kunden
    Route::get('/customers', [AdminController::class, 'customers'])->name('customers');
    Route::get('/customers/create', [AdminController::class, 'createCustomer'])->name('customers.create');
    Route::post('/customers', [AdminController::class, 'storeCustomer'])->name('customers.store');
    Route::post('/customers/bulk-assign', [AdminController::class, 'bulkAssign'])->name('customers.bulk-assign')->middleware('role:admin,manager');
    // Betreuer direkt in der Kundenliste setzen (Popover je Zeile) - ohne den
    // Umweg ueber Checkbox + Massen-Zuweisung.
    Route::post('/customers/{id}/betreuer', [AdminController::class, 'setBetreuer'])
        ->name('customers.betreuer')->middleware('role:admin,manager');
    // Dubletten-Pruefung: MUSS vor /customers/{id} stehen, sonst wuerde
    // "duplicates" als Kunden-ID interpretiert.
    Route::get('/customers/duplicates', [AdminController::class, 'duplicates'])->name('customers.duplicates');
    // Sammel-Zusammenfuehrung: nur admin/manager (Massenaktion, entfernt
    // leere Duplikat-Akten - analog zur Loesch-Beschraenkung).
    Route::post('/customers/duplicates/merge', [AdminController::class, 'duplicatesMerge'])
        ->name('customers.duplicates.merge')->middleware('role:admin,manager');
    // Ein-Klick: alle "sicheren" Treffer (Score >= 40 %) automatisch vereinen.
    Route::post('/customers/duplicates/merge-all', [AdminController::class, 'duplicatesMergeAll'])
        ->name('customers.duplicates.merge_all')->middleware('role:admin,manager');
    // "Kein Duplikat" -> Paar als Beziehung markieren (Verwandte Kunden).
    Route::post('/customers/duplicates/dismiss', [AdminController::class, 'dismissDuplicate'])
        ->name('customers.duplicates.dismiss');
    Route::post('/customers/duplicates/dismiss-bulk', [AdminController::class, 'dismissBulk'])
        ->name('customers.duplicates.dismiss_bulk');
    // Verwandte Kunden (Beziehungen). GET vor /customers/{id} registrieren.
    Route::get('/customers/relationships', [AdminController::class, 'relationships'])->name('customers.relationships');
    Route::post('/customers/relationships/{id}/type', [AdminController::class, 'relationshipSetType'])->name('customers.relationships.type');
    Route::delete('/customers/relationships/{id}', [AdminController::class, 'relationshipDelete'])->name('customers.relationships.delete');
    Route::put('/customers/notes/{id}/done', [AdminController::class, 'noteMarkDone'])->name('customer.note.done');
    Route::get('/customers/{id}', [AdminController::class, 'customerShow'])->name('customer');
    Route::get('/customers/{id}/edit', [AdminController::class, 'customerEdit'])->name('customer.edit');
    Route::put('/customers/{id}', [AdminController::class, 'customerUpdate'])->name('customer.update');
    // Kundenlöschung: NUR admin (employee/manager/support können nicht löschen)
    Route::delete('/customers/{id}', [AdminController::class, 'destroyCustomer'])->name('customers.delete')->middleware('role:admin');
    Route::post('/customers/bulk-delete', [AdminController::class, 'bulkDestroyCustomers'])->name('customers.bulk-delete')->middleware('role:admin');

    // Portal-Zugang-Controls in der Kundenakte (nur admin)
    Route::middleware('role:admin')->group(function () {
        Route::post('/customers/{id}/portal/invite', [PortalAccessController::class, 'invite'])->name('customer.portal.invite');
        Route::post('/customers/{id}/portal/reset-link', [PortalAccessController::class, 'sendResetLink'])->name('customer.portal.reset_link');
        Route::post('/customers/{id}/portal/reset', [PortalAccessController::class, 'reset'])->name('customer.portal.reset');
        Route::post('/customers/{id}/portal/toggle', [PortalAccessController::class, 'toggle'])->name('customer.portal.toggle');
    });
    // Kundenzusammenfuehrung loescht den Duplikat-Datensatz + Login endgueltig
    // -> wie die anderen Loeschpfade NUR admin (DSGVO/Sicherheitsregel:
    // Mitarbeiter/Manager/Support duerfen nicht loeschen).
    Route::get('/customers/{id}/merge', [AdminController::class, 'mergeForm'])->name('customer.merge')->middleware('role:admin');
    Route::post('/customers/{id}/merge', [AdminController::class, 'mergeCustomers'])->name('customer.merge.do')->middleware('role:admin');
    Route::get('/attachments/{id}/download', [AdminController::class, 'downloadAttachment'])->name('attachment.download');
    Route::get('/customers/{id}/timeline', [AdminController::class, 'customerTimeline'])->name('customer.timeline');
    Route::post('/customers/{id}/notes', [AdminController::class, 'storeNote'])->name('customer.note.store');
    Route::post('/customers/{id}/documents', [AdminController::class, 'storeDocument'])->name('customer.document.store');
    Route::post('/customers/{id}/family', [AdminController::class, 'storeFamily'])->name('customer.family.store');
    // Familien- und Kundenbeziehungen: verknuepft ausschliesslich BESTEHENDE
    // Kundenakten (es entsteht nie ein neuer Kunde, es wird nie einer geloescht).
    Route::get('/customers/{id}/familie/kunden-suche', [CustomerFamilyRelationController::class, 'search'])->name('customer.family.search');
    Route::post('/customers/{id}/familie/verknuepfen', [CustomerFamilyRelationController::class, 'link'])->name('customer.family.link');
    Route::post('/customers/{id}/familie/{relation}/rolle', [CustomerFamilyRelationController::class, 'updateRole'])->name('customer.family.role');
    Route::delete('/customers/{id}/familie/{relation}', [CustomerFamilyRelationController::class, 'unlink'])->name('customer.family.unlink');
    // Loeschen als DELETE (nicht GET): zustandsaendernde Aktion gehoert hinter
    // CSRF-Schutz; ein GET waere per Link-Prefetch/Scanner ungewollt ausloesbar.
    Route::delete('/customers/family/{id}', [AdminController::class, 'destroyFamily'])->name('customer.family.delete');
    Route::post('/customers/{id}/vehicles', [AdminController::class, 'storeVehicle'])->name('customer.vehicle.store');

    // Sofort-Suche der Kundenauswahl (Vertragsformular, Zusammenfuehren).
    // Eigener Pfad statt /contracts/... - so kann keine Route-Reihenfolge
    // ihn als Vertrags-ID missdeuten.
    Route::get('/kunden-suche', [AdminController::class, 'customerSearch'])->name('customers.search');

    // "Kinder werden 15": Familienmitglieder mit bevorstehender
    // Verselbststaendigung. Bewusst NICHT unter /customers/... - dort wuerde
    // die Route-Reihenfolge sie als Kunden-ID missdeuten.
    Route::get('/familie/uebergaenge', [CustomerFamilyRelationController::class, 'transitions'])->name('family.transitions');
    Route::post('/familie/uebergaenge/{relation}/vorbereiten', [CustomerFamilyRelationController::class, 'prepareTransition'])->name('family.prepare_transition');

    // Verträge
    Route::get('/contracts', [AdminController::class, 'contracts'])->name('contracts');
    Route::get('/contracts/new', [AdminController::class, 'contractNew'])->name('contract.new');

    Route::get('/contracts/create/{customerId}', [AdminController::class, 'contractCreate'])->name('contract.create');
    Route::get('/contracts/{id}/edit', [AdminController::class, 'contractEdit'])->name('contract.edit');
    Route::put('/contracts/{id}', [AdminController::class, 'contractUpdate'])->name('contract.update');
    Route::delete('/contracts/{id}', [AdminController::class, 'contractDestroy'])->name('contract.destroy');
    Route::post('/contracts/{customerId}', [AdminController::class, 'contractStore'])->name('contract.store');
    // Energie: Zaehlerstand von Hand erfassen; Loeschen einer fehlerhaften
    // Ablesung bleibt admin/manager vorbehalten (Historie ist Datenbestand).
    Route::post('/contracts/{id}/zaehlerstand', [AdminController::class, 'contractMeterReadingStore'])
        ->name('contract.meter_reading.store');
    Route::delete('/contracts/{id}/zaehlerstand/{readingId}', [AdminController::class, 'contractMeterReadingDestroy'])
        ->middleware('role:admin,manager')->name('contract.meter_reading.destroy');

    // Tickets (Workflow: Status, Zuweisung, Eigenschaften, Notizen, Antwort)
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets');
    // Statistik VOR /tickets/{id} registrieren (sonst faengt {id} die URL ab)
    Route::get('/tickets/statistik', [TicketController::class, 'stats'])->name('tickets.stats')->middleware('role:admin,manager');
    // Bulk-Aktionen der Liste (Status/Zuweisung/Prioritaet/Papierkorb, max. 30)
    Route::post('/tickets/bulk', [TicketController::class, 'bulk'])->name('tickets.bulk');
    Route::get('/tickets/{id}', [TicketController::class, 'show'])->name('ticket');
    Route::post('/tickets/{id}/reply', [TicketController::class, 'reply'])->name('ticket.reply');
    Route::post('/tickets/{id}/status', [TicketController::class, 'status'])->name('ticket.status');
    Route::post('/tickets/{id}/update', [TicketController::class, 'updateMeta'])->name('ticket.update');
    Route::post('/tickets/{id}/note', [TicketController::class, 'note'])->name('ticket.note');
    // Loeschen = Papierkorb (Soft Delete, admin/manager); endgueltig NUR admin
    // und nur aus dem Papierkorb (zweistufiger Schutz). Mitarbeiter/Support
    // loeschen NIE - analog zur Kundenloeschung.
    Route::delete('/tickets/{id}', [TicketController::class, 'destroy'])->name('ticket.delete')->middleware('role:admin,manager');
    Route::post('/tickets/{id}/restore', [TicketController::class, 'restore'])->name('ticket.restore')->middleware('role:admin,manager');
    Route::delete('/tickets/{id}/force', [TicketController::class, 'forceDelete'])->name('ticket.forcedelete')->middleware('role:admin');

    // Anfragen (Website + E-Mail info@): Leads mit Kontaktdaten sind sensibel -
    // wie der E-Mail-Posteingang nur admin/manager/support (das Nav-Item war
    // bereits so eingeschraenkt, die Routen bisher aber nicht).
    Route::middleware('role:admin,manager,support')->group(function () {
        Route::get('/inquiries', [AdminController::class, 'inquiries'])->name('inquiries');
        Route::get('/inquiries/create', [WebsiteInquiryController::class, 'createManual'])->name('inquiries.create');
        Route::post('/inquiries', [WebsiteInquiryController::class, 'storeManual'])->name('inquiries.store');
    });

    // Genehmigungen

    // Kundenänderungen (Self-Service Genehmigungsworkflow)
    Route::get('/change-requests', [ChangeRequestReviewController::class, 'index'])->name('change_requests');
    Route::post('/change-requests/{id}/action', [ChangeRequestReviewController::class, 'action'])->name('change_requests.action');
    Route::get('/change-requests/{id}/document', [ChangeRequestReviewController::class, 'document'])->name('change_requests.document');
    // Nachweis (Ausweis/Meldebescheinigung/Kontonachweis) + automatische Pruefung
    Route::get('/change-requests/nachweis/{id}', [ChangeRequestReviewController::class, 'proof'])->name('change_requests.proof');
    Route::post('/change-requests/{id}/nachweis-pruefen', [ChangeRequestReviewController::class, 'recheck'])->name('change_requests.recheck');
    // Rueckfrage an den Kunden (fuehrt direkt in die Unterhaltung)
    Route::post('/change-requests/{id}/rueckfrage', [ChangeRequestReviewController::class, 'ask'])->name('change_requests.ask');
    // Mitteilungen an die Gesellschaften (nach der Freigabe vorbereitet)
    Route::get('/change-requests/{id}/mitteilungen', [ChangeNotificationController::class, 'index'])->name('change_requests.notifications');
    Route::post('/mitteilungen/{id}', [ChangeNotificationController::class, 'update'])->name('change_notifications.update');
    Route::post('/mitteilungen/{id}/senden', [ChangeNotificationController::class, 'send'])->name('change_notifications.send');
    Route::post('/mitteilungen/{id}/erledigt', [ChangeNotificationController::class, 'skip'])->name('change_notifications.skip');
    // Smart Document Upload (CRM): Dokumenten-Eingang, Drag&Drop-Analyse, Zuordnung
    Route::get('/dokumenten-eingang', [SmartDocumentUploadController::class, 'inbox'])->name('documents.inbox');
    // Rate-Limits bewusst grosszuegig (10-fach ggue. Ausgangswert): der
    // Mitarbeiter arbeitet den Dokumenten-Eingang zuegig im Stapel ab (Kunde
    // anlegen/zuordnen im Sekundentakt). Enge Limits loesten faelschlich
    // HTTP 429 ("Zu viele Anfragen") aus. Das Throttle bleibt als Missbrauchs-
    // Bremse erhalten, behindert aber den normalen Stapelbetrieb nicht mehr.
    Route::post('/documents/smart-upload', [SmartDocumentUploadController::class, 'adminStore'])
        ->middleware('throttle:300,10')->name('documents.smart_upload');
    Route::get('/documents/customer-search', [SmartDocumentUploadController::class, 'customerSearch'])
        ->middleware('throttle:600,1')->name('documents.customer_search');
    // Vorschlaege beim Oeffnen des Zuordnungs-Dialogs (keine eigene Eingabe
    // noetig) - gleiches grosszuegiges Limit wie die manuelle Kundensuche.
    Route::get('/documents/{id}/kunden-vorschlaege', [SmartDocumentUploadController::class, 'customerSuggestions'])
        ->middleware('throttle:600,1')->name('documents.customer_suggestions');
    Route::get('/documents/{id}/analyse-status', [SmartDocumentUploadController::class, 'adminStatus'])
        ->middleware('throttle:2400,1')->name('documents.analyse_status');
    Route::post('/documents/{id}/assign', [SmartDocumentUploadController::class, 'assign'])
        ->middleware('throttle:300,10')->name('documents.assign');
    Route::post('/documents/{id}/create-customer', [SmartDocumentUploadController::class, 'createCustomer'])
        ->middleware('throttle:300,10')->name('documents.create_customer');
    // Mehrere Personen auf EINER Aufnahme (z.B. die Gesundheitskarten einer
    // Familie) - je Person ein Kunde.
    Route::post('/documents/{id}/create-customers-from-persons', [SmartDocumentUploadController::class, 'createCustomersFromPersons'])
        ->middleware('throttle:300,10')->name('documents.create_customers_persons');
    // Mehrere Eingangs-Dokumente (Ausweis + Bankkarte + Fuehrerschein +
    // Protokoll) zu EINEM neuen Kunden zusammenfuehren.
    Route::post('/documents/create-customer-batch', [SmartDocumentUploadController::class, 'createCustomerFromDocuments'])
        ->middleware('throttle:300,10')->name('documents.create_customer_batch');
    // Vorschau fuer eine manuelle Mehrfachauswahl (beliebige Dokumente zu EINEM
    // Kunden buendeln) - dieselbe Zusammenfuehrung wie ein Vorgang.
    Route::post('/documents/batch-preview', [SmartDocumentUploadController::class, 'batchPreview'])
        ->middleware('throttle:1200,1')->name('documents.batch_preview');
    // Mehrere Eingangs-Dokumente auf einmal loeschen (Select-All / Bulk-Delete).
    // Hinweis: Das Throttle begrenzt nur die Request-Frequenz. Der Controller
    // loescht bewusst NUR unzugeordnete Eingangs-Dokumente (max. 100/Request,
    // in einer Transaktion) - zugeordnete Kundendokumente bleiben unberuehrt.
    Route::post('/documents/bulk-delete', [SmartDocumentUploadController::class, 'bulkDelete'])
        ->middleware('throttle:300,10')->name('documents.bulk_delete');
    Route::post('/documents/{id}/reanalyze', [SmartDocumentUploadController::class, 'reanalyze'])
        ->middleware('throttle:300,10')->name('documents.reanalyze');
    // Diagnose: der TATSAECHLICH erkannte Text. Er steht VOR der Route
    // '/documents/{id}', damit der feste Pfad nicht als Dokument-ID gilt.
    // Kostenlos, ohne KI, wird nicht gespeichert - Rechtepruefung im Controller
    // (nur admin/manager, der Rohtext ist das ganze Dokument).
    Route::get('/documents/{id}/erkannter-text', [SmartDocumentUploadController::class, 'ocrText'])
        ->middleware('throttle:120,10')->name('documents.ocr_text');
    Route::get('/documents/{id}/download', [AdminController::class, 'documentDownload'])->name('documents.download');
    Route::post('/documents/{id}/replace', [AdminController::class, 'documentReplace'])->name('documents.replace');
    // Direkter Dokument-Link (GET /admin/documents/{id}): eine eigene
    // Detailseite gibt es bewusst nicht. Solche Aufrufe entstehen z.B. ueber
    // den Browser-Verlauf (die Formular-Action des Bearbeiten-/Loeschen-
    // Dialogs landet dort als Adresse) oder alte Lesezeichen und liefen
    // bisher ins 404. Stattdessen zum richtigen Ort weiterleiten.
    Route::get('/documents/{id}', [AdminController::class, 'documentShow'])->name('documents.show');
    Route::put('/documents/{id}', [AdminController::class, 'documentUpdate'])->name('documents.update');
    Route::delete('/documents/{id}', [AdminController::class, 'documentDestroy'])->name('documents.destroy');
    // Banner: Marketing-Verwaltung nur für Admin/Manager (Sicherheits-Fix:
    // war zuvor ohne Rollen-Einschränkung für alle Staff-Rollen erreichbar).
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/banners', [BannerController::class, 'index'])->name('banners');
        Route::get('/banners/statistik', [BannerController::class, 'stats'])->name('banners.stats');
        Route::post('/banners', [BannerController::class, 'store'])->name('banners.store');
        Route::post('/banners/{banner}', [BannerController::class, 'update'])->name('banners.update');
        Route::post('/banners/{banner}/toggle', [BannerController::class, 'toggle'])->name('banners.toggle');
        Route::post('/banners/{banner}/move', [BannerController::class, 'move'])->name('banners.move');
        Route::post('/banners/{banner}/reset-stats', [BannerController::class, 'resetStats'])->name('banners.reset_stats');
        Route::post('/banners/{banner}/delete', [BannerController::class, 'destroy'])->name('banners.delete');

        // Social-Publishing je Banner: Bildformate fuer Facebook/Instagram/
        // TikTok, Beitragstexte DE/AR, Tracking-Kurzlinks, Veroeffentlichungs-
        // Protokoll und Download-Paket.
        Route::get('/banners/{banner}/social', [BannerSocialController::class, 'show'])->name('banners.social');
        Route::post('/banners/{banner}/social', [BannerSocialController::class, 'save'])->name('banners.social.save');
        Route::post('/banners/{banner}/social/{platform}/veroeffentlicht', [BannerSocialController::class, 'markPublished'])->name('banners.social.published');
        Route::post('/banners/{banner}/social/{platform}/api-post', [BannerSocialController::class, 'publishNow'])->name('banners.social.publish_now');
        Route::post('/banners/{banner}/social/zahlen', [BannerSocialController::class, 'refreshInsights'])->name('banners.social.refresh_insights');
        Route::get('/banners/{banner}/social/paket', [BannerSocialController::class, 'downloadZip'])->name('banners.social.zip');

        // Werbeanzeigen-Steuerung (Meta Marketing API): Uebersicht,
        // Start/Pause, Budget, Loeschen, "Banner bewerben" - alles aus dem
        // System, ohne Meta zu oeffnen.
        Route::get('/werbung', [MetaAdsController::class, 'index'])->name('werbung');
        Route::get('/werbung/neu/{banner}', [MetaAdsController::class, 'create'])->name('werbung.neu');
        Route::post('/werbung/neu/{banner}', [MetaAdsController::class, 'store'])->name('werbung.store');
        Route::post('/werbung/{campaignId}/status', [MetaAdsController::class, 'status'])->whereNumber('campaignId')->name('werbung.status');
        Route::post('/werbung/{campaignId}/budget', [MetaAdsController::class, 'budget'])->whereNumber('campaignId')->name('werbung.budget');
        Route::post('/werbung/{campaignId}/delete', [MetaAdsController::class, 'destroy'])->whereNumber('campaignId')->name('werbung.delete');
        // Schutzgrenze (max. Tagesbudget): bewusst NUR Admin - eine Rolle
        // ueber denen, die Anzeigen anlegen/steuern duerfen.
        Route::post('/werbung/schutzgrenze', [MetaAdsController::class, 'updateCap'])
            ->middleware('role:admin')->name('werbung.cap');

        // Leistungsseiten (oeffentliche /leistungen/*): Inhalte pflegbar durch
        // admin/manager - Texte DE/AR, Kurzinfos, FAQ, Bild, Reihenfolge.
        Route::get('/service-pages', [ServicePageAdminController::class, 'index'])->name('service_pages');
        Route::get('/service-pages/create', [ServicePageAdminController::class, 'create'])->name('service_pages.create');
        Route::post('/service-pages', [ServicePageAdminController::class, 'store'])->name('service_pages.store');
        Route::get('/service-pages/{servicePage}/edit', [ServicePageAdminController::class, 'edit'])->name('service_pages.edit');
        Route::put('/service-pages/{servicePage}', [ServicePageAdminController::class, 'update'])->name('service_pages.update');
        Route::post('/service-pages/{servicePage}/toggle', [ServicePageAdminController::class, 'toggle'])->name('service_pages.toggle');
        Route::delete('/service-pages/{servicePage}', [ServicePageAdminController::class, 'destroy'])->name('service_pages.delete');
    });

    // Medienverwaltung der Website (P1-1): Bilder hochladen, Slot waehlen,
    // Alt-Texte pflegen - sofort live, ohne FTP/Code. Hochladen/Ersetzen/
    // Bearbeiten fuer alle Staff-Rollen ("Redakteur"); Loeschen/
    // Wiederherstellen nur admin/manager.
    Route::get('/medien', [MediaLibraryController::class, 'index'])->name('media');
    Route::post('/medien', [MediaLibraryController::class, 'store'])->name('media.store');
    Route::put('/medien/{asset}', [MediaLibraryController::class, 'update'])->name('media.update');
    Route::post('/medien/{asset}/ersetzen', [MediaLibraryController::class, 'replace'])->name('media.replace');
    Route::delete('/medien/{asset}', [MediaLibraryController::class, 'destroy'])
        ->name('media.delete')->middleware('role:admin,manager');
    Route::post('/medien/{id}/wiederherstellen', [MediaLibraryController::class, 'restore'])
        ->name('media.restore')->middleware('role:admin,manager');

    // E-Mail-Posteingang: Zuordnungen bestätigen/zuweisen (Priorität 8).
    // DSGVO/Zugriff (Plan 3.3): Mailinhalte unbekannter Absender sind
    // sensibel - nur admin/manager/support, nicht jeder Mitarbeiter.
    Route::middleware('role:admin,manager,support')->group(function () {
        Route::get('/email-inbox', [EmailInboxController::class, 'index'])->name('email_inbox');
        Route::get('/email-inbox/{id}', [EmailInboxController::class, 'show'])->name('email_inbox.show');
        Route::get('/email-inbox/{id}/attachment/{index}', [EmailInboxController::class, 'downloadAttachment'])->name('email_inbox.attachment');
        Route::post('/email-inbox/{id}/confirm', [EmailInboxController::class, 'confirm'])->name('email_inbox.confirm');
        Route::post('/email-inbox/{id}/reject', [EmailInboxController::class, 'reject'])->name('email_inbox.reject');
        Route::post('/email-inbox/{id}/assign', [EmailInboxController::class, 'assign'])->name('email_inbox.assign');
        // KI-Vorschläge (Phase 3): Übernahme/Verwerfen ist die Freigabestufe
        Route::post('/email-inbox/ai/{decisionId}/accept', [EmailInboxController::class, 'aiAccept'])->name('email_inbox.ai_accept');
        Route::post('/email-inbox/ai/{decisionId}/reject', [EmailInboxController::class, 'aiReject'])->name('email_inbox.ai_reject');
    });

    // Dokumentenanfragen an Kunden (Priorität 7)
    Route::get('/document-requests', [DocumentRequestController::class, 'index'])->name('document_requests');
    Route::post('/customers/{customerId}/document-requests', [DocumentRequestController::class, 'store'])->name('document_requests.store');
    Route::post('/document-requests/{id}/approve', [DocumentRequestController::class, 'approve'])->name('document_requests.approve');
    Route::post('/document-requests/{id}/reject', [DocumentRequestController::class, 'reject'])->name('document_requests.reject');

    // Eigenständiger interner Mitarbeiter-Chat (Spec Teil 8)
    Route::get('/chat', [InternalChatController::class, 'index'])->name('chat.index');
    Route::post('/chat', [InternalChatController::class, 'store'])->name('chat.store');
    Route::get('/chat/{id}', [InternalChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{id}/reply', [InternalChatController::class, 'reply'])->name('chat.reply');

    // Direktnachrichten an Kunden (Portal-Chat), Vorlagen & E-Mail-Composer
    Route::post('/customers/{id}/messages', [CustomerMessageController::class, 'store'])->name('customer.messages.store');
    Route::get('/messages/attachments/{id}/download', [CustomerMessageController::class, 'downloadAttachment'])->name('messages.attachment');
    Route::get('/messages/attachments/{id}/view', [CustomerMessageController::class, 'viewAttachment'])->name('messages.attachment.view');
    // Zentraler Kunden-Chat: alle Portal-Unterhaltungen an einem Ort
    Route::get('/kundenchat', [AdminCustomerChatController::class, 'index'])->name('customer_chat');
    Route::get('/kundenchat/{id}/feed', [AdminCustomerChatController::class, 'feed'])
        ->middleware('throttle:120,1')->name('customer_chat.feed');
    // Vorgang direkt aus der Unterhaltung eroeffnen (Anfrage -> Ticket)
    Route::post('/kundenchat/{id}/ticket', [AdminCustomerChatController::class, 'createTicket'])
        ->name('customer_chat.ticket');

    /*
    | KI-Kundenassistent: menschliche Kontrolle (Spezifikation 15/27).
    | Alle Staff-Rollen duerfen uebernehmen bzw. die KI schalten - aber nur
    | fuer Kunden im eigenen Portfolio (Pruefung im Controller).
    */
    Route::post('/ki-assistent/{id}/uebernehmen', [AiAssistantController::class, 'takeOver'])
        ->name('ai_assistant.take_over');
    Route::post('/ki-assistent/{id}/deaktivieren', [AiAssistantController::class, 'deactivate'])
        ->name('ai_assistant.deactivate');
    Route::post('/ki-assistent/{id}/aktivieren', [AiAssistantController::class, 'reactivate'])
        ->name('ai_assistant.reactivate');

    /*
    | Verkaufsassistent (Betreiber-Auftrag 18.08.2026): Angebote hinterlegen,
    | Antwortvorschlag holen, nach einer Stoerung erneut versuchen. Alle
    | Staff-Rollen, aber nur fuer Kunden im eigenen Portfolio (Controller).
    */
    Route::post('/ki-assistent/{id}/angebot', [AiAssistantController::class, 'storeOffer'])
        ->name('ai_assistant.offer.store');
    Route::delete('/ki-assistent/{id}/angebot/{offer}', [AiAssistantController::class, 'destroyOffer'])
        ->name('ai_assistant.offer.destroy');
    Route::get('/ki-assistent/{id}/antwortvorschlag', [AiAssistantController::class, 'suggestReply'])
        ->name('ai_assistant.suggest');
    Route::post('/ki-assistent/{id}/erneut-versuchen', [AiAssistantController::class, 'retry'])
        ->name('ai_assistant.retry');

    // Interessenten aus dem Website-Assistenten.
    Route::get('/interessenten', [AiAssistantController::class, 'leads'])
        ->name('leads.index');

    /*
    | Wissensbasis des Assistenten: was hier steht, sagt die KI ALLEN
    | Kunden - deshalb nur Verwaltung (admin/manager).
    */
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/ki-wissensbasis', [AiAssistantController::class, 'knowledgeIndex'])
            ->name('ai_knowledge');
        Route::post('/ki-wissensbasis', [AiAssistantController::class, 'knowledgeStore'])
            ->name('ai_knowledge.store');
        Route::post('/ki-wissensbasis/import', [AiAssistantController::class, 'knowledgeImport'])
            ->name('ai_knowledge.import');
        // Wissensluecken: wonach der Assistent vergeblich gesucht hat.
        Route::get('/ki-wissensluecken', [AiAssistantController::class, 'knowledgeGaps'])
            ->name('ai_knowledge_gaps');
        Route::post('/ki-wissensluecken/{id}/antwort', [AiAssistantController::class, 'knowledgeGapAnswer'])
            ->name('ai_knowledge_gaps.answer');
        Route::post('/ki-wissensluecken/{id}/status', [AiAssistantController::class, 'knowledgeGapStatus'])
            ->name('ai_knowledge_gaps.status');
        Route::post('/ki-wissensbasis/sammelaktion', [AiAssistantController::class, 'knowledgeBulk'])
            ->name('ai_knowledge.bulk');
        Route::put('/ki-wissensbasis/{id}', [AiAssistantController::class, 'knowledgeUpdate'])
            ->name('ai_knowledge.update');
        Route::delete('/ki-wissensbasis/{id}', [AiAssistantController::class, 'knowledgeDestroy'])
            ->name('ai_knowledge.destroy');
    });
    Route::get('/vorlagen', [MessageTemplateController::class, 'index'])->name('templates');
    Route::get('/vorlagen/liste', [MessageTemplateController::class, 'list'])->name('templates.list');
    Route::get('/vorlagen/{id}/render', [MessageTemplateController::class, 'render'])->name('templates.render');
    // Vorlagen-Pflege nur Verwaltung; Nutzung (Liste/Rendern) alle Staff-Rollen
    Route::middleware('role:admin,manager')->group(function () {
        Route::post('/vorlagen', [MessageTemplateController::class, 'store'])->name('templates.store');
        Route::post('/vorlagen/standard', [MessageTemplateController::class, 'seedDefaults'])->name('templates.seed');
        Route::put('/vorlagen/{id}', [MessageTemplateController::class, 'update'])->name('templates.update');
        Route::delete('/vorlagen/{id}', [MessageTemplateController::class, 'destroy'])->name('templates.destroy');
    });
    Route::get('/email/verfassen', [ComposeEmailController::class, 'create'])->name('email.compose');
    Route::post('/email/verfassen', [ComposeEmailController::class, 'send'])->name('email.compose.send');
    // Smart-Composer: Kundensuche, Kundenkarte/Verlauf, Favoriten, KI-Entwurf
    Route::get('/email/kunden-suche', [ComposeEmailController::class, 'customerSearch'])
        ->middleware('throttle:120,1')->name('email.customer_search');
    Route::get('/email/kunden-kontext/{id}', [ComposeEmailController::class, 'customerContext'])
        ->middleware('throttle:120,1')->name('email.customer_context');
    Route::post('/email/favorit/{id}', [ComposeEmailController::class, 'toggleFavorite'])->name('email.favorite');
    Route::post('/email/ki-entwurf', [ComposeEmailController::class, 'aiDraft'])
        ->middleware('throttle:15,10')->name('email.ai_draft');

    // Interner Chat & Notizen (nur Mitarbeiter - keine Portal-Routen!)
    Route::post('/customers/{id}/internal-messages', [InternalMessageController::class, 'store'])->name('internal.store');
    Route::delete('/internal-messages/{id}', [InternalMessageController::class, 'destroy'])->name('internal.destroy');
    Route::get('/notifications', [InternalNotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/{id}/read', [InternalNotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [InternalNotificationController::class, 'markAllRead'])->name('notifications.read_all');

    // Aufgaben (inkl. Sofort-Kundensuche fuer das Aufgaben-Formular)
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks');
    Route::get('/tasks/kunden-suche', [TaskController::class, 'customerSearch'])
        ->middleware('throttle:120,1')->name('tasks.customer_search');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('/tasks/{id}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    // Import / Export
    Route::get('/import-export', [ImportExportController::class, 'index'])->name('import_export')->middleware('role:admin,manager');
    Route::post('/import', [ImportExportController::class, 'import'])->name('import')->middleware('role:admin,manager');
    Route::post('/import/confirm', [ImportExportController::class, 'confirmImport'])->name('import.confirm')->middleware('role:admin,manager');
    Route::get('/export', [ImportExportController::class, 'export'])->name('export')->middleware('role:admin,manager');
    Route::get('/import/template', [ImportExportController::class, 'template'])->name('import.template')->middleware('role:admin,manager');

    // E-Mail Marketing
    Route::get('/email-marketing', [EmailMarketingController::class, 'index'])->name('email_marketing');
    Route::post('/email-marketing/send', [EmailMarketingController::class, 'send'])->name('email_marketing.send');
    Route::post('/email-marketing/preview', [EmailMarketingController::class, 'preview'])->name('email_marketing.preview');
    Route::post('/email-marketing/test', [EmailMarketingController::class, 'testSend'])->name('email_marketing.test');
    Route::post('/email-marketing/{id}/dispatch', [EmailMarketingController::class, 'dispatchCampaign'])->name('email_marketing.dispatch');
    Route::delete('/email-marketing/{id}', [EmailMarketingController::class, 'destroyCampaign'])->name('email_marketing.destroy');
    Route::post('/email-marketing/reminders', [EmailMarketingController::class, 'sendContractReminders'])->name('email_marketing.reminders');
    Route::post('/contracts/{id}/switch-responded', [EmailMarketingController::class, 'markSwitchResponded'])->name('contracts.switch_responded');

    // Berichte
    Route::get('/reports', [ReportController::class, 'index'])->name('reports');
    // Neukunden-Bericht: Liste fuer alle Rollen (Daten per Portfolio-Scoping),
    // Werber/Sichtbarkeit setzen nur Verwaltung.
    Route::get('/reports/neukunden', [ReportController::class, 'newCustomers'])->name('reports.neukunden');
    Route::post('/reports/neukunden/{id}/werber', [ReportController::class, 'setAcquirer'])->name('reports.neukunden.werber')->middleware('role:admin,manager');
    Route::post('/reports/neukunden/{id}/sichtbarkeit', [ReportController::class, 'setVisibility'])->name('reports.neukunden.sichtbarkeit')->middleware('role:admin,manager');

    // Tarifrechner & Ankündigungen
    Route::get('/tarifrechner', [TarifrechnerController::class, 'index'])->name('tarifrechner')->middleware('role:admin,manager');
    Route::post('/tarifrechner', [TarifrechnerController::class, 'store'])->name('tarifrechner.store')->middleware('role:admin,manager');
    Route::delete('/tarifrechner/{id}', [TarifrechnerController::class, 'destroy'])->name('tarifrechner.destroy')->middleware('role:admin,manager');
    Route::post('/tarifrechner/reorder', [TarifrechnerController::class, 'reorder'])->name('tarifrechner.reorder')->middleware('role:admin,manager');
    Route::get('/announcements', [TarifrechnerController::class, 'announcements'])->name('announcements');
    Route::post('/announcements', [TarifrechnerController::class, 'storeAnnouncement'])->name('announcements.store');
    Route::delete('/announcements/{id}', [TarifrechnerController::class, 'destroyAnnouncement'])->name('announcements.destroy');

    // Mitarbeiter
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees')->middleware('role:admin,manager');
    Route::get('/employees/customer-search', [EmployeeController::class, 'customerSearch'])->name('employees.customer-search')->middleware('role:admin,manager');
    Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create')->middleware('role:admin,manager');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store')->middleware('role:admin,manager');
    Route::get('/employees/{id}/edit', [EmployeeController::class, 'edit'])->name('employees.edit')->middleware('role:admin,manager');
    // Mitarbeiter-Detailseite: Profil + durchsuchbare/paginierte Kundenliste
    // (wie der Kundenbereich) mit smarter Mehrfach-Zuweisung und Entfernen.
    Route::get('/employees/{id}', [EmployeeController::class, 'show'])->name('employees.show')->middleware('role:admin,manager');
    Route::post('/employees/{id}/assign-customers', [EmployeeController::class, 'assignCustomers'])->name('employees.assign_customers')->middleware('role:admin,manager');
    Route::delete('/employees/{id}/customers/{customerId}', [EmployeeController::class, 'unassignCustomer'])->name('employees.unassign_customer')->middleware('role:admin,manager');
    Route::put('/employees/{id}', [EmployeeController::class, 'update'])->name('employees.update')->middleware('role:admin,manager');
    Route::delete('/employees/{id}', [EmployeeController::class, 'destroy'])->name('employees.destroy')->middleware('role:admin');
    Route::put('/employees/{id}/toggle-active', [EmployeeController::class, 'toggleActive'])->name('employees.toggle')->middleware('role:admin,manager');
    // Einladung erneut senden: seit die Verwaltung keine Passwoerter mehr
    // vergibt, ist das der einzige Weg, einem Mitarbeiter den Zugang
    // wiederherzustellen (Link geht nur an seine hinterlegte Adresse).
    Route::post('/employees/{id}/einladung', [EmployeeController::class, 'resendInvitation'])
        ->name('employees.resend_invitation')->middleware('role:admin,manager');
    // Zwei-Faktor zuruecksetzen (verlorenes Telefon). Nur admin - es
    // entfernt die zweite Schicht eines fremden Kontos.
    Route::post('/employees/{id}/zwei-faktor-zuruecksetzen', [EmployeeController::class, 'resetTwoFactor'])
        ->name('employees.reset_two_factor')->middleware('role:admin');
    Route::get('/team', [EmployeeController::class, 'teamPage'])->name('team.verwaltung')->middleware('role:admin,manager');
    Route::post('/team/transfer', [EmployeeController::class, 'transferPortfolio'])->name('team.transfer')->middleware('role:admin,manager');
    Route::post('/team/substitution', [EmployeeController::class, 'storeSubstitution'])->name('team.substitution.store')->middleware('role:admin,manager');
    Route::delete('/team/substitution/{id}', [EmployeeController::class, 'destroySubstitution'])->name('team.substitution.destroy')->middleware('role:admin,manager');

    // Aktivitätslog
    Route::get('/activity-log', [EmployeeController::class, 'activityLog'])->name('activity_log')->middleware('role:admin,manager');

    // Aktivitaet & Arbeitszeiten: Berichte NUR fuer die Verwaltung
    // (admin/manager); Einstellungen (Punkte/Schwellwerte) nur admin.
    // Mitarbeiter haben keinerlei Einblick in Erfassung oder Berechnung.
    Route::prefix('aktivitaet')->name('activity.')->middleware('role:admin,manager')->group(function () {
        Route::get('/', [ActivityReportController::class, 'index'])->name('index');
        Route::get('/export', [ActivityReportController::class, 'export'])->name('export');
        Route::get('/einstellungen', [ActivityReportController::class, 'settings'])->name('settings')->middleware('role:admin');
        Route::put('/einstellungen', [ActivityReportController::class, 'settingsUpdate'])->name('settings.update')->middleware('role:admin');
        Route::get('/{id}/export', [ActivityReportController::class, 'exportEmployee'])->whereNumber('id')->name('user_export');
        Route::get('/{id}', [ActivityReportController::class, 'show'])->whereNumber('id')->name('show');
    });

    // Partner & Provisionen (Priorität 6)
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/partners', [PartnerController::class, 'index'])->name('partners');
        Route::post('/partners', [PartnerController::class, 'store'])->name('partners.store');
        Route::get('/partners/{id}', [PartnerController::class, 'show'])->name('partners.show');
        Route::put('/partners/{id}', [PartnerController::class, 'update'])->name('partners.update');
        Route::get('/commissions', [CommissionController::class, 'index'])->name('commissions');
        Route::post('/commissions/{id}/book', [CommissionController::class, 'book'])->name('commissions.book');
        Route::post('/commissions/{id}/reject', [CommissionController::class, 'reject'])->name('commissions.reject');
        // Vermittler-Provisionen (Ausgang an Mitarbeiter/Partner) -
        // Provisions-Management: NUR admin/manager, Mitarbeiter/Partner haben
        // keinerlei Zugriff auf Betraege, Saetze, Berichte oder Statistiken.
        Route::get('/provisionen', [ProvisionController::class, 'index'])->name('provisions');
        Route::post('/provisionen', [ProvisionController::class, 'store'])->name('provisions.store');
        Route::get('/provisionen/saetze', [ProvisionController::class, 'rates'])->name('provisions.rates');
        Route::post('/provisionen/saetze', [ProvisionController::class, 'ratesSave'])->name('provisions.rates.save');
        Route::get('/provisionen/bericht', [ProvisionController::class, 'report'])->name('provisions.report');
        Route::get('/provisionen/bericht/export', [ProvisionController::class, 'reportExport'])->name('provisions.report.export');
        Route::get('/provisionen/dashboard', [ProvisionController::class, 'dashboard'])->name('provisions.dashboard');
        Route::get('/provisionen/{id}', [ProvisionController::class, 'show'])->whereUuid('id')->name('provisions.show');
        Route::post('/provisionen/{id}/status', [ProvisionController::class, 'updateStatus'])->name('provisions.status');
        Route::post('/provisionen/{id}/betrag', [ProvisionController::class, 'adjustAmount'])->name('provisions.amount');

        // Vermittler-Abrechnung: CSV des Vermittlers einlesen, Vertraege
        // zuordnen, Abweichungen pruefen (Betreiber-Auftrag 20.08.2026).
        // Gleiche Zugriffsregel wie das uebrige Provisions-Management -
        // hier stehen Provisionsbetraege.
        Route::prefix('vermittler-abrechnung')->name('vermittler.')->group(function () {
            Route::get('/', [VermittlerAbrechnungController::class, 'index'])->name('index');
            Route::post('/import', [VermittlerAbrechnungController::class, 'import'])->name('import');
            // Vorgangsliste (offene Vorgaenge) als Screenshot/PDF/CSV: stellt
            // die Bruecke Referenz-Nr. -> Vermittler-ID her, bevor abgerechnet
            // wird.
            Route::post('/vorgangsliste', [VermittlerAbrechnungController::class, 'importVorgangsliste'])->name('vorgangsliste');
            // Dieselbe Verarbeitung direkt aus dem Dokumenten-Eingang heraus:
            // dort liegt die Datei bereits, dort arbeiten die Mitarbeiter.
            Route::post('/dokument/{id}/einlesen', [VermittlerAbrechnungController::class, 'importFromDocument'])->whereUuid('id')->name('from_document');
            Route::get('/pruefung', [VermittlerAbrechnungController::class, 'review'])->name('review');
            Route::get('/bericht', [VermittlerAbrechnungController::class, 'report'])->name('report');
            Route::get('/vertrag-suche', [VermittlerAbrechnungController::class, 'contractSearch'])->name('contract_search');
            Route::post('/datensatz/{id}/zuordnen', [VermittlerAbrechnungController::class, 'link'])->whereUuid('id')->name('link');
            Route::get('/{id}', [VermittlerAbrechnungController::class, 'show'])->whereUuid('id')->name('show');
        });
    });

    // PROVISIONSMANAGEMENT (Betreiber-Auftrag 02.09.2026): der zentrale
    // Bereich fuer alle Provisionen - Dashboard, Importe, Abrechnungen,
    // Buchungen, Vertraege, fehlende Provisionen, Auswertungen, Pools.
    //
    // ZUGRIFF wie im uebrigen Provisionsteil ueber das RECHT
    // `provisionen-verwalten`. Der Menuepunkt allein ist KEINE Berechtigung:
    // dieselbe Pruefung steht zusaetzlich im Controller, damit ein direkt
    // aufgerufener Pfad genauso abgewiesen wird wie ein Klick.
    Route::prefix('provisionsmanagement')->name('provisionsmanagement.')
        ->middleware('can:provisionen-verwalten')->group(function () {
        $p = ProvisionsmanagementController::class;

        Route::get('/', [$p, 'dashboard'])->name('dashboard');
        Route::get('/importe', [$p, 'imports'])->name('imports');
        Route::get('/abrechnungen', [$p, 'statements'])->name('statements');
        Route::get('/vertraege', [$p, 'contracts'])->name('contracts');
        Route::get('/fehlende-provisionen', [$p, 'missingList'])->name('missing');
        Route::get('/unklare-zuordnungen', [$p, 'unclear'])->name('unclear');
        Route::get('/auswertungen', [$p, 'analytics'])->name('analytics');
        Route::get('/auswertungen/export.csv', [$p, 'export'])->name('export');
        Route::get('/einstellungen', [$p, 'settings'])->name('settings');
        Route::post('/einstellungen/pool', [$p, 'poolStore'])->name('pool_store');
        Route::put('/einstellungen/pool/{id}', [$p, 'poolUpdate'])->whereUuid('id')->name('pool_update');
        Route::post('/neu-berechnen', [$p, 'recalculate'])->name('recalculate');
        Route::get('/kunde/{id}', [$p, 'customer'])->whereUuid('id')->name('customer');

        // Die Vertragsroute steht ZULETZT - sonst verschluckt sie die festen
        // Pfade oben (dieselbe Lehre wie bei der Kundensuche).
        Route::get('/vertrag/{id}', [$p, 'contract'])->whereUuid('id')->name('contract');
        Route::post('/vertrag/{id}/nachverfolgung', [$p, 'followup'])->whereUuid('id')->name('followup');
    });

    // Interne Provisionen: Provisionsdaten aus Fremdsystemen (Maklerpool,
    // Vergleichsportal, Energieportal) einlesen und an den Vertrag binden
    // (Betreiber-Auftrag 26.08.2026).
    //
    // ZUGRIFF ueber das RECHT `provisionen-verwalten`, nicht ueber eine
    // Rolle: Provisionsdaten sind intern und vertraulich, und ein Recht wird
    // einzeln vergeben, waehrend eine Rolle mit der Zeit um Aufgaben
    // waechst. Die Pruefung steht zusaetzlich im Controller.
    Route::prefix('interne-provisionen')->name('commissions_internal.')
        ->middleware('can:provisionen-verwalten')->group(function () {
        $c = ContractCommissionController::class;

        Route::get('/', [$c, 'index'])->name('index');
        Route::get('/export', [$c, 'export'])->name('export');
        Route::get('/protokoll', [$c, 'auditLog'])->name('audit');
        Route::get('/rechnungsabgleich', [$c, 'invoiceMatch'])->name('invoice');
        Route::get('/vertrag-suche', [$c, 'contractSearch'])->name('contract_search');

        // Import in fuenf Schritten: Upload -> Erkennung -> Zuordnung ->
        // Pruefung -> Bestaetigung. Erst der letzte Schritt schreibt.
        Route::get('/import', [$c, 'importForm'])->name('import');
        Route::post('/import', [$c, 'upload'])->name('upload');
        Route::get('/import/{id}', [$c, 'preview'])->whereUuid('id')->name('preview');
        Route::post('/import/{id}/zuordnung', [$c, 'remap'])->whereUuid('id')->name('remap');
        Route::post('/import/{id}/bestaetigen', [$c, 'confirm'])->whereUuid('id')->name('confirm');
        Route::post('/import/{id}/verwerfen', [$c, 'discard'])->whereUuid('id')->name('discard');
        Route::get('/import/{id}/fehler.csv', [$c, 'errorExport'])->whereUuid('id')->name('errors');

        // Die Detailroute steht ZULETZT - sonst verschluckt sie die festen
        // Pfade oben (gleiche Lehre wie bei der Kundensuche).
        Route::get('/{id}', [$c, 'show'])->whereUuid('id')->name('show');
        Route::put('/{id}', [$c, 'update'])->whereUuid('id')->name('update');
        Route::post('/{id}/status', [$c, 'updateStatus'])->whereUuid('id')->name('status');
        Route::post('/{id}/zahlung', [$c, 'pay'])->whereUuid('id')->name('pay');
        Route::post('/{id}/zuordnen', [$c, 'link'])->whereUuid('id')->name('link');
        Route::post('/{id}/zuordnung-loesen', [$c, 'unlink'])->whereUuid('id')->name('unlink');
        Route::post('/{id}/rechnung', [$c, 'linkInvoice'])->whereUuid('id')->name('invoice_link');
        Route::post('/{id}/rechnung-loesen', [$c, 'unlinkInvoice'])->whereUuid('id')->name('invoice_unlink');
    });

    // lexoffice
    Route::prefix('lexoffice')->name('lexoffice.')->middleware('role:admin,manager')->group(function () {
        Route::get('/contacts', [LexofficeController::class, 'contacts'])->name('contacts');
        Route::post('/contacts/import', [LexofficeController::class, 'importContact'])->name('import');
        Route::get('/invoices', [LexofficeController::class, 'invoices'])->name('invoices');
        Route::post('/invoices/{id}/send', [LexofficeController::class, 'sendInvoice'])->name('invoice.send');
        Route::get('/invoices/{id}/download', [LexofficeController::class, 'downloadInvoice'])->name('invoice.download');
    });

    // Systemzustand: laeuft im Hintergrund noch alles? (nur lesend)
    Route::middleware('role:admin,manager')->group(function () {
        // Fehlerliste: was geht im Betrieb wirklich kaputt.
        Route::get('/fehler', [ErrorEventController::class, 'index'])
            ->name('errors');
        Route::post('/fehler/{id}/erledigt', [ErrorEventController::class, 'resolve'])
            ->name('errors.resolve');
        Route::post('/fehler/{id}/wieder-oeffnen', [ErrorEventController::class, 'reopen'])
            ->name('errors.reopen');
        Route::get('/systemzustand', [SystemHealthController::class, 'index'])
            ->name('system_health');
        Route::get('/systemzustand.json', [SystemHealthController::class, 'json'])
            ->name('system_health.json');
    });

    // Einstellungen & Termine
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings')->middleware('role:admin');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update')->middleware('role:admin');

    // E-Mail-Postfächer (Priorität 1 der KI-Systemerweiterung) - nur admin, Zugangsdaten sind sensibel
    Route::prefix('email-accounts')->name('email_accounts.')->middleware('role:admin')->group(function () {
        Route::get('/', [EmailAccountController::class, 'index'])->name('index');
        Route::get('/create', [EmailAccountController::class, 'create'])->name('create');
        Route::post('/', [EmailAccountController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [EmailAccountController::class, 'edit'])->name('edit');
        Route::put('/{id}', [EmailAccountController::class, 'update'])->name('update');
        Route::delete('/{id}', [EmailAccountController::class, 'destroy'])->name('destroy');
        Route::put('/{id}/toggle', [EmailAccountController::class, 'toggleActive'])->name('toggle');
        Route::post('/{id}/test', [EmailAccountController::class, 'testConnection'])->name('test');
        // OAuth-Anbindung Gmail/M365 (Phase 2)
        Route::get('/{id}/oauth', [EmailAccountController::class, 'oauthRedirect'])->name('oauth');
        Route::get('/oauth/callback', [EmailAccountController::class, 'oauthCallback'])->name('oauth_callback');
    });
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    Route::put('/appointments/{id}', [AppointmentController::class, 'update'])->name('appointments.update');
});

// استقبال استفسارات الموقع (WordPress) — محمي بـ Token
Route::post('/api/website-inquiry', [WebsiteInquiryController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('api.inquiry.store');

// Kontaktformular der statischen Website (dienstly24.de). Schutzschichten
// (JS-Token, Mindest-Ausfuellzeit, Einmal-Token, Honeypot, SpamFilter)
// siehe WebsiteContactController.
Route::get('/api/website-contact/token', [WebsiteContactController::class, 'token'])
    ->middleware('throttle:30,1')
    ->name('api.contact.token');
Route::post('/api/website-contact', [WebsiteContactController::class, 'submit'])
    ->middleware('throttle:10,1')
    ->name('api.contact.store');

// Website-Assistent fuer nicht angemeldete Besucher (KI-Verkaufsassistent,
// Spezifikation Abschnitt 19). Oeffentlich, deshalb gedrosselt; die
// Zuordnung laeuft ueber die Server-Sitzung, nie ueber den Request.
Route::get('/api/website-assistent/status', [WebsiteAssistantController::class, 'status'])
    ->middleware('throttle:60,1')
    ->name('api.assistant.status');
Route::get('/api/website-assistent/verlauf', [WebsiteAssistantController::class, 'history'])
    ->middleware('throttle:60,1')
    ->name('api.assistant.history');
Route::post('/api/website-assistent', [WebsiteAssistantController::class, 'send'])
    ->middleware('throttle:20,1')
    ->name('api.assistant.send');
