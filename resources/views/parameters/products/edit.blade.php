@extends('layouts.admin')

@section('title', 'Editar producto | '.config('app.name', 'Base Admin'))
@section('page-title', 'Editar producto')
@section('page-subtitle', $product->internal_code)

@section('content')
    @include('parameters.products.partials.form', [
        'action' => route('parameters.products.update', $product),
        'method' => 'PUT',
        'product' => $product,
    ])
@endsection
