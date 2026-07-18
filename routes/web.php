<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\EmailMarketingController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ImportExportController;
use App\Http\Controllers\LexofficeController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TarifrechnerController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', \App\Http\Controllers\HomeController::class);

// Öffentliche Leistungsseiten (Definition + Kurzinfos + FAQ je Leistung).
// Das Anfrageformular erzeugt ein Ticket im System (source=website).
Route::get('/leistungen', [\App\Http\Controllers\ServicePageController::class, 'index'])->name('services.index');
Route::get('/leistungen/{slug}', [\App\Http\Controllers\ServicePageController::class, 'show'])->name('services.show');
Route::post('/leistungen/{slug}/anfrage', [\App\Http\Controllers\ServicePageController::class, 'submit'])
    ->middleware('throttle:8,1')
    ->name('services.submit');

// Öffentliche Rechts-/Infoseiten (Impressum, AGB, Datenschutzerklärung,
// Cookie-Richtlinie, Kontakt) – IMMER erreichbar, im Portal gehostet.
Route::get('/{page}', [\App\Http\Controllers\LegalPageController::class, 'show'])
    ->whereIn('page', ['impressum', 'agb', 'datenschutz', 'cookie-richtlinie', 'kontakt'])
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

// Abmeldung von Marketing-Mails (UWG §7 / DSGVO): öffentlich, ohne Login,
// Token pro Kunde. Ratenbegrenzt gegen Token-Raten.
Route::get('/abmelden/{token}', [\App\Http\Controllers\UnsubscribeController::class, 'handle'])
    ->middleware('throttle:30,1')
    ->name('unsubscribe');

// Magischer Erst-Login aus der Willkommens-Mail: signiert (90 Tage),
// nur Kunden-Accounts, ratenbegrenzt. Details im MagicLoginController.
Route::get('/magic-login/{user}', \App\Http\Controllers\Auth\MagicLoginController::class)
    ->middleware(['signed', 'throttle:10,1'])
    ->name('magic.login');

