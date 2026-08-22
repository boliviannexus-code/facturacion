@extends('layouts.admin')

@section('title', 'Editar cliente | '.config('app.name', 'Base Admin'))
@section('page-title', 'Editar cliente')
@section('page-subtitle', 'Parametros')

@section('content')
    @include('parameters.customers.partials.form', [
        'action' => route('parameters.customers.update', $customer),
        'method' => 'PUT',
        'customer' => $customer,
    ])
@endsection
