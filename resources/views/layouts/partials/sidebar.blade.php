@php
    $organizationOpen = request()->routeIs('companies.*');
    $adminOpen = request()->routeIs('users.*', 'roles.*', 'permissions.*', 'audits.*', 'backups.*');
    $cashRegistersOpen = request()->routeIs('cash-registers.*');
    $billingOpen = request()->routeIs('billing.*');
    $apiTokenOpen = request()->routeIs('sin-api-token.*');
    $siatCommunicationOpen = request()->routeIs('siat.communication.*');
    $siatCuisOpen = request()->routeIs('siat.cuis.*');
    $siatCatalogsOpen = request()->routeIs('siat.catalogs.*');
    $siatBranchesOpen = request()->routeIs('siat.branches.*');
    $siatWsdlServicesOpen = request()->routeIs('siat.wsdl-services.*');
    $siatOpen = $apiTokenOpen || $siatCommunicationOpen || $siatCuisOpen || $siatCatalogsOpen || $siatBranchesOpen || $siatWsdlServicesOpen;
    $parametersOpen = request()->routeIs('parameters.*');

    $canOrganization = auth()->user()?->can('companies.view');
    $canCashRegisters = auth()->user()?->company_id !== null
        && auth()->user()?->can('cash-registers.view');
    $canBilling = auth()->user()?->company_id !== null
        && (
            auth()->user()?->can('invoices.view')
            || auth()->user()?->can('invoices.issue')
            || auth()->user()?->can('cafc-ranges.view')
            || auth()->user()?->can('manual-cafc.view')
        );
    $canContingencies = \App\Support\CompanyContext::canOperate(auth()->user())
        && auth()->user()?->can('contingencies.view');
    $canApiToken = auth()->user()?->company_id !== null
        && auth()->user()?->can('sin-api-tokens.view');
    $canSiatCommunication = auth()->user()?->company_id !== null
        && auth()->user()?->can('siat-communication.view');
    $canSiatCuis = auth()->user()?->company_id !== null
        && auth()->user()?->can('siat-cuis.view');
    $canSiatCatalogs = auth()->user()?->company_id !== null
        && auth()->user()?->can('siat-catalogs.view');
    $canSiatBranches = auth()->user()?->company_id !== null
        && auth()->user()?->can('siat-branches.view');
    $canSiat = $canApiToken || $canSiatCommunication || $canSiatCuis || $canSiatCatalogs || $canSiatBranches;
    $canParameters = auth()->user()?->company_id !== null
        && (
            auth()->user()?->can('product-categories.view')
            || auth()->user()?->can('customers.view')
            || auth()->user()?->can('products.view')
            || auth()->user()?->can('sin-authorizations.view')
        );
    $canAdmin = auth()->user()?->can('users.view')
        || auth()->user()?->can('roles.view')
        || auth()->user()?->can('permissions.view')
        || auth()->user()?->can('audits.view')
        || auth()->user()?->can('backups.view');
    $sidebarCompany = \App\Support\CompanyContext::activeCompany(auth()->user());
@endphp

