@php
    $navbarCompany = \App\Support\CompanyContext::activeCompany(auth()->user());
    $navbarUnreadCount = auth()->user()->unreadNotifications()->count();
    $navbarNotifications = auth()->user()->notifications()->latest()->limit(8)->get();
@endphp

<header class="navbar navbar-expand-md d-print-none app-navbar">
    <div class="container-xl">
        <button class="btn btn-icon d-none d-lg-inline-flex me-3" type="button" data-sidebar-toggle aria-label="Replegar menu" title="Replegar menu">
            <i class="ti ti-layout-sidebar-left-collapse"></i>
        </button>

        <div class="navbar-brand d-none-navbar-horizontal pe-0 pe-md-3">
            <div class="d-flex align-items-center gap-3">
                @if ($navbarCompany?->logo_url)
                    <span class="avatar avatar-md" style="background-image: url('{{ $navbarCompany->logo_url }}')"></span>
                @else
                    <span class="avatar avatar-md bg-primary-lt text-primary">
                        <i class="ti ti-building-store fs-2"></i>
                    </span>
                @endif
                <div>
                    <div class="page-title mb-0">@yield('page-title', 'Panel administrativo')</div>
                    @hasSection('page-subtitle')
                        <div class="text-muted small">@yield('page-subtitle')</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="navbar-nav flex-row align-items-center order-md-last ms-auto">
            <div class="nav-item dropdown me-3">
                <button class="nav-link px-2 position-relative border-0 bg-transparent" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Abrir notificaciones SIAT">
                    <i class="ti ti-bell fs-2" aria-hidden="true"></i>
                    @if ($navbarUnreadCount > 0)
                        <span class="badge bg-danger navbar-alert-badge">{{ $navbarUnreadCount > 99 ? '99+' : $navbarUnreadCount }}</span>
                    @endif
                </button>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow navbar-alert-menu">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2">
                        <div>
                            <div class="fw-semibold">Alertas SIAT</div>
                            <div class="text-secondary small">{{ $navbarUnreadCount }} sin leer</div>
                        </div>
                        @if ($navbarUnreadCount > 0)
                            <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-ghost-primary btn-sm" type="submit">Marcar todas</button></form>
                        @endif
                    </div>
                    <div class="dropdown-divider my-0"></div>
                    <div class="navbar-alert-list">
                        @forelse ($navbarNotifications as $notification)
                            @php
                                $notificationTone = match ($notification->data['severity'] ?? null) {
                                    'CRITICAL' => 'danger',
                                    'WARNING' => 'warning',
                                    default => 'primary',
                                };
                            @endphp
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                <button class="dropdown-item navbar-alert-item {{ $notification->read_at ? '' : 'is-unread' }}" type="submit">
                                    <span class="navbar-alert-dot bg-{{ $notificationTone }}" aria-hidden="true"></span>
                                    <span class="text-wrap">
                                        <strong class="d-block">{{ $notification->data['title'] ?? 'Alerta SIAT' }}</strong>
                                        <span class="d-block text-secondary small">{{ \Illuminate\Support\Str::limit($notification->data['message'] ?? '', 100) }}</span>
                                        <span class="d-block text-secondary mt-1" style="font-size: .7rem">{{ $notification->created_at->diffForHumans() }}</span>
                                    </span>
                                </button>
                            </form>
                        @empty
                            <div class="px-3 py-4 text-center text-secondary small"><i class="ti ti-bell-check fs-2 d-block mb-2" aria-hidden="true"></i>No hay notificaciones.</div>
                        @endforelse
                    </div>
                    <div class="dropdown-divider my-0"></div>
                    <a class="dropdown-item text-center py-2" href="{{ route('billing.contingencies.index') }}">Abrir monitor de contingencias</a>
                </div>
            </div>
            <div class="nav-item dropdown">
                <button class="nav-link d-flex lh-1 text-reset p-0 border-0 bg-transparent" type="button" data-user-dropdown-toggle aria-expanded="false" aria-label="Abrir menu de usuario">
                    <span class="avatar avatar-sm">{{ str(auth()->user()->name ?? 'U')->substr(0, 1)->upper() }}</span>
                    <div class="d-none d-xl-block ps-2">
                        <div>{{ auth()->user()->name ?? 'Usuario' }}</div>
                        <div class="mt-1 small text-muted">{{ auth()->user()->email ?? '' }}</div>
                    </div>
                    <i class="ti ti-chevron-down ms-2 align-self-center text-muted"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <span class="dropdown-item-text text-muted small">{{ auth()->user()->email ?? '' }}</span>
                    <div class="dropdown-divider"></div>
                    <form class="m-0" method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="dropdown-item text-danger" type="submit">
                            <i class="ti ti-logout me-2"></i>Cerrar sesion
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
