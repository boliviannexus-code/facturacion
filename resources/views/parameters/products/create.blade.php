@extends('layouts.admin')

@section('title', 'Nuevo producto | '.config('app.name', 'Base Admin'))
@section('page-title', 'Nuevo producto')
@section('page-subtitle', 'Parametros')

@section('content')
    @include('parameters.products.partials.form', [
        'action' => route('parameters.products.store'),
        'method' => 'POST',
        'product' => null,
    ])
@endsection