<aside class="navbar navbar-vertical navbar-expand-lg app-sidebar" id="adminSidebar" data-bs-theme="dark">
    <div class="container-fluid">
        <h1 class="navbar-brand navbar-brand-autodark justify-content-start">
            <a href="{{ route('dashboard') }}" class="d-flex align-items-center gap-2 text-decoration-none">
                <span class="lh-sm">
                    <span class="d-block text-truncate">{{ $sidebarCompany?->name ?? config('app.name', 'Base Admin') }}</span>
                    @if ($sidebarCompany)
                        <span class="d-block small text-muted">{{ config('app.name', 'Base Admin') }}</span>
                    @endif
                </span>
            </a>
        </h1>

        <div class="navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-3">
                <li class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <a class="nav-link" href="{{ route('dashboard') }}">
                        <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-dashboard"></i></span>
                        <span class="nav-link-title">Dashboard</span>
                    </a>
                </li>

                @if ($canOrganization)
                    <li class="nav-item app-menu-section {{ $organizationOpen ? 'active' : '' }}">
                        <button class="nav-link app-menu-toggle {{ $organizationOpen ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#menu-organization" aria-expanded="{{ $organizationOpen ? 'true' : 'false' }}" aria-controls="menu-organization">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-building-store"></i></span>
                            <span class="nav-link-title">Organizacion</span>
                            <span class="menu-chevron"><i class="ti ti-chevron-down"></i></span>
                        </button>
                        <div class="collapse {{ $organizationOpen ? 'show' : '' }}" id="menu-organization">
                            <ul class="nav app-submenu">
                                @can('companies.view')
                                    <li class="nav-item {{ request()->routeIs('companies.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('companies.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-building"></i></span>
                                            <span class="nav-link-title">Empresas</span>
                                        </a>
                                    </li>
                                @endcan
                            </ul>
                        </div>
                    </li>
                @endif

                @if ($canParameters)
                    <li class="nav-item app-menu-section {{ $parametersOpen ? 'active' : '' }}">
                        <button class="nav-link app-menu-toggle {{ $parametersOpen ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#menu-parameters" aria-expanded="{{ $parametersOpen ? 'true' : 'false' }}" aria-controls="menu-parameters">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-adjustments"></i></span>
                            <span class="nav-link-title">Parametros</span>
                            <span class="menu-chevron"><i class="ti ti-chevron-down"></i></span>
                        </button>
                        <div class="collapse {{ $parametersOpen ? 'show' : '' }}" id="menu-parameters">
                            <ul class="nav app-submenu">
                                @can('products.view')
                                    <li class="nav-item {{ request()->routeIs('parameters.products.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('parameters.products.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-package"></i></span>
                                            <span class="nav-link-title">Productos</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('sin-authorizations.view')
                                    <li class="nav-item {{ request()->routeIs('parameters.authorization.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('parameters.authorization.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-certificate"></i></span>
                                            <span class="nav-link-title">Autorizacion</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('product-categories.view')
                                    <li class="nav-item {{ request()->routeIs('parameters.categories.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('parameters.categories.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-category"></i></span>
                                            <span class="nav-link-title">Categorias</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('customers.view')
                                    <li class="nav-item {{ request()->routeIs('parameters.customers.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('parameters.customers.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-id"></i></span>
                                            <span class="nav-link-title">Clientes</span>
                                        </a>
                                    </li>
                                @endcan
                            </ul>
                        </div>
                    </li>
                @endif

                @if ($canSiat)
                    <li class="nav-item app-menu-section {{ $siatOpen ? 'active' : '' }}">
                        <button class="nav-link app-menu-toggle {{ $siatOpen ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#menu-siat" aria-expanded="{{ $siatOpen ? 'true' : 'false' }}" aria-controls="menu-siat">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-cloud-code"></i></span>
                            <span class="nav-link-title">SIAT</span>
                            <span class="menu-chevron"><i class="ti ti-chevron-down"></i></span>
                        </button>
                        <div class="collapse {{ $siatOpen ? 'show' : '' }}" id="menu-siat">
                            <ul class="nav app-submenu">
                                @can('sin-api-tokens.view')
                                    <li class="nav-item {{ $siatWsdlServicesOpen ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('siat.wsdl-services.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-world-code"></i></span>
                                            <span class="nav-link-title">Servicios WSDL</span>
                                        </a>
                                    </li>

                                    <li class="nav-item {{ $apiTokenOpen ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('sin-api-token.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-key"></i></span>
                                            <span class="nav-link-title">Token API</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('siat-communication.view')
                                    <li class="nav-item {{ $siatCommunicationOpen ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('siat.communication.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-plug-connected"></i></span>
                                            <span class="nav-link-title">Verificar comunicacion</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('siat-cuis.view')
                                    <li class="nav-item {{ $siatCuisOpen ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('siat.cuis.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-id-badge-2"></i></span>
                                            <span class="nav-link-title">CUIS</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('siat-branches.view')
                                    <li class="nav-item {{ $siatBranchesOpen ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('siat.branches.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-building-store"></i></span>
                                            <span class="nav-link-title">Sucursales</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('siat-catalogs.view')
                                    <li class="nav-item {{ $siatCatalogsOpen ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('siat.catalogs.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-table-options"></i></span>
                                            <span class="nav-link-title">Catalogos SIAT</span>
                                        </a>
                                    </li>
                                @endcan
                            </ul>
                        </div>
                    </li>
                @endif

                @if ($canCashRegisters)
                    <li class="nav-item {{ $cashRegistersOpen ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('cash-registers.index') }}">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-cash-register"></i></span>
                            <span class="nav-link-title">Cajas</span>
                        </a>
                    </li>
                @endif

                @if ($canBilling)
                    <li class="nav-item app-menu-section {{ $billingOpen ? 'active' : '' }}">
                        <button class="nav-link app-menu-toggle {{ $billingOpen ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#menu-billing" aria-expanded="{{ $billingOpen ? 'true' : 'false' }}" aria-controls="menu-billing">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-file-invoice"></i></span>
                            <span class="nav-link-title">Facturacion</span>
                            <span class="menu-chevron"><i class="ti ti-chevron-down"></i></span>
                        </button>
                        <div class="collapse {{ $billingOpen ? 'show' : '' }}" id="menu-billing">
                            <ul class="nav app-submenu">
                                @can('invoices.view')
                                    <li class="nav-item {{ request()->routeIs('billing.invoices.index') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('billing.invoices.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-files"></i></span>
                                            <span class="nav-link-title">Facturas</span>
                                        </a>
                                    </li>
                                @endcan
                                @can('contingencies.view')
                                    <li class="nav-item {{ request()->routeIs('billing.contingencies.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('billing.contingencies.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-activity-heartbeat"></i></span>
                                            <span class="nav-link-title">Contingencias</span>
                                        </a>
                                    </li>
                                @endcan
                                @can('invoices.issue')
                                    <li class="nav-item {{ request()->routeIs('billing.significant-events.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('billing.significant-events.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-alert-triangle"></i></span>
                                            <span class="nav-link-title">Eventos significativos</span>
                                        </a>
                                    </li>
                                    <li class="nav-item {{ request()->routeIs('billing.invoices.issue.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('billing.invoices.issue.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-receipt"></i></span>
                                            <span class="nav-link-title">Emitir factura</span>
                                        </a>
                                    </li>
                                    @can('invoice-tests.run')
                                        <li class="nav-item {{ request()->routeIs('billing.invoice-tests.*') ? 'active' : '' }}">
                                            <a class="nav-link" href="{{ route('billing.invoice-tests.index') }}">
                                                <span class="nav-link-icon"><i class="ti ti-test-pipe"></i></span>
                                                <span class="nav-link-title">Pruebas de facturación</span>
                                            </a>
                                        </li>
                                    @endcan
                                    <li class="nav-item {{ request()->routeIs('billing.invoice-print-settings.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('billing.invoice-print-settings.edit') }}">
                                            <span class="nav-link-icon"><i class="ti ti-printer"></i></span>
                                            <span class="nav-link-title">Configuracion</span>
                                        </a>
                                    </li>
                                @endcan
                                @can('cafc-ranges.view')
                                    <li class="nav-item {{ request()->routeIs('billing.cafc-contingencies.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('billing.cafc-contingencies.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-file-invoice"></i></span>
                                            <span class="nav-link-title">Contingencias 2</span>
                                        </a>
                                    </li>
                                    <li class="nav-item {{ request()->routeIs('billing.cafc-ranges.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('billing.cafc-ranges.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-number"></i></span>
                                            <span class="nav-link-title">Rangos CAFC</span>
                                        </a>
                                    </li>
                                @endcan
                                @can('manual-cafc.view')
                                    <li class="nav-item {{ request()->routeIs('billing.manual-cafc.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('billing.manual-cafc.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-file-pencil"></i></span>
                                            <span class="nav-link-title">Facturas manuales</span>
                                        </a>
                                    </li>
                                @endcan
                            </ul>
                        </div>
                    </li>
                @endif

                @if ($canContingencies && ! $canBilling)
                    <li class="nav-item {{ request()->routeIs('billing.contingencies.*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('billing.contingencies.index') }}">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-activity-heartbeat"></i></span>
                            <span class="nav-link-title">Contingencias</span>
                        </a>
                    </li>
                @endif

                @if ($canAdmin)
                    <li class="nav-item app-menu-section {{ $adminOpen ? 'active' : '' }}">
                        <button class="nav-link app-menu-toggle {{ $adminOpen ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#menu-admin" aria-expanded="{{ $adminOpen ? 'true' : 'false' }}" aria-controls="menu-admin">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-settings"></i></span>
                            <span class="nav-link-title">Administracion</span>
                            <span class="menu-chevron"><i class="ti ti-chevron-down"></i></span>
                        </button>
                        <div class="collapse {{ $adminOpen ? 'show' : '' }}" id="menu-admin">
                            <ul class="nav app-submenu">
                                @can('users.view')
                                    <li class="nav-item {{ request()->routeIs('users.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('users.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-users"></i></span>
                                            <span class="nav-link-title">Usuarios</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('roles.view')
                                    <li class="nav-item {{ request()->routeIs('roles.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('roles.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-lock-access"></i></span>
                                            <span class="nav-link-title">Roles</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('permissions.view')
                                    <li class="nav-item {{ request()->routeIs('permissions.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('permissions.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-shield-check"></i></span>
                                            <span class="nav-link-title">Permisos</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('audits.view')
                                    <li class="nav-item {{ request()->routeIs('audits.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('audits.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-list-search"></i></span>
                                            <span class="nav-link-title">Auditoria</span>
                                        </a>
                                    </li>
                                @endcan

                                @can('backups.view')
                                    <li class="nav-item {{ request()->routeIs('backups.*') ? 'active' : '' }}">
                                        <a class="nav-link" href="{{ route('backups.index') }}">
                                            <span class="nav-link-icon"><i class="ti ti-database-export"></i></span>
                                            <span class="nav-link-title">Respaldos</span>
                                        </a>
                                    </li>
                                @endcan
                            </ul>
                        </div>
                    </li>
                @endif

            </ul>
        </div>
    </div>
</aside>
