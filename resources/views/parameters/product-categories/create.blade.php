@extends('layouts.admin')

@section('title', 'Nueva categoria | '.config('app.name', 'Base Admin'))
@section('page-title', 'Nueva categoria')
@section('page-subtitle', 'Parametros')

@section('content')
    @include('parameters.product-categories.partials.form', [
        'action' => route('parameters.categories.store'),
        'method' => 'POST',
        'category' => null,
    ])
@endsection
