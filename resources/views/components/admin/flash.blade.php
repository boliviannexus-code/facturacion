@if (session('success'))
    <div class="alert alert-success d-none" data-swal-success="{{ session('success') }}"></div>
@endif

@if (session('warning'))
    <div class="alert alert-warning d-none" data-swal-warning="{{ session('warning') }}"></div>
@endif

@if (session('error'))
    <div class="alert alert-danger d-none" data-swal-error="{{ session('error') }}"></div>
@endif

@if (($errors ?? null)?->any())
    <div class="alert alert-danger d-none" data-swal-error="{{ implode("\n", $errors->all()) }}"></div>
@endif
