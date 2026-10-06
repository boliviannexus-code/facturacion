<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SinAuthorization;
use App\Models\SinCafcRange;
use App\Models\SinCommunicationLog;
use App\Models\SinInvoiceIssue;
use App\Models\SinInvoicePackage;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OwenIt\Auditing\Models\Audit;

class AuditController extends Controller
{
    public const AUDITABLE_TYPES = [
        CashRegister::class => 'Cajas',
        Company::class => 'Empresas',
        Customer::class => 'Clientes',
        Product::class => 'Productos',
        ProductCategory::class => 'Categorias',
        SinAuthorization::class => 'Autorizacion SIN',
        SinSignificantEvent::class => 'Eventos significativos',
        SinInvoiceIssue::class => 'Facturas fiscales',
        SinInvoicePackage::class => 'Paquetes de contingencia',
        SinManualContingencyInvoice::class => 'Facturas manuales CAFC',
        SinCafcRange::class => 'Rangos CAFC',
        SinCommunicationLog::class => 'Comunicación SIAT',
        User::class => 'Usuarios',
    ];

    public function index(Request $request): View
    {
        abort_unless($request->user()?->can('audits.view'), 403);

        $companyId = CompanyContext::id($request->user());

        return view('audits.index', [
            'auditableTypes' => self::AUDITABLE_TYPES,
            'companies' => Company::query()
                ->when($companyId, fn ($query, $companyId) => $query->where('id', $companyId))
                ->orderBy('name')
                ->get(['id', 'name']),
            'users' => User::query()
                ->when($companyId, fn ($query, $companyId) => $query->where('company_id', $companyId))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Audit $audit): View
    {
        abort_unless($request->user()?->can('audits.view'), 403);
        abort_unless($this->canSeeAudit($audit, $request->user()), 403);

        $audit->load('user');

        return view('audits.partials.show', [
            'audit' => $audit,
            'eventLabel' => self::eventLabel($audit->event),
            'auditableLabel' => self::auditableLabel((string) $audit->auditable_type),
            'oldValues' => $audit->old_values ?? [],
            'newValues' => $audit->new_values ?? [],
        ]);
    }

    public static function eventLabel(string $event): string
    {
        return match ($event) {
            'created' => 'Creado',
            'updated' => 'Editado',
            'deleted' => 'Eliminado',
            'restored' => 'Restaurado',
            default => ucfirst($event),
        };
    }

    public static function auditableLabel(string $type): string
    {
        return self::AUDITABLE_TYPES[$type] ?? class_basename($type);
    }

    private function canSeeAudit(Audit $audit, ?User $user): bool
    {
        $companyId = CompanyContext::id($user);

        return $companyId === null || (int) $audit->company_id === (int) $companyId;
    }
}
