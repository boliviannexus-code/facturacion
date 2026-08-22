<?php

use App\Http\Controllers\Web\AdminDataTableController;
use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\Billing\CafcContingencyController;
use App\Http\Controllers\Web\Billing\CafcRangeController;
use App\Http\Controllers\Web\Billing\ContingencyDashboardController;
use App\Http\Controllers\Web\Billing\FiscalArtifactController;
use App\Http\Controllers\Web\Billing\InvoiceController;
use App\Http\Controllers\Web\Billing\InvoiceIssueController;
use App\Http\Controllers\Web\Billing\InvoicePrintSettingController;
use App\Http\Controllers\Web\Billing\InvoiceTestBatchController;
use App\Http\Controllers\Web\Billing\ManualCafcInvoiceController;
use App\Http\Controllers\Web\Billing\SignificantEventController;
use App\Http\Controllers\Web\CashRegisterController;
use App\Http\Controllers\Web\CompanyController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DatabaseBackupController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\Parameters\CustomerController;
use App\Http\Controllers\Web\Parameters\ProductCategoryController;
use App\Http\Controllers\Web\Parameters\ProductController;
use App\Http\Controllers\Web\Parameters\SinAuthorizationController;
use App\Http\Controllers\Web\PermissionController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\SiatBranchController;
use App\Http\Controllers\Web\SiatCatalogController;
use App\Http\Controllers\Web\SiatCommunicationController;
use App\Http\Controllers\Web\SiatCuisController;
use App\Http\Controllers\Web\SiatWsdlServiceController;
use App\Http\Controllers\Web\SinApiTokenController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.store');
});

