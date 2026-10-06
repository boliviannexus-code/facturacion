@extends('layouts.admin')

@section('title', 'Nuevo cliente | '.config('app.name', 'Base Admin'))
@section('page-title', 'Nuevo cliente')
@section('page-subtitle', 'Parametros')

@section('content')
    @include('parameters.customers.partials.form', [
        'action' => route('parameters.customers.store'),
        'method' => 'POST',
        'customer' => null,
    ])
@endsection