// Hilfe-/Kontaktformular: oeffentlich; der Button in der Willkommens-Mail
// bringt ein verschluesseltes Kunden-Token mit -> Formular ist vorbefuellt
// und die Anfrage wird automatisch als Ticket der Kundenakte zugeordnet.
Route::get('/hilfe', [\App\Http\Controllers\SupportFormController::class, 'show'])->name('support.form');
Route::post('/hilfe', [\App\Http\Controllers\SupportFormController::class, 'submit'])
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
    Route::post('/documents/scan', [\App\Http\Controllers\SmartDocumentUploadController::class, 'portalStore'])
        ->middleware('throttle:20,10')->name('documents.scan');
    Route::get('/documents/{id}/analyse-status', [\App\Http\Controllers\SmartDocumentUploadController::class, 'portalStatus'])
        ->middleware('throttle:120,1')->name('documents.analyse_status');
    Route::post('/document-requests/{id}/upload', [PortalController::class, 'documentRequestUpload'])->middleware('throttle:20,10')->name('document_requests.upload');
    Route::get('/notifications', [PortalController::class, 'notifications'])->name('notifications');
    Route::post('/notifications/{id}/read', [PortalController::class, 'notificationRead'])->name('notifications.read');
    // Direktnachrichten Berater <-> Kunde (Portal-Chat mit Anhaengen)
    Route::get('/nachrichten', [\App\Http\Controllers\PortalMessageController::class, 'index'])->name('messages');
    Route::post('/nachrichten', [\App\Http\Controllers\PortalMessageController::class, 'store'])->name('messages.store');
    Route::get('/nachrichten/anhang/{id}', [\App\Http\Controllers\PortalMessageController::class, 'downloadAttachment'])->name('messages.attachment');
    Route::get('/nachrichten/anhang/{id}/ansehen', [\App\Http\Controllers\PortalMessageController::class, 'viewAttachment'])->name('messages.attachment.view');
    Route::get('/banner/{id}/interesse', [PortalController::class, 'bannerInterest'])->name('banner.interest');
    Route::get('/banner/{id}/klick', [PortalController::class, 'bannerClick'])->name('banner.click');
    Route::post('/banner/{id}/schliessen', [PortalController::class, 'bannerDismiss'])->name('banner.dismiss');
    Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
    Route::get('/datenschutz', [PortalController::class, 'datenschutz'])->name('datenschutz');
    Route::get('/email-connection', [PortalController::class, 'emailConnection'])->name('email_connection');
    Route::post('/email-connection/grant', [PortalController::class, 'emailConnectionGrant'])->name('email_connection.grant');
    Route::post('/email-connection/revoke', [PortalController::class, 'emailConnectionRevoke'])->name('email_connection.revoke');

    // Self-Service (jede Aktion erzeugt nur einen Change Request)
    Route::get('/family', [\App\Http\Controllers\SelfServiceController::class, 'family'])->name('family');
    Route::post('/family', [\App\Http\Controllers\SelfServiceController::class, 'familyStore'])->name('family.store');
    Route::post('/family/{id}/change', [\App\Http\Controllers\SelfServiceController::class, 'familyChange'])->name('family.change');
    Route::post('/family/{id}/delete', [\App\Http\Controllers\SelfServiceController::class, 'familyDelete'])->name('family.delete');
    Route::get('/addresses', [\App\Http\Controllers\SelfServiceController::class, 'addresses'])->name('addresses');
    Route::post('/addresses', [\App\Http\Controllers\SelfServiceController::class, 'addressStore'])->name('addresses.store');
    Route::post('/addresses/{id}/change', [\App\Http\Controllers\SelfServiceController::class, 'addressChange'])->name('addresses.change');
    Route::get('/contacts', [\App\Http\Controllers\SelfServiceController::class, 'contacts'])->name('contacts');
    Route::post('/contacts', [\App\Http\Controllers\SelfServiceController::class, 'contactStore'])->name('contacts.store');
    Route::post('/contacts/{id}/change', [\App\Http\Controllers\SelfServiceController::class, 'contactChange'])->name('contacts.change');
    Route::get('/bank', [\App\Http\Controllers\SelfServiceController::class, 'bank'])->name('bank');
    Route::post('/bank', [\App\Http\Controllers\SelfServiceController::class, 'bankStore'])->name('bank.store');
    Route::post('/contracts/report', [\App\Http\Controllers\SelfServiceController::class, 'contractReport'])->name('contracts.report');
    Route::post('/contracts/{id}/change', [\App\Http\Controllers\SelfServiceController::class, 'contractChange'])->name('contracts.change');
    Route::get('/contracts/{id}', [PortalController::class, 'contractShow'])->name('contracts.show');
    // KFZ: Kunde meldet den aktuellen Kilometerstand (Historie bleibt erhalten)
    Route::post('/contracts/{id}/kilometerstand', [PortalController::class, 'contractMileageStore'])
        ->middleware('throttle:10,1')->name('contracts.mileage');
    Route::get('/change-requests', [\App\Http\Controllers\SelfServiceController::class, 'changeRequests'])->name('change_requests');
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
    Route::get('/', [\App\Http\Controllers\PartnerPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/kunden', [\App\Http\Controllers\PartnerPortalController::class, 'customers'])->name('customers');
    Route::get('/kunden/{id}', [\App\Http\Controllers\PartnerPortalController::class, 'customerShow'])->name('customer');
    Route::get('/provisionen', [\App\Http\Controllers\PartnerPortalController::class, 'commissions'])->name('commissions');
    Route::get('/profil', [\App\Http\Controllers\PartnerPortalController::class, 'profile'])->name('profile');
    Route::post('/profil', [\App\Http\Controllers\PartnerPortalController::class, 'profileUpdate'])->name('profile.update');
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
    // Dubletten-Pruefung: MUSS vor /customers/{id} stehen, sonst wuerde
    // "duplicates" als Kunden-ID interpretiert.
    Route::get('/customers/duplicates', [AdminController::class, 'duplicates'])->name('customers.duplicates');
    Route::put('/customers/notes/{id}/done', [AdminController::class, 'noteMarkDone'])->name('customer.note.done');
    Route::get('/customers/{id}', [AdminController::class, 'customerShow'])->name('customer');
    Route::get('/customers/{id}/edit', [AdminController::class, 'customerEdit'])->name('customer.edit');
    Route::put('/customers/{id}', [AdminController::class, 'customerUpdate'])->name('customer.update');
    // Kundenlöschung: NUR admin (employee/manager/support können nicht löschen)
    Route::delete('/customers/{id}', [AdminController::class, 'destroyCustomer'])->name('customers.delete')->middleware('role:admin');
    Route::post('/customers/bulk-delete', [AdminController::class, 'bulkDestroyCustomers'])->name('customers.bulk-delete')->middleware('role:admin');

    // Portal-Zugang-Controls in der Kundenakte (nur admin)
    Route::middleware('role:admin')->group(function () {
        Route::post('/customers/{id}/portal/invite', [\App\Http\Controllers\PortalAccessController::class, 'invite'])->name('customer.portal.invite');
        Route::post('/customers/{id}/portal/reset-link', [\App\Http\Controllers\PortalAccessController::class, 'sendResetLink'])->name('customer.portal.reset_link');
        Route::post('/customers/{id}/portal/reset', [\App\Http\Controllers\PortalAccessController::class, 'reset'])->name('customer.portal.reset');
        Route::post('/customers/{id}/portal/toggle', [\App\Http\Controllers\PortalAccessController::class, 'toggle'])->name('customer.portal.toggle');
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
    // Loeschen als DELETE (nicht GET): zustandsaendernde Aktion gehoert hinter
    // CSRF-Schutz; ein GET waere per Link-Prefetch/Scanner ungewollt ausloesbar.
    Route::delete('/customers/family/{id}', [AdminController::class, 'destroyFamily'])->name('customer.family.delete');
    Route::post('/customers/{id}/vehicles', [AdminController::class, 'storeVehicle'])->name('customer.vehicle.store');

    // Verträge
    Route::get('/contracts', [AdminController::class, 'contracts'])->name('contracts');
    Route::get('/contracts/new', [AdminController::class, 'contractNew'])->name('contract.new');
    Route::get('/contracts/create/{customerId}', [AdminController::class, 'contractCreate'])->name('contract.create');
    Route::get('/contracts/{id}/edit', [AdminController::class, 'contractEdit'])->name('contract.edit');
    Route::put('/contracts/{id}', [AdminController::class, 'contractUpdate'])->name('contract.update');
    Route::delete('/contracts/{id}', [AdminController::class, 'contractDestroy'])->name('contract.destroy');
    Route::post('/contracts/{customerId}', [AdminController::class, 'contractStore'])->name('contract.store');

    // Tickets (Workflow: Status, Zuweisung, Eigenschaften, Notizen, Antwort)
    Route::get('/tickets', [\App\Http\Controllers\TicketController::class, 'index'])->name('tickets');
    // Statistik VOR /tickets/{id} registrieren (sonst faengt {id} die URL ab)
    Route::get('/tickets/statistik', [\App\Http\Controllers\TicketController::class, 'stats'])->name('tickets.stats')->middleware('role:admin,manager');
    Route::get('/tickets/{id}', [\App\Http\Controllers\TicketController::class, 'show'])->name('ticket');
    Route::post('/tickets/{id}/reply', [\App\Http\Controllers\TicketController::class, 'reply'])->name('ticket.reply');
    Route::post('/tickets/{id}/status', [\App\Http\Controllers\TicketController::class, 'status'])->name('ticket.status');
    Route::post('/tickets/{id}/update', [\App\Http\Controllers\TicketController::class, 'updateMeta'])->name('ticket.update');
    Route::post('/tickets/{id}/note', [\App\Http\Controllers\TicketController::class, 'note'])->name('ticket.note');

    // Anfragen (Website + E-Mail info@): Leads mit Kontaktdaten sind sensibel -
    // wie der E-Mail-Posteingang nur admin/manager/support (das Nav-Item war
    // bereits so eingeschraenkt, die Routen bisher aber nicht).
    Route::middleware('role:admin,manager,support')->group(function () {
        Route::get('/inquiries', [AdminController::class, 'inquiries'])->name('inquiries');
        Route::get('/inquiries/create', [\App\Http\Controllers\WebsiteInquiryController::class, 'createManual'])->name('inquiries.create');
        Route::post('/inquiries', [\App\Http\Controllers\WebsiteInquiryController::class, 'storeManual'])->name('inquiries.store');
    });

    // Genehmigungen

    // Kundenänderungen (Self-Service Genehmigungsworkflow)
    Route::get('/change-requests', [\App\Http\Controllers\ChangeRequestReviewController::class, 'index'])->name('change_requests');
    Route::post('/change-requests/{id}/action', [\App\Http\Controllers\ChangeRequestReviewController::class, 'action'])->name('change_requests.action');
    Route::get('/change-requests/{id}/document', [\App\Http\Controllers\ChangeRequestReviewController::class, 'document'])->name('change_requests.document');
    // Smart Document Upload (CRM): Dokumenten-Eingang, Drag&Drop-Analyse, Zuordnung
    Route::get('/dokumenten-eingang', [\App\Http\Controllers\SmartDocumentUploadController::class, 'inbox'])->name('documents.inbox');
    Route::post('/documents/smart-upload', [\App\Http\Controllers\SmartDocumentUploadController::class, 'adminStore'])
        ->middleware('throttle:30,10')->name('documents.smart_upload');
    Route::get('/documents/customer-search', [\App\Http\Controllers\SmartDocumentUploadController::class, 'customerSearch'])
        ->middleware('throttle:60,1')->name('documents.customer_search');
    Route::get('/documents/{id}/analyse-status', [\App\Http\Controllers\SmartDocumentUploadController::class, 'adminStatus'])
        ->middleware('throttle:240,1')->name('documents.analyse_status');
    Route::post('/documents/{id}/assign', [\App\Http\Controllers\SmartDocumentUploadController::class, 'assign'])
        ->middleware('throttle:30,10')->name('documents.assign');
    Route::post('/documents/{id}/create-customer', [\App\Http\Controllers\SmartDocumentUploadController::class, 'createCustomer'])
        ->middleware('throttle:30,10')->name('documents.create_customer');
    Route::post('/documents/{id}/reanalyze', [\App\Http\Controllers\SmartDocumentUploadController::class, 'reanalyze'])
        ->middleware('throttle:30,10')->name('documents.reanalyze');
    Route::get('/documents/{id}/download', [AdminController::class, 'documentDownload'])->name('documents.download');
    Route::post('/documents/{id}/replace', [AdminController::class, 'documentReplace'])->name('documents.replace');
    Route::put('/documents/{id}', [AdminController::class, 'documentUpdate'])->name('documents.update');
    Route::delete('/documents/{id}', [AdminController::class, 'documentDestroy'])->name('documents.destroy');
    // Banner: Marketing-Verwaltung nur für Admin/Manager (Sicherheits-Fix:
    // war zuvor ohne Rollen-Einschränkung für alle Staff-Rollen erreichbar).
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/banners', [\App\Http\Controllers\BannerController::class, 'index'])->name('banners');
        Route::get('/banners/statistik', [\App\Http\Controllers\BannerController::class, 'stats'])->name('banners.stats');
        Route::post('/banners', [\App\Http\Controllers\BannerController::class, 'store'])->name('banners.store');
        Route::post('/banners/{banner}', [\App\Http\Controllers\BannerController::class, 'update'])->name('banners.update');
        Route::post('/banners/{banner}/toggle', [\App\Http\Controllers\BannerController::class, 'toggle'])->name('banners.toggle');
        Route::post('/banners/{banner}/move', [\App\Http\Controllers\BannerController::class, 'move'])->name('banners.move');
        Route::post('/banners/{banner}/reset-stats', [\App\Http\Controllers\BannerController::class, 'resetStats'])->name('banners.reset_stats');
        Route::post('/banners/{banner}/delete', [\App\Http\Controllers\BannerController::class, 'destroy'])->name('banners.delete');

        // Leistungsseiten (oeffentliche /leistungen/*): Inhalte pflegbar durch
        // admin/manager - Texte DE/AR, Kurzinfos, FAQ, Bild, Reihenfolge.
        Route::get('/service-pages', [\App\Http\Controllers\ServicePageAdminController::class, 'index'])->name('service_pages');
        Route::get('/service-pages/create', [\App\Http\Controllers\ServicePageAdminController::class, 'create'])->name('service_pages.create');
        Route::post('/service-pages', [\App\Http\Controllers\ServicePageAdminController::class, 'store'])->name('service_pages.store');
        Route::get('/service-pages/{servicePage}/edit', [\App\Http\Controllers\ServicePageAdminController::class, 'edit'])->name('service_pages.edit');
        Route::put('/service-pages/{servicePage}', [\App\Http\Controllers\ServicePageAdminController::class, 'update'])->name('service_pages.update');
        Route::post('/service-pages/{servicePage}/toggle', [\App\Http\Controllers\ServicePageAdminController::class, 'toggle'])->name('service_pages.toggle');
        Route::delete('/service-pages/{servicePage}', [\App\Http\Controllers\ServicePageAdminController::class, 'destroy'])->name('service_pages.delete');
    });

    // E-Mail-Posteingang: Zuordnungen bestätigen/zuweisen (Priorität 8).
    // DSGVO/Zugriff (Plan 3.3): Mailinhalte unbekannter Absender sind
    // sensibel - nur admin/manager/support, nicht jeder Mitarbeiter.
    Route::middleware('role:admin,manager,support')->group(function () {
        Route::get('/email-inbox', [\App\Http\Controllers\EmailInboxController::class, 'index'])->name('email_inbox');
        Route::get('/email-inbox/{id}', [\App\Http\Controllers\EmailInboxController::class, 'show'])->name('email_inbox.show');
        Route::get('/email-inbox/{id}/attachment/{index}', [\App\Http\Controllers\EmailInboxController::class, 'downloadAttachment'])->name('email_inbox.attachment');
        Route::post('/email-inbox/{id}/confirm', [\App\Http\Controllers\EmailInboxController::class, 'confirm'])->name('email_inbox.confirm');
        Route::post('/email-inbox/{id}/reject', [\App\Http\Controllers\EmailInboxController::class, 'reject'])->name('email_inbox.reject');
        Route::post('/email-inbox/{id}/assign', [\App\Http\Controllers\EmailInboxController::class, 'assign'])->name('email_inbox.assign');
        // KI-Vorschläge (Phase 3): Übernahme/Verwerfen ist die Freigabestufe
        Route::post('/email-inbox/ai/{decisionId}/accept', [\App\Http\Controllers\EmailInboxController::class, 'aiAccept'])->name('email_inbox.ai_accept');
        Route::post('/email-inbox/ai/{decisionId}/reject', [\App\Http\Controllers\EmailInboxController::class, 'aiReject'])->name('email_inbox.ai_reject');
    });

    // Dokumentenanfragen an Kunden (Priorität 7)
    Route::get('/document-requests', [\App\Http\Controllers\DocumentRequestController::class, 'index'])->name('document_requests');
    Route::post('/customers/{customerId}/document-requests', [\App\Http\Controllers\DocumentRequestController::class, 'store'])->name('document_requests.store');
    Route::post('/document-requests/{id}/approve', [\App\Http\Controllers\DocumentRequestController::class, 'approve'])->name('document_requests.approve');
    Route::post('/document-requests/{id}/reject', [\App\Http\Controllers\DocumentRequestController::class, 'reject'])->name('document_requests.reject');

    // Eigenständiger interner Mitarbeiter-Chat (Spec Teil 8)
    Route::get('/chat', [\App\Http\Controllers\InternalChatController::class, 'index'])->name('chat.index');
    Route::post('/chat', [\App\Http\Controllers\InternalChatController::class, 'store'])->name('chat.store');
    Route::get('/chat/{id}', [\App\Http\Controllers\InternalChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{id}/reply', [\App\Http\Controllers\InternalChatController::class, 'reply'])->name('chat.reply');

    // Direktnachrichten an Kunden (Portal-Chat), Vorlagen & E-Mail-Composer
    Route::post('/customers/{id}/messages', [\App\Http\Controllers\CustomerMessageController::class, 'store'])->name('customer.messages.store');
    Route::get('/messages/attachments/{id}/download', [\App\Http\Controllers\CustomerMessageController::class, 'downloadAttachment'])->name('messages.attachment');
    Route::get('/messages/attachments/{id}/view', [\App\Http\Controllers\CustomerMessageController::class, 'viewAttachment'])->name('messages.attachment.view');
    Route::get('/vorlagen', [\App\Http\Controllers\MessageTemplateController::class, 'index'])->name('templates');
    Route::get('/vorlagen/liste', [\App\Http\Controllers\MessageTemplateController::class, 'list'])->name('templates.list');
    Route::get('/vorlagen/{id}/render', [\App\Http\Controllers\MessageTemplateController::class, 'render'])->name('templates.render');
    // Vorlagen-Pflege nur Verwaltung; Nutzung (Liste/Rendern) alle Staff-Rollen
    Route::middleware('role:admin,manager')->group(function () {
        Route::post('/vorlagen', [\App\Http\Controllers\MessageTemplateController::class, 'store'])->name('templates.store');
        Route::post('/vorlagen/standard', [\App\Http\Controllers\MessageTemplateController::class, 'seedDefaults'])->name('templates.seed');
        Route::put('/vorlagen/{id}', [\App\Http\Controllers\MessageTemplateController::class, 'update'])->name('templates.update');
        Route::delete('/vorlagen/{id}', [\App\Http\Controllers\MessageTemplateController::class, 'destroy'])->name('templates.destroy');
    });
    Route::get('/email/verfassen', [\App\Http\Controllers\ComposeEmailController::class, 'create'])->name('email.compose');
    Route::post('/email/verfassen', [\App\Http\Controllers\ComposeEmailController::class, 'send'])->name('email.compose.send');
    // Smart-Composer: Kundensuche, Kundenkarte/Verlauf, Favoriten, KI-Entwurf
    Route::get('/email/kunden-suche', [\App\Http\Controllers\ComposeEmailController::class, 'customerSearch'])
        ->middleware('throttle:120,1')->name('email.customer_search');
    Route::get('/email/kunden-kontext/{id}', [\App\Http\Controllers\ComposeEmailController::class, 'customerContext'])
        ->middleware('throttle:120,1')->name('email.customer_context');
    Route::post('/email/favorit/{id}', [\App\Http\Controllers\ComposeEmailController::class, 'toggleFavorite'])->name('email.favorite');
    Route::post('/email/ki-entwurf', [\App\Http\Controllers\ComposeEmailController::class, 'aiDraft'])
        ->middleware('throttle:15,10')->name('email.ai_draft');

    // Interner Chat & Notizen (nur Mitarbeiter - keine Portal-Routen!)
    Route::post('/customers/{id}/internal-messages', [\App\Http\Controllers\InternalMessageController::class, 'store'])->name('internal.store');
    Route::delete('/internal-messages/{id}', [\App\Http\Controllers\InternalMessageController::class, 'destroy'])->name('internal.destroy');
    Route::get('/notifications', [\App\Http\Controllers\InternalNotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\InternalNotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\InternalNotificationController::class, 'markAllRead'])->name('notifications.read_all');

    // Aufgaben
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks');
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

    // Tarifrechner & Ankündigungen
    Route::get('/tarifrechner', [TarifrechnerController::class, 'index'])->name('tarifrechner')->middleware('role:admin,manager');
    Route::post('/tarifrechner', [TarifrechnerController::class, 'store'])->name('tarifrechner.store')->middleware('role:admin,manager');
    Route::delete('/tarifrechner/{id}', [TarifrechnerController::class, 'destroy'])->name('tarifrechner.destroy')->middleware('role:admin,manager');
    Route::get('/announcements', [TarifrechnerController::class, 'announcements'])->name('announcements');
    Route::post('/announcements', [TarifrechnerController::class, 'storeAnnouncement'])->name('announcements.store');
    Route::delete('/announcements/{id}', [TarifrechnerController::class, 'destroyAnnouncement'])->name('announcements.destroy');

    // Mitarbeiter
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees')->middleware('role:admin,manager');
    Route::get('/employees/customer-search', [EmployeeController::class, 'customerSearch'])->name('employees.customer-search');
    Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create')->middleware('role:admin,manager');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store')->middleware('role:admin,manager');
    Route::get('/employees/{id}/edit', [EmployeeController::class, 'edit'])->name('employees.edit')->middleware('role:admin,manager');
    Route::put('/employees/{id}', [EmployeeController::class, 'update'])->name('employees.update')->middleware('role:admin,manager');
    Route::delete('/employees/{id}', [EmployeeController::class, 'destroy'])->name('employees.destroy')->middleware('role:admin');
    Route::put('/employees/{id}/toggle-active', [EmployeeController::class, 'toggleActive'])->name('employees.toggle')->middleware('role:admin,manager');
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
        Route::get('/', [\App\Http\Controllers\ActivityReportController::class, 'index'])->name('index');
        Route::get('/export', [\App\Http\Controllers\ActivityReportController::class, 'export'])->name('export');
        Route::get('/einstellungen', [\App\Http\Controllers\ActivityReportController::class, 'settings'])->name('settings')->middleware('role:admin');
        Route::put('/einstellungen', [\App\Http\Controllers\ActivityReportController::class, 'settingsUpdate'])->name('settings.update')->middleware('role:admin');
        Route::get('/{id}/export', [\App\Http\Controllers\ActivityReportController::class, 'exportEmployee'])->whereNumber('id')->name('user_export');
        Route::get('/{id}', [\App\Http\Controllers\ActivityReportController::class, 'show'])->whereNumber('id')->name('show');
    });

    // Partner & Provisionen (Priorität 6)
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/partners', [\App\Http\Controllers\PartnerController::class, 'index'])->name('partners');
        Route::post('/partners', [\App\Http\Controllers\PartnerController::class, 'store'])->name('partners.store');
        Route::get('/partners/{id}', [\App\Http\Controllers\PartnerController::class, 'show'])->name('partners.show');
        Route::put('/partners/{id}', [\App\Http\Controllers\PartnerController::class, 'update'])->name('partners.update');
        Route::get('/commissions', [\App\Http\Controllers\CommissionController::class, 'index'])->name('commissions');
        Route::post('/commissions/{id}/book', [\App\Http\Controllers\CommissionController::class, 'book'])->name('commissions.book');
        Route::post('/commissions/{id}/reject', [\App\Http\Controllers\CommissionController::class, 'reject'])->name('commissions.reject');
    });

    // lexoffice
    Route::prefix('lexoffice')->name('lexoffice.')->middleware('role:admin,manager')->group(function () {
        Route::get('/contacts', [LexofficeController::class, 'contacts'])->name('contacts');
        Route::post('/contacts/import', [LexofficeController::class, 'importContact'])->name('import');
        Route::get('/invoices', [LexofficeController::class, 'invoices'])->name('invoices');
        Route::post('/invoices/{id}/send', [LexofficeController::class, 'sendInvoice'])->name('invoice.send');
        Route::get('/invoices/{id}/download', [LexofficeController::class, 'downloadInvoice'])->name('invoice.download');
    });

    // Einstellungen & Termine
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings')->middleware('role:admin');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update')->middleware('role:admin');

    // E-Mail-Postfächer (Priorität 1 der KI-Systemerweiterung) - nur admin, Zugangsdaten sind sensibel
    Route::prefix('email-accounts')->name('email_accounts.')->middleware('role:admin')->group(function () {
        Route::get('/', [\App\Http\Controllers\EmailAccountController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\EmailAccountController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\EmailAccountController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [\App\Http\Controllers\EmailAccountController::class, 'edit'])->name('edit');
        Route::put('/{id}', [\App\Http\Controllers\EmailAccountController::class, 'update'])->name('update');
        Route::delete('/{id}', [\App\Http\Controllers\EmailAccountController::class, 'destroy'])->name('destroy');
        Route::put('/{id}/toggle', [\App\Http\Controllers\EmailAccountController::class, 'toggleActive'])->name('toggle');
        Route::post('/{id}/test', [\App\Http\Controllers\EmailAccountController::class, 'testConnection'])->name('test');
        // OAuth-Anbindung Gmail/M365 (Phase 2)
        Route::get('/{id}/oauth', [\App\Http\Controllers\EmailAccountController::class, 'oauthRedirect'])->name('oauth');
        Route::get('/oauth/callback', [\App\Http\Controllers\EmailAccountController::class, 'oauthCallback'])->name('oauth_callback');
    });
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    Route::put('/appointments/{id}', [AppointmentController::class, 'update'])->name('appointments.update');
});

// استقبال استفسارات الموقع (WordPress) — محمي بـ Token
Route::post('/api/website-inquiry', [\App\Http\Controllers\WebsiteInquiryController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('api.inquiry.store');
