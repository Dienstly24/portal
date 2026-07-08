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

Route::get('/', function () {
    $user = auth()->user();
    if ($user && in_array($user->role, ['admin', 'manager', 'employee'])) {
        return redirect()->route('admin.dashboard');
    }
    return redirect()->route('portal.dashboard');
});

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
    Route::post('/tickets', [PortalController::class, 'ticketsStore'])->name('tickets.store');
    Route::get('/tickets/{id}', [PortalController::class, 'ticketsShow'])->name('tickets.show');
    Route::post('/tickets/{id}/reply', [PortalController::class, 'ticketsReply'])->name('tickets.reply');
    Route::get('/attachments/{id}/download', [PortalController::class, 'downloadAttachment'])->name('attachment.download');
    Route::get('/documents', [PortalController::class, 'documents'])->name('documents');
    Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
    Route::post('/profile', [PortalController::class, 'profileUpdate'])->name('profile.update');
});

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Admin (admin.dienstly24.de/admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin,manager,employee'])->prefix('admin')->name('admin.')->group(function () {

    // Dashboard & Suche
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/search', [AdminController::class, 'globalSearch'])->name('search');

    // Kunden
    Route::get('/customers', [AdminController::class, 'customers'])->name('customers');
    Route::get('/customers/create', [AdminController::class, 'createCustomer'])->name('customers.create');
    Route::post('/customers', [AdminController::class, 'storeCustomer'])->name('customers.store');
    Route::post('/customers/bulk-assign', [AdminController::class, 'bulkAssign'])->name('customers.bulk-assign')->middleware('role:admin,manager');
    Route::put('/customers/notes/{id}/done', [AdminController::class, 'noteMarkDone'])->name('customer.note.done');
    Route::get('/customers/{id}', [AdminController::class, 'customerShow'])->name('customer');
    Route::get('/customers/{id}/edit', [AdminController::class, 'customerEdit'])->name('customer.edit');
    Route::put('/customers/{id}', [AdminController::class, 'customerUpdate'])->name('customer.update');
    Route::delete('/customers/{id}', [AdminController::class, 'destroyCustomer'])->name('customers.delete');
    Route::get('/customers/{id}/merge', [AdminController::class, 'mergeForm'])->name('customer.merge');
    Route::post('/customers/{id}/merge', [AdminController::class, 'mergeCustomers'])->name('customer.merge.do');
    Route::get('/attachments/{id}/download', [AdminController::class, 'downloadAttachment'])->name('attachment.download');
    Route::get('/customers/{id}/timeline', [AdminController::class, 'customerTimeline'])->name('customer.timeline');
    Route::post('/customers/{id}/notes', [AdminController::class, 'storeNote'])->name('customer.note.store');
    Route::post('/customers/{id}/documents', [AdminController::class, 'storeDocument'])->name('customer.document.store');
    Route::post('/customers/{id}/family', [AdminController::class, 'storeFamily'])->name('customer.family.store');
    Route::get('/customers/family/{id}/delete', [AdminController::class, 'destroyFamily'])->name('customer.family.delete');
    Route::post('/customers/{id}/vehicles', [AdminController::class, 'storeVehicle'])->name('customer.vehicle.store');

    // Verträge
    Route::get('/contracts', [AdminController::class, 'contracts'])->name('contracts');
    Route::get('/contracts/new', function () {
        $customers = \App\Models\Customer::with('user')->get();
        return view('admin.contract_new', compact('customers'));
    })->name('contract.new');
    Route::get('/contracts/create/{customerId}', [AdminController::class, 'contractCreate'])->name('contract.create');
    Route::post('/contracts/{customerId}', [AdminController::class, 'contractStore'])->name('contract.store');

    // Tickets
    Route::get('/tickets', [AdminController::class, 'tickets'])->name('tickets');
    Route::get('/tickets/{id}', [AdminController::class, 'ticketShow'])->name('ticket');
    Route::post('/tickets/{id}/reply', [AdminController::class, 'ticketReply'])->name('ticket.reply');

    // Anfragen (Website + E-Mail info@)
    Route::get('/inquiries', [AdminController::class, 'inquiries'])->name('inquiries');
    Route::get('/inquiries/create', [\App\Http\Controllers\WebsiteInquiryController::class, 'createManual'])->name('inquiries.create');
    Route::post('/inquiries', [\App\Http\Controllers\WebsiteInquiryController::class, 'storeManual'])->name('inquiries.store');

    // Genehmigungen
    Route::get('/approvals', [AdminController::class, 'approvals'])->name('approvals');
    Route::post('/approvals/{id}', [AdminController::class, 'approvalAction'])->name('approval.action');

    // Aufgaben
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('/tasks/{id}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    // Import / Export
    Route::get('/import-export', [ImportExportController::class, 'index'])->name('import_export')->middleware('role:admin,manager');
    Route::post('/import', [ImportExportController::class, 'import'])->name('import')->middleware('role:admin,manager');
    Route::get('/export', [ImportExportController::class, 'export'])->name('export')->middleware('role:admin,manager');
    Route::get('/import/template', [ImportExportController::class, 'template'])->name('import.template')->middleware('role:admin,manager');

    // E-Mail Marketing
    Route::get('/email-marketing', [EmailMarketingController::class, 'index'])->name('email_marketing');
    Route::post('/email-marketing/send', [EmailMarketingController::class, 'send'])->name('email_marketing.send');
    Route::post('/email-marketing/reminders', [EmailMarketingController::class, 'sendContractReminders'])->name('email_marketing.reminders');

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
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    Route::put('/appointments/{id}', [AppointmentController::class, 'update'])->name('appointments.update');
});

// استقبال استفسارات الموقع (WordPress) — محمي بـ Token
Route::post('/api/website-inquiry', [\App\Http\Controllers\WebsiteInquiryController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('api.inquiry.store');
