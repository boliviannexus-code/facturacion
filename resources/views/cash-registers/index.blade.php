@extends('layouts.admin')

@section('title', 'Cajas | '.config('app.name', 'Base Admin'))
@section('page-title', 'Cajas')
@section('page-subtitle', 'Apertura, cierre e historial de cajas de la empresa')

@section('content')
    @if ($errors->has('cash_register'))
        <div class="alert alert-danger" role="alert">
            <i class="ti ti-alert-circle me-1" aria-hidden="true"></i>{{ $errors->first('cash_register') }}
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            @if ($activeCashRegister)
                <x-ui.card title="Mi caja activa">
                    <div class="card-body">
                        <dl class="row mb-4">
                            <dt class="col-sm-5">Apertura</dt>
                            <dd class="col-sm-7">{{ $activeCashRegister->opened_at->format('d/m/Y H:i') }}</dd>

                            <dt class="col-sm-5">Monto inicial</dt>
                            <dd class="col-sm-7 fw-semibold">Bs {{ money_format_decimal($activeCashRegister->opening_amount) }}</dd>

                            <dt class="col-sm-5">Estado</dt>
                            <dd class="col-sm-7"><span class="badge text-bg-success">Activa</span></dd>

                            @if ($activeCashRegister->opening_notes)
                                <dt class="col-sm-5">Nota</dt>
                                <dd class="col-sm-7">{{ $activeCashRegister->opening_notes }}</dd>
                            @endif
                        </dl>

                        @can('close', $activeCashRegister)
                            <form method="POST" action="{{ route('cash-registers.close', $activeCashRegister) }}" novalidate>
                                @csrf
                                @method('PATCH')

                                <div class="mb-3">
                                    <label class="form-label" for="closing-amount">Monto contado al cierre</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Bs</span>
                                        <input
                                            class="form-control @error('closing_amount') is-invalid @enderror"
                                            id="closing-amount"
                                            name="closing_amount"
                                            type="number"
                                            min="0"
                                            max="9999999999999999.99"
                                            step="0.01"
                                            inputmode="decimal"
                                            value="{{ old('closing_amount') }}"
                                            required
                                        >
                                        @error('closing_amount')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="closing-notes">Observacion de cierre</label>
                                    <textarea
                                        class="form-control @error('closing_notes') is-invalid @enderror"
                                        id="closing-notes"
                                        name="closing_notes"
                                        rows="3"
                                        maxlength="1000"
                                    >{{ old('closing_notes') }}</textarea>
                                    @error('closing_notes')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <button class="btn btn-danger w-100" type="submit">
                                    <i class="ti ti-lock me-1" aria-hidden="true"></i>Cerrar caja
                                </button>
                            </form>
                        @endcan
                    </div>
                </x-ui.card>
            @else
                @can('create', \App\Models\CashRegister::class)
                    <x-ui.card title="Abrir mi caja">
                        <div class="card-body">
                            <form method="POST" action="{{ route('cash-registers.store') }}" novalidate>
                                @csrf

                                <div class="mb-3">
                                    <label class="form-label" for="opening-amount">Monto inicial</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Bs</span>
                                        <input
                                            class="form-control @error('opening_amount') is-invalid @enderror"
                                            id="opening-amount"
                                            name="opening_amount"
                                            type="number"
                                            min="0"
                                            max="9999999999999999.99"
                                            step="0.01"
                                            inputmode="decimal"
                                            value="{{ old('opening_amount', '0.00') }}"
                                            required
                                            autofocus
                                        >
                                        @error('opening_amount')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="opening-notes">Observacion de apertura</label>
                                    <textarea
                                        class="form-control @error('opening_notes') is-invalid @enderror"
                                        id="opening-notes"
                                        name="opening_notes"
                                        rows="3"
                                        maxlength="1000"
                                    >{{ old('opening_notes') }}</textarea>
                                    @error('opening_notes')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <button class="btn btn-primary w-100" type="submit">
                                    <i class="ti ti-lock-open me-1" aria-hidden="true"></i>Abrir caja
                                </button>
                            </form>
                        </div>
                    </x-ui.card>
                @endcan
            @endif
        </div>

        <div class="col-lg-7">
            <x-ui.card title="Control operativo">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3 mb-4">
                        <span class="avatar bg-primary-lt text-primary"><i class="ti ti-user-dollar"></i></span>
                        <div>
                            <div class="fw-semibold">{{ auth()->user()->name }}</div>
                            <div class="text-body-secondary small">{{ auth()->user()->company?->name }}</div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-body-secondary small mb-1">Estado personal</div>
                                <div class="fw-semibold">{{ $activeCashRegister ? 'Caja activa' : 'Sin caja activa' }}</div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-body-secondary small mb-1">Registros de la empresa</div>
                                <div class="fw-semibold">{{ $cashRegisters->total() }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>

    <x-ui.table-card title="Historial de cajas">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Apertura</th>
                    <th class="text-end">Monto inicial</th>
                    <th>Cierre</th>
                    <th class="text-end">Monto final</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cashRegisters as $cashRegister)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $cashRegister->user?->name ?? 'Usuario no disponible' }}</div>
                            @if ((int) $cashRegister->user_id === (int) auth()->id())
                                <span class="text-body-secondary small">Mi caja</span>
                            @endif
                        </td>
                        <td>{{ $cashRegister->opened_at->format('d/m/Y H:i') }}</td>
                        <td class="text-end">Bs {{ money_format_decimal($cashRegister->opening_amount) }}</td>
                        <td>{{ $cashRegister->closed_at?->format('d/m/Y H:i') ?? '-' }}</td>
                        <td class="text-end">
                            {{ $cashRegister->closing_amount !== null ? 'Bs '.money_format_decimal($cashRegister->closing_amount) : '-' }}
                        </td>
                        <td>
                            <span class="badge text-bg-{{ $cashRegister->isActive() ? 'success' : 'secondary' }}">
                                {{ $cashRegister->isActive() ? 'Activa' : 'Cerrada' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row colspan="6" message="No hay cajas registradas en esta empresa." />
                @endforelse
            </tbody>
        </table>

        <x-slot:footer>
            {{ $cashRegisters->links() }}
        </x-slot:footer>
    </x-ui.table-card>
@endsection
