<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\CreditNotesController;
use App\Http\Controllers\Customer360Controller;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DataEntryController;
use App\Http\Controllers\ElnasrRecommendationsController;
use App\Http\Controllers\Entity360Controller;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InvoicesController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentsReceivedController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalesKpisController;
use App\Http\Controllers\SalesOperationsController;
use App\Http\Controllers\SalesOwnerController;
use App\Http\Controllers\SalesRepController;
use App\Http\Controllers\StaffUserController;
use App\Http\Controllers\WidgetController;
use App\Http\Controllers\ZohoConnectionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::post('/contact', [LandingController::class, 'contact'])->name('landing.contact');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'activity.log'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/notifications/{id}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::get('/imports/create', [ImportController::class, 'create'])->name('imports.create');
    Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
    Route::get('/imports/{import}/mapping', [ImportController::class, 'mapping'])->name('imports.mapping');
    Route::post('/imports/{import}/mapping', [ImportController::class, 'confirmMapping'])->name('imports.mapping.confirm');
    Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::delete('/imports/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/elnasr-recommendations', [ElnasrRecommendationsController::class, 'index'])->name('elnasr-recommendations.index');
    // Customer 360 rebuilt from the Lovable portal (approved 2026-10-19) is now THE customer page; the previous page
    // stays reachable at /legacy (nothing removed), and /360 keeps working for links shared during the preview.
    Route::get('/customers/{customer}', [Customer360Controller::class, 'show'])->name('customers.show');
    Route::get('/customers/{customer}/legacy', [CustomerController::class, 'show'])->name('customers.show-legacy');
    Route::get('/customers/{customer}/360', [Customer360Controller::class, 'show'])->name('customers.show360');
    Route::get('/customers/{customer}/360/ar-details', [Customer360Controller::class, 'arDetails'])->name('customers.show360.ar-details');
    Route::get('/customers/{customer}/360/insight-items', [Customer360Controller::class, 'insightItems'])->name('customers.show360.insight-items');
    Route::get('/customers/{customer}/360/expiry-items', [Customer360Controller::class, 'expiryItems'])->name('customers.show360.expiry-items');

    // Sales Reps / Sales Owners / Data Entry rebuilt from the Lovable portal (2026-10-19): their directory list +
    // Entity 360 page. The previous pages stay reachable under /legacy (nothing removed). The kind is taken from the
    // route-name prefix (Entity360Controller::kindOf), so keep these names.
    foreach (['sales-reps' => [SalesRepController::class, 'repName'], 'sales-owners' => [SalesOwnerController::class, 'ownerName'],
        'data-entry' => [DataEntryController::class, 'dataEntryName']] as $prefix => [$legacy, $param]) {
        Route::get("/{$prefix}", [Entity360Controller::class, 'index'])->name("{$prefix}.index");
        Route::get("/{$prefix}/legacy", [$legacy, 'index'])->name("{$prefix}.index-legacy");
        Route::get("/{$prefix}/{name}/legacy", fn (string $name) => app($legacy)->show($name))->name("{$prefix}.show-legacy");
        Route::get("/{$prefix}/{name}", [Entity360Controller::class, 'show'])->name("{$prefix}.show");
        Route::get("/{$prefix}/{name}/360/ar-details", [Entity360Controller::class, 'arDetails'])->name("{$prefix}.ar-details");
        Route::get("/{$prefix}/{name}/360/classification-customers", [Entity360Controller::class, 'classificationCustomers'])->name("{$prefix}.classification-customers");
        Route::get("/{$prefix}/{name}/360/action-customers", [Entity360Controller::class, 'actionCustomers'])->name("{$prefix}.action-customers");
        Route::get("/{$prefix}/{name}/360/action-detail", [Entity360Controller::class, 'actionDetail'])->name("{$prefix}.action-detail");
    }
        // Management: same directory + Entity 360 pages, scoped by the customer's MANAGEMENT field (no legacy page).
    Route::get('/management', [Entity360Controller::class, 'index'])->name('management.index');
    Route::get('/management/{name}', [Entity360Controller::class, 'show'])->name('management.show');
    Route::get('/management/{name}/360/ar-details', [Entity360Controller::class, 'arDetails'])->name('management.ar-details');
    Route::get('/management/{name}/360/classification-customers', [Entity360Controller::class, 'classificationCustomers'])->name('management.classification-customers');
    Route::get('/management/{name}/360/action-customers', [Entity360Controller::class, 'actionCustomers'])->name('management.action-customers');
    Route::get('/management/{name}/360/action-detail', [Entity360Controller::class, 'actionDetail'])->name('management.action-detail');
    Route::get('/sales-kpis', [SalesKpisController::class, 'index'])->name('sales-kpis.index');
    Route::get('/payments-received', [PaymentsReceivedController::class, 'index'])->name('payments-received.index');
    Route::get('/sales-operations', [SalesOperationsController::class, 'index'])->name('sales-operations.index');
    Route::get('/credit-notes', [CreditNotesController::class, 'index'])->name('credit-notes.index');
    Route::get('/credit-notes/{documentNumber}', [CreditNotesController::class, 'show'])->name('credit-notes.show');
    // Invoices: the Lovable copy is the main page; the earlier page stays reachable at /invoices/legacy.
    Route::get('/invoices', [\App\Http\Controllers\InvoiceListController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/data', [\App\Http\Controllers\InvoiceListController::class, 'data'])->name('invoices.data');
    Route::get('/invoices/facets', [\App\Http\Controllers\InvoiceListController::class, 'facets'])->name('invoices.facets');
    Route::get('/invoices/legacy', [InvoicesController::class, 'index'])->name('invoices.legacy');
    Route::get('/invoices/{invoice}', [\App\Http\Controllers\InvoiceListController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
    Route::get('/invoices/{invoice}/items', [\App\Http\Controllers\InvoiceListController::class, 'items'])->whereNumber('invoice')->name('invoices.items');
    Route::get('/sales-orders', [\App\Http\Controllers\SalesOrdersController::class, 'index'])->name('sales-orders.index');

    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports/warehouses', [ReportsController::class, 'warehouses'])->name('reports.warehouses');
    Route::get('/reports/sfda-price-checks', [ReportsController::class, 'sfdaPriceChecks'])->name('reports.sfda-price-checks');
    Route::get('/reports/sfda-changes', [ReportsController::class, 'sfdaChanges'])->name('reports.sfda-changes');
    Route::get('/reports/sfda-market-changes', [ReportsController::class, 'sfdaMarketChanges'])->name('reports.sfda-market-changes');
    Route::get('/reports/sfda-reg-no-issues', [ReportsController::class, 'sfdaRegNoIssues'])->name('reports.sfda-reg-no-issues');
    Route::get('/reports/{type}/documents/{documentNumber}', [ReportsController::class, 'document'])
        ->name('reports.document')
        ->where('type', 'sales|purchase-orders|invoices|returns');
    Route::get('/reports/{type}', [ReportsController::class, 'transactions'])
        ->name('reports.transactions')
        ->where('type', 'sales|purchase-orders|invoices|returns');

    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/chat/messages', [ChatController::class, 'send'])->name('chat.send');

    Route::get('/configuration', [ConfigurationController::class, 'index'])->name('configuration.index');

    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');

    Route::get('/users', [StaffUserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [StaffUserController::class, 'create'])->name('users.create');
    Route::post('/users', [StaffUserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [StaffUserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [StaffUserController::class, 'update'])->name('users.update');

    Route::post('/widgets', [WidgetController::class, 'store'])->name('widgets.store');
    Route::delete('/widgets/{widget}', [WidgetController::class, 'destroy'])->name('widgets.destroy');

    Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');

    Route::get('/zoho/connect', [ZohoConnectionController::class, 'connect'])->name('zoho.connect');
    Route::get('/zoho/callback', [ZohoConnectionController::class, 'callback'])->name('zoho.callback');
    Route::delete('/zoho/disconnect', [ZohoConnectionController::class, 'disconnect'])->name('zoho.disconnect');
    Route::post('/zoho/sync-now', [ZohoConnectionController::class, 'syncNow'])->name('zoho.sync-now');
});

require __DIR__.'/auth.php';
