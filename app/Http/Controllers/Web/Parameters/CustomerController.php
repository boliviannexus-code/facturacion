<?php

namespace App\Http\Controllers\Web\Parameters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Parameters\StoreCustomerRequest;
use App\Http\Requests\Parameters\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\Parameters\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Customer::class);

        return view('parameters.customers.index', [
            'customers' => $this->customers->paginate(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Customer::class);

        $data = $this->customers->formOptions();

        if ($request->ajax()) {
            return view('parameters.customers.partials.create-form', $data);
        }

        return view('parameters.customers.create', $data);
    }

    public function store(StoreCustomerRequest $request): JsonResponse|RedirectResponse
    {
        $customer = $this->customers->create($request->user(), $request->validated());

        if ($request->ajax() || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Cliente registrado correctamente.',
                'data' => [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'document_number' => $customer->document_number,
                        'document_complement' => $customer->document_complement,
                        'customer_code' => $customer->customer_code,
                        'email' => $customer->email,
                        'identity_document_type_code' => $customer->identity_document_type_code,
                    ],
                ],
            ], 201);
        }

        return redirect()
            ->route('parameters.customers.index')
            ->with('success', 'Cliente registrado correctamente.');
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('parameters.customers.edit', [
            'customer' => $customer,
            ...$this->customers->formOptions($customer),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->customers->update($customer, $request->validated());

        return redirect()
            ->route('parameters.customers.index')
            ->with('success', 'Cliente actualizado correctamente.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $this->customers->delete($customer);

        return redirect()
            ->route('parameters.customers.index')
            ->with('success', 'Cliente eliminado correctamente.');
    }
}
