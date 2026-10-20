<?php

namespace App\Providers;

use App\Contracts\DatabaseBackupManager;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Policies\CashRegisterPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\ProductCategoryPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RolePolicy;
use App\Policies\SinApiTokenPolicy;
use App\Policies\SinAuthorizationPolicy;
use App\Services\Backup\PostgresDatabaseBackupManager;
use App\Services\Billing\ComputerizedOnlineXmlSigner;
use App\Services\Billing\Contracts\InvoiceCancellationReversalSiatClient;
use App\Services\Billing\Contracts\InvoiceCancellationSiatClient;
use App\Services\Billing\Contracts\InvoiceSiatClient;
use App\Services\Billing\Contracts\InvoiceXmlSigner;
use App\Services\Billing\Packages\Contracts\InvoicePackageSiatClient;
use App\Services\Billing\Packages\SoapInvoicePackageSiatClient;
use App\Services\Billing\SoapInvoiceCancellationReversalSiatClient;
use App\Services\Billing\SoapInvoiceCancellationSiatClient;
use App\Services\Billing\SoapInvoiceSiatClient;
use App\Services\Siat\Contracts\SiatCommunicationClient;
use App\Services\Siat\Contracts\SiatDelay;
use App\Services\Siat\NativeSiatDelay;
use App\Services\Siat\Recovery\Contracts\RecoveryCufdProvider;
use App\Services\Siat\Recovery\Contracts\SignificantEventRegistrar;
use App\Services\Siat\Recovery\SiatRecoveryCufdProvider;
use App\Services\Siat\Recovery\SoapSignificantEventRegistrar;
use App\Services\Siat\SiatContingencyPolicy;
use App\Services\Siat\SiatRetryPolicy;
use App\Services\Siat\SoapSiatCommunicationClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DatabaseBackupManager::class, PostgresDatabaseBackupManager::class);
        $this->app->bind(InvoiceSiatClient::class, SoapInvoiceSiatClient::class);
        $this->app->bind(InvoiceCancellationSiatClient::class, SoapInvoiceCancellationSiatClient::class);
        $this->app->bind(InvoiceCancellationReversalSiatClient::class, SoapInvoiceCancellationReversalSiatClient::class);
        $this->app->bind(InvoiceXmlSigner::class, ComputerizedOnlineXmlSigner::class);
        $this->app->bind(InvoicePackageSiatClient::class, SoapInvoicePackageSiatClient::class);
        $this->app->bind(SiatCommunicationClient::class, SoapSiatCommunicationClient::class);
        $this->app->bind(RecoveryCufdProvider::class, SiatRecoveryCufdProvider::class);
        $this->app->bind(SignificantEventRegistrar::class, SoapSignificantEventRegistrar::class);
        $this->app->singleton(SiatDelay::class, NativeSiatDelay::class);
        $this->app->singleton(SiatRetryPolicy::class, static fn (): SiatRetryPolicy => new SiatRetryPolicy(
            delays: (array) config('siat.communication.retry_delays', [0, 2, 5]),
            timeoutSeconds: (int) config('siat.communication.timeout_seconds', 5),
        ));
        $this->app->singleton(SiatContingencyPolicy::class, static fn (): SiatContingencyPolicy => new SiatContingencyPolicy(
            minimumConsecutiveFailures: (int) config('siat.communication.contingency_failure_threshold', 3),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(CashRegister::class, CashRegisterPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
        Gate::policy(SinApiToken::class, SinApiTokenPolicy::class);
        Gate::policy(SinAuthorization::class, SinAuthorizationPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