Route::middleware(['auth', 'active_account'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->whereUuid('notification')->name('notifications.read');
    Route::get('audits', [AuditController::class, 'index'])->middleware('permission:audits.view')->name('audits.index');
    Route::get('audits/{audit}', [AuditController::class, 'show'])->middleware('permission:audits.view')->name('audits.show');
    Route::prefix('backups')->name('backups.')->group(function (): void {
        Route::get('/', [DatabaseBackupController::class, 'index'])->middleware('permission:backups.view')->name('index');
        Route::post('/', [DatabaseBackupController::class, 'store'])->middleware('permission:backups.create')->name('store');
        Route::post('upload-restore', [DatabaseBackupController::class, 'uploadAndRestore'])
            ->middleware(['permission:backups.restore', 'throttle:3,10'])->name('upload-restore');
        Route::get('{backup}/download', [DatabaseBackupController::class, 'download'])
            ->where('backup', 'facturacion-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\\.sql\\.gz')
            ->middleware('permission:backups.download')->name('download');
        Route::post('{backup}/restore', [DatabaseBackupController::class, 'restore'])
            ->where('backup', 'facturacion-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\\.sql\\.gz')
            ->middleware(['permission:backups.restore', 'throttle:3,10'])->name('restore');
        Route::delete('{backup}', [DatabaseBackupController::class, 'destroy'])
            ->where('backup', 'facturacion-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\\.sql\\.gz')
            ->middleware('permission:backups.delete')->name('destroy');
    });
    Route::prefix('companies')->name('companies.')->group(function (): void {
        Route::get('/', [CompanyController::class, 'index'])->middleware('permission:companies.view')->name('index');
        Route::get('create', [CompanyController::class, 'create'])->middleware('permission:companies.create')->name('create');
        Route::post('/', [CompanyController::class, 'store'])->middleware('permission:companies.create')->name('store');
        Route::get('{company}', [CompanyController::class, 'show'])->middleware('permission:companies.view')->name('show');
        Route::get('{company}/edit', [CompanyController::class, 'edit'])->middleware('permission:companies.update')->name('edit');
        Route::put('{company}', [CompanyController::class, 'update'])->middleware('permission:companies.update')->name('update');
        Route::delete('{company}', [CompanyController::class, 'destroy'])->middleware('permission:companies.delete')->name('destroy');
    });
    Route::prefix('cash-registers')
        ->name('cash-registers.')
        ->middleware('company_user')
        ->group(function (): void {
            Route::get('/', [CashRegisterController::class, 'index'])
                ->middleware('permission:cash-registers.view')
                ->name('index');
            Route::post('/', [CashRegisterController::class, 'store'])
                ->middleware('permission:cash-registers.open')
                ->name('store');
            Route::patch('{cashRegister}/close', [CashRegisterController::class, 'close'])
                ->whereNumber('cashRegister')
                ->middleware('permission:cash-registers.close')
                ->name('close');
        });
    Route::prefix('facturacion/contingencias')
        ->name('billing.contingencies.')
        ->group(function (): void {
            Route::get('/', [ContingencyDashboardController::class, 'index'])
                ->middleware('permission:contingencies.view')->name('index');
            Route::get('eventos/{event}', [ContingencyDashboardController::class, 'event'])
                ->whereNumber('event')->middleware('permission:contingencies.events.view')->name('events.show');
            Route::get('respuesta/{type}/{id}', [ContingencyDashboardController::class, 'technical'])
                ->whereIn('type', ['invoice', 'package', 'event'])->whereNumber('id')
                ->middleware('permission:contingencies.technical.view')->name('technical.show');
            Route::post('comunicacion/consultar', [ContingencyDashboardController::class, 'verifyCommunication'])
                ->middleware('permission:contingencies.communication.check')->name('communication.check');
            Route::post('eventos/{event}/reintentar-registro', [ContingencyDashboardController::class, 'retryEvent'])
                ->whereNumber('event')->middleware('permission:contingencies.events.retry')->name('events.retry');
            Route::post('eventos/{event}/regularizar', [ContingencyDashboardController::class, 'regularizeEvent'])
                ->whereNumber('event')->middleware('permission:contingencies.events.retry')->name('events.regularize');
            Route::post('eventos/{event}/registrar', [ContingencyDashboardController::class, 'registerEvent'])
                ->whereNumber('event')->middleware('permission:contingencies.events.retry')->name('events.register');
            Route::post('eventos/{event}/generar-paquetes', [ContingencyDashboardController::class, 'buildPackages'])
                ->whereNumber('event')->middleware('permission:contingencies.packages.build')->name('packages.build');
            Route::post('paquetes/{package}/reintentar-envio', [ContingencyDashboardController::class, 'sendPackage'])
                ->whereNumber('package')->middleware('permission:contingencies.packages.send')->name('packages.send');
            Route::post('paquetes/{package}/consultar-validacion', [ContingencyDashboardController::class, 'validatePackage'])
                ->whereNumber('package')->middleware('permission:contingencies.packages.validate')->name('packages.validate');
            Route::get('facturas/{invoice}/xml', [FiscalArtifactController::class, 'xml'])
                ->whereNumber('invoice')->middleware('permission:contingencies.artifacts.download')->name('invoices.xml');
            Route::get('facturas/{invoice}/representacion', [FiscalArtifactController::class, 'pdf'])
                ->whereNumber('invoice')->middleware('permission:contingencies.artifacts.download')->name('invoices.pdf');
        });
    Route::prefix('facturacion')
        ->name('billing.')
        ->middleware('company_user')
        ->group(function (): void {
            Route::get('facturas', [InvoiceController::class, 'index'])
                ->middleware('permission:invoices.view')
                ->name('invoices.index');
            Route::get('facturas/{invoice}/imprimir', [InvoiceController::class, 'print'])
                ->whereNumber('invoice')
                ->middleware('permission:invoices.view')
                ->name('invoices.print');
            Route::get('facturas/{invoice}/xml', [FiscalArtifactController::class, 'viewXml'])
                ->whereNumber('invoice')
                ->middleware('permission:invoices.view')
                ->name('invoices.xml');
            Route::get('facturas/{invoice}/anular', [InvoiceController::class, 'cancelForm'])
                ->whereNumber('invoice')->middleware('permission:invoices.cancel')->name('invoices.cancel.form');
            Route::post('facturas/{invoice}/anular', [InvoiceController::class, 'cancel'])
                ->whereNumber('invoice')->middleware('permission:invoices.cancel')->name('invoices.cancel');
            Route::post('facturas/{invoice}/notificar-anulacion', [InvoiceController::class, 'notifyCancellation'])
                ->whereNumber('invoice')->middleware('permission:invoices.cancel')->name('invoices.cancel.notify');
            Route::get('facturas/{invoice}/revertir-anulacion', [InvoiceController::class, 'reversalForm'])
                ->whereNumber('invoice')->middleware('permission:invoices.cancel')->name('invoices.reversal.form');
            Route::post('facturas/{invoice}/revertir-anulacion', [InvoiceController::class, 'reverseCancellation'])
                ->whereNumber('invoice')->middleware('permission:invoices.cancel')->name('invoices.reversal');
            Route::post('facturas/{invoice}/notificar-reversion', [InvoiceController::class, 'notifyReversal'])
                ->whereNumber('invoice')->middleware('permission:invoices.cancel')->name('invoices.reversal.notify');
            Route::get('facturas/{invoice}/corregir-pago', [InvoiceController::class, 'correctPaymentForm'])
                ->whereNumber('invoice')->middleware('permission:invoices.issue')->name('invoices.payment.correct.form');
            Route::post('facturas/{invoice}/corregir-pago', [InvoiceController::class, 'correctPayment'])
                ->whereNumber('invoice')->middleware('permission:invoices.issue')->name('invoices.payment.correct');
            Route::post('facturas/{invoice}/reenviar', [InvoiceController::class, 'resendPendingOnline'])
                ->whereNumber('invoice')->middleware('permission:invoices.issue')->name('invoices.resend');
            Route::get('cafc', [CafcRangeController::class, 'index'])
                ->middleware('permission:cafc-ranges.view')->name('cafc-ranges.index');
            Route::post('cafc', [CafcRangeController::class, 'store'])
                ->middleware('permission:cafc-ranges.manage')->name('cafc-ranges.store');
            Route::get('contingencias-2', [CafcContingencyController::class, 'index'])
                ->middleware('permission:cafc-ranges.view')->name('cafc-contingencies.index');
            Route::post('contingencias-2', [CafcContingencyController::class, 'storeRange'])
                ->middleware('permission:cafc-ranges.manage')->name('cafc-contingencies.store');
            Route::get('contingencias-2/{cafcRange}', [CafcContingencyController::class, 'show'])
                ->whereNumber('cafcRange')->middleware('permission:manual-cafc.view')->name('cafc-contingencies.show');
            Route::post('contingencias-2/{cafcRange}/facturas', [CafcContingencyController::class, 'storeInvoice'])
                ->whereNumber('cafcRange')->middleware('permission:manual-cafc.use')->name('cafc-contingencies.invoices.store');
            Route::post('contingencias-2/{cafcRange}/finalizar', [CafcContingencyController::class, 'finalize'])
                ->whereNumber('cafcRange')->middleware('permission:manual-cafc.use')->name('cafc-contingencies.finalize');
            Route::get('manuales-cafc', [ManualCafcInvoiceController::class, 'index'])
                ->middleware('permission:manual-cafc.view')->name('manual-cafc.index');
            Route::post('manuales-cafc', [ManualCafcInvoiceController::class, 'store'])
                ->middleware('permission:manual-cafc.use')->name('manual-cafc.store');
            Route::get('manuales-cafc/{manualInvoice}/transcribir', [ManualCafcInvoiceController::class, 'edit'])
                ->whereNumber('manualInvoice')->middleware('permission:manual-cafc.transcribe')->name('manual-cafc.transcribe.edit');
            Route::put('manuales-cafc/{manualInvoice}/transcribir', [ManualCafcInvoiceController::class, 'update'])
                ->whereNumber('manualInvoice')->middleware('permission:manual-cafc.transcribe')->name('manual-cafc.transcribe.update');
            Route::post('manuales-cafc/{manualInvoice}/enviar', [ManualCafcInvoiceController::class, 'send'])
                ->whereNumber('manualInvoice')->middleware('permission:manual-cafc.transcribe')->name('manual-cafc.send');
            Route::get('configuracion/impresion', [InvoicePrintSettingController::class, 'edit'])
                ->middleware('permission:invoices.issue')
                ->name('invoice-print-settings.edit');
            Route::put('configuracion/impresion', [InvoicePrintSettingController::class, 'update'])
                ->middleware('permission:invoices.issue')
                ->name('invoice-print-settings.update');
            Route::get('emitir', [InvoiceIssueController::class, 'index'])
                ->middleware('permission:invoices.issue')
                ->name('invoices.issue.index');
            Route::get('pruebas', [InvoiceTestBatchController::class, 'index'])
                ->middleware('permission:invoice-tests.run')
                ->name('invoice-tests.index');
            Route::post('pruebas', [InvoiceTestBatchController::class, 'store'])
                ->middleware('permission:invoice-tests.run')
                ->name('invoice-tests.store');
            Route::post('pruebas/{batch}/anular', [InvoiceTestBatchController::class, 'cancel'])
                ->whereNumber('batch')->middleware('permission:invoice-tests.run')
                ->name('invoice-tests.cancel');
            Route::post('pruebas/{batch}/revertir-anulaciones', [InvoiceTestBatchController::class, 'reverse'])
                ->whereNumber('batch')->middleware('permission:invoice-tests.run')
                ->name('invoice-tests.reverse');
            Route::post('emitir/cufd/request', [InvoiceIssueController::class, 'requestCufd'])
                ->middleware('permission:invoices.issue')
                ->name('invoices.issue.cufd.request');
            Route::post('emitir/compra-venta', [InvoiceIssueController::class, 'issuePurchaseSale'])
                ->middleware('permission:invoices.issue')
                ->name('invoices.issue.purchase-sale.store');
            Route::get('facturas/{invoice}/evento-significativo', [SignificantEventController::class, 'create'])
                ->whereNumber('invoice')
                ->middleware('permission:invoices.issue')
                ->name('significant-events.create');
            Route::post('facturas/{invoice}/evento-significativo', [SignificantEventController::class, 'store'])
                ->whereNumber('invoice')
                ->middleware('permission:invoices.issue')
                ->name('significant-events.store');
            Route::get('eventos-significativos', [SignificantEventController::class, 'index'])
                ->middleware('permission:invoices.issue')
                ->name('significant-events.index');
            Route::get('eventos-significativos/registrar/{pointOfSale}', [SignificantEventController::class, 'createForPointOfSale'])
                ->whereNumber('pointOfSale')
                ->middleware('permission:invoices.issue')
                ->name('significant-events.point-of-sale.create');
            Route::post('eventos-significativos/registrar', [SignificantEventController::class, 'storeForPointOfSale'])
                ->middleware('permission:invoices.issue')
                ->name('significant-events.point-of-sale.store');
            Route::get('emitir/{documentSectorCode}', [InvoiceIssueController::class, 'show'])
                ->whereNumber('documentSectorCode')
                ->middleware('permission:invoices.issue')
                ->name('invoices.issue.show');
        });
    Route::prefix('api-token')
        ->name('sin-api-token.')
        ->middleware('company_user')
        ->group(function (): void {
            Route::get('/', [SinApiTokenController::class, 'index'])
                ->middleware('permission:sin-api-tokens.view')
                ->name('index');
            Route::post('/', [SinApiTokenController::class, 'store'])
                ->middleware('permission:sin-api-tokens.manage')
                ->name('store');
            Route::put('/', [SinApiTokenController::class, 'update'])
                ->middleware('permission:sin-api-tokens.manage')
                ->name('update');
        });
    Route::prefix('siat')
        ->name('siat.')
        ->middleware('company_user')
        ->group(function (): void {
            Route::get('wsdl-services', [SiatWsdlServiceController::class, 'index'])
                ->middleware('permission:sin-api-tokens.view')
                ->name('wsdl-services.index');
            Route::post('wsdl-services', [SiatWsdlServiceController::class, 'store'])
                ->middleware('permission:sin-api-tokens.manage')
                ->name('wsdl-services.store');
            Route::put('wsdl-services/{wsdlService}', [SiatWsdlServiceController::class, 'update'])
                ->whereNumber('wsdlService')
                ->middleware('permission:sin-api-tokens.manage')
                ->name('wsdl-services.update');
            Route::delete('wsdl-services/{wsdlService}', [SiatWsdlServiceController::class, 'destroy'])
                ->whereNumber('wsdlService')
                ->middleware('permission:sin-api-tokens.manage')
                ->name('wsdl-services.destroy');
            Route::get('communication', [SiatCommunicationController::class, 'index'])
                ->middleware('permission:siat-communication.view')
                ->name('communication.index');
            Route::post('communication/verify', [SiatCommunicationController::class, 'verify'])
                ->middleware('permission:siat-communication.verify')
                ->name('communication.verify');
            Route::get('cuis', [SiatCuisController::class, 'index'])
                ->middleware('permission:siat-cuis.view')
                ->name('cuis.index');
            Route::post('cuis/request', [SiatCuisController::class, 'request'])
                ->middleware('permission:siat-cuis.request')
                ->name('cuis.request');
            Route::post('cuis/import', [SiatCuisController::class, 'importExisting'])
                ->middleware('permission:siat-cuis.request')
                ->name('cuis.import');
            Route::get('catalogs', [SiatCatalogController::class, 'index'])
                ->middleware('permission:siat-catalogs.view')
                ->name('catalogs.index');
            Route::post('catalogs/sync-all', [SiatCatalogController::class, 'syncAll'])
                ->middleware('permission:siat-catalogs.sync')
                ->name('catalogs.sync-all');
            Route::get('catalogs/{catalog}', [SiatCatalogController::class, 'show'])
                ->middleware('permission:siat-catalogs.view')
                ->name('catalogs.show');
            Route::post('catalogs/{catalog}/sync', [SiatCatalogController::class, 'sync'])
                ->middleware('permission:siat-catalogs.sync')
                ->name('catalogs.sync');
            Route::patch('catalogs/{catalog}/items/status', [SiatCatalogController::class, 'updateItemsStatus'])
                ->middleware('permission:siat-catalogs.sync')
                ->name('catalogs.items.status');
            Route::patch('catalogs/{catalog}/items/{item}/status', [SiatCatalogController::class, 'updateItemStatus'])
                ->whereNumber('item')
                ->middleware('permission:siat-catalogs.sync')
                ->name('catalogs.items.update-status');
            Route::get('branches', [SiatBranchController::class, 'index'])
                ->middleware('permission:siat-branches.view')
                ->name('branches.index');
            Route::post('branches', [SiatBranchController::class, 'store'])
                ->middleware('permission:siat-branches.manage')
                ->name('branches.store');
            Route::post('branches/{branch}/points', [SiatBranchController::class, 'storePoint'])
                ->whereNumber('branch')
                ->middleware('permission:siat-branches.manage')
                ->name('branches.points.store');
            Route::post('branches/{branch}/points/synchronize', [SiatBranchController::class, 'synchronizePoints'])
                ->whereNumber('branch')
                ->middleware('permission:siat-branches.manage')
                ->name('branches.points.synchronize');
        });
    Route::prefix('parameters')
        ->name('parameters.')
        ->middleware('company_user')
        ->group(function (): void {
            Route::prefix('products')
                ->name('products.')
                ->group(function (): void {
                    Route::get('/', [ProductController::class, 'index'])
                        ->middleware('permission:products.view')
                        ->name('index');
                    Route::get('create', [ProductController::class, 'create'])
                        ->middleware('permission:products.create')
                        ->name('create');
                    Route::post('/', [ProductController::class, 'store'])
                        ->middleware('permission:products.create')
                        ->name('store');
                    Route::get('{product}/edit', [ProductController::class, 'edit'])
                        ->whereNumber('product')
                        ->middleware('permission:products.edit')
                        ->name('edit');
                    Route::put('{product}', [ProductController::class, 'update'])
                        ->whereNumber('product')
                        ->middleware('permission:products.edit')
                        ->name('update');
                    Route::delete('{product}', [ProductController::class, 'destroy'])
                        ->whereNumber('product')
                        ->middleware('permission:products.delete')
                        ->name('destroy');
                });
            Route::prefix('authorization')
                ->name('authorization.')
                ->group(function (): void {
                    Route::get('/', [SinAuthorizationController::class, 'index'])
                        ->middleware('permission:sin-authorizations.view')
                        ->name('index');
                    Route::post('/', [SinAuthorizationController::class, 'store'])
                        ->middleware('permission:sin-authorizations.manage')
                        ->name('store');
                    Route::put('/', [SinAuthorizationController::class, 'update'])
                        ->middleware('permission:sin-authorizations.manage')
                        ->name('update');
                });
            Route::prefix('categories')
                ->name('categories.')
                ->group(function (): void {
                    Route::get('/', [ProductCategoryController::class, 'index'])
                        ->middleware('permission:product-categories.view')
                        ->name('index');
                    Route::get('create', [ProductCategoryController::class, 'create'])
                        ->middleware('permission:product-categories.create')
                        ->name('create');
                    Route::post('/', [ProductCategoryController::class, 'store'])
                        ->middleware('permission:product-categories.create')
                        ->name('store');
                    Route::get('{productCategory}/edit', [ProductCategoryController::class, 'edit'])
                        ->whereNumber('productCategory')
                        ->middleware('permission:product-categories.edit')
                        ->name('edit');
                    Route::put('{productCategory}', [ProductCategoryController::class, 'update'])
                        ->whereNumber('productCategory')
                        ->middleware('permission:product-categories.edit')
                        ->name('update');
                    Route::delete('{productCategory}', [ProductCategoryController::class, 'destroy'])
                        ->whereNumber('productCategory')
                        ->middleware('permission:product-categories.delete')
                        ->name('destroy');
                });
            Route::prefix('customers')
                ->name('customers.')
                ->group(function (): void {
                    Route::get('/', [CustomerController::class, 'index'])
                        ->middleware('permission:customers.view')
                        ->name('index');
                    Route::get('create', [CustomerController::class, 'create'])
                        ->middleware('permission:customers.create')
                        ->name('create');
                    Route::post('/', [CustomerController::class, 'store'])
                        ->middleware('permission:customers.create')
                        ->name('store');
                    Route::get('{customer}/edit', [CustomerController::class, 'edit'])
                        ->whereNumber('customer')
                        ->middleware('permission:customers.edit')
                        ->name('edit');
                    Route::put('{customer}', [CustomerController::class, 'update'])
                        ->whereNumber('customer')
                        ->middleware('permission:customers.edit')
                        ->name('update');
                    Route::delete('{customer}', [CustomerController::class, 'destroy'])
                        ->whereNumber('customer')
                        ->middleware('permission:customers.delete')
                        ->name('destroy');
                });
        });
    Route::prefix('datatables')->name('datatables.')->group(function (): void {
        Route::get('audits', [AdminDataTableController::class, 'audits'])->name('audits');
        Route::get('invoices', [AdminDataTableController::class, 'invoices'])
            ->middleware(['company_user', 'permission:invoices.view'])
            ->name('invoices');
        Route::get('siat/catalogs/{catalog}/items', [AdminDataTableController::class, 'siatCatalogItems'])
            ->middleware('permission:siat-catalogs.view')
            ->name('siat-catalog-items');
    });
    Route::prefix('users')->name('users.')->group(function (): void {
        Route::get('/', [UserController::class, 'index'])->middleware('permission:users.view')->name('index');
        Route::get('create', [UserController::class, 'create'])->middleware('permission:users.create')->name('create');
        Route::post('/', [UserController::class, 'store'])->middleware('permission:users.create')->name('store');
        Route::get('{user}', [UserController::class, 'show'])->middleware('permission:users.view')->withTrashed()->name('show');
        Route::get('{user}/edit', [UserController::class, 'edit'])->middleware('permission:users.edit')->name('edit');
        Route::put('{user}', [UserController::class, 'update'])->middleware('permission:users.edit')->name('update');
        Route::patch('{user}/toggle-status', [UserController::class, 'toggleStatus'])->middleware('permission:users.edit')->name('toggle-status');
        Route::get('{user}/change-password', [UserController::class, 'changePasswordForm'])->middleware('permission:users.change-password')->name('change-password.form');
        Route::patch('{user}/change-password', [UserController::class, 'changePassword'])->middleware('permission:users.change-password')->name('change-password');
        Route::get('{user}/roles', [UserController::class, 'rolesForm'])->middleware('permission:users.assign-roles')->name('roles.form');
        Route::patch('{user}/assign-roles', [UserController::class, 'assignRoles'])->middleware('permission:users.assign-roles')->name('assign-roles');
        Route::delete('{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete')->name('destroy');
        Route::patch('{user}/restore', [UserController::class, 'restore'])->middleware('permission:users.restore')->name('restore');
    });
    Route::prefix('roles')->name('roles.')->group(function (): void {
        Route::get('/', [RoleController::class, 'index'])->middleware('permission:roles.view')->name('index');
        Route::get('create', [RoleController::class, 'create'])->middleware('permission:roles.create')->name('create');
        Route::post('/', [RoleController::class, 'store'])->middleware('permission:roles.create')->name('store');
        Route::get('{role}', [RoleController::class, 'show'])->middleware('permission:roles.view')->name('show');
        Route::get('{role}/edit', [RoleController::class, 'edit'])->middleware('permission:roles.edit')->name('edit');
        Route::put('{role}', [RoleController::class, 'update'])->middleware('permission:roles.edit')->name('update');
        Route::get('{role}/permissions', [RoleController::class, 'permissionsForm'])->middleware('permission:roles.assign-permissions')->name('permissions.form');
        Route::patch('{role}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:roles.assign-permissions')->name('permissions');
        Route::delete('{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete')->name('destroy');
    });
    Route::resource('permissions', PermissionController::class)->middleware([
        'index' => 'permission:permissions.view',
        'show' => 'permission:permissions.view',
        'create' => 'permission:permissions.create',
        'store' => 'permission:permissions.create',
        'edit' => 'permission:permissions.edit',
        'update' => 'permission:permissions.edit',
        'destroy' => 'permission:permissions.delete',
    ]);
});
