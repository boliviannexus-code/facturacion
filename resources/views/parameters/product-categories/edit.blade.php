@extends('layouts.admin')

@section('title', 'Editar categoria | '.config('app.name', 'Base Admin'))
@section('page-title', 'Editar categoria')
@section('page-subtitle', 'Parametros')

@section('content')
    @include('parameters.product-categories.partials.form', [
        'action' => route('parameters.categories.update', $category),
        'method' => 'PUT',
        'category' => $category,
    ])
@endsection
