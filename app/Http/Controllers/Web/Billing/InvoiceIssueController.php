<?php

namespace App\Http\Controllers\Web\Billing;

use App\Enums\InvoiceFiscalStatus;
use App\Enums\SiatEnvironment;
use App\Services\Siat\SiatErrorClassifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\IssuePurchaseSaleInvoiceRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCatalogItem;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\SaleCreationService;
use App\Services\Siat\SiatCommunicationService;
use App\Services\Siat\SiatCufdService;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class InvoiceIssueController extends Controller
{
    private const DOCUMENT_SECTOR_CATALOG = 'tipos_documento_sector';

    public function __construct(
        private readonly SiatCommunicationService $communication,
        private readonly SiatCufdService $cufds,
        private readonly SiatErrorClassifier $errorClassifier,
        private readonly SaleCreationService $sales,
        private readonly InvoiceIssuanceService $invoiceIssuance,
    ) {}

    public function index(): View
    {
        return view('billing.invoices.issue.index', [
            'documentSectors' => $this->activeCatalogItems(self::DOCUMENT_SECTOR_CATALOG),
        ]);
    }

    public function show(string $documentSectorCode): View
    {
        $sector = $this->findActiveDocumentSector($documentSectorCode);

        $documentSector = (int) $documentSectorCode;

        if (! InvoiceDocumentSector::supports($documentSector)) {
            return view('billing.invoices.issue.development', [
                'sector' => $sector,
                'documentSectors' => $this->activeCatalogItems(self::DOCUMENT_SECTOR_CATALOG),
            ]);
        }

        $company = CompanyContext::activeCompany(request()->user());
        $authorization = $company?->sinAuthorization()->first() ?? SinAuthorization::query()->first();
        $apiToken = $company?->sinApiToken()->first() ?? SinApiToken::query()->first();
        $branches = SinBranch::query()
            ->with(['activePointsOfSale' => fn ($query) => $query->orderBy('point_of_sale_code')])
            ->where('is_active', true)
            ->orderBy('branch_code')
            ->get();
        $communicationStatus = $this->communicationStatus($apiToken, $authorization);
        $fiscalStatuses = $this->fiscalStatuses($branches);
        $measurementUnits = $this->activeCatalogItems('unidades_medida')
            ->keyBy(fn (SinCatalogItem $item): string => (string) ($item->classifier_code ?? Arr::get($item->raw_data, 'codigoClasificador', '')));
        $allowedActivityCodes = $this->allowedActivityCodes($documentSector);
        $productsQuery = Product::query()
            ->with('category')
            ->active()
            ->orderBy('description');

        if ($documentSector === InvoiceDocumentSector::ZERO_RATE) {
            $productsQuery->whereIn('economic_activity_code', $allowedActivityCodes);
        }

        $products = $productsQuery->get([
            'id',
            'product_category_id',
            'measurement_unit_code',
            'internal_code',
            'description',
            'economic_activity_code',
            'siat_product_code',
            'unit_price',
        ]);

        $products->each(function (Product $product) use ($measurementUnits): void {
            $unit = $measurementUnits->get((string) $product->measurement_unit_code);
            $product->setAttribute('measurement_unit_description', $unit?->description);
        });

        return view('billing.invoices.issue.purchase-sale', [
            'sector' => $sector,
            'company' => $company,
            'authorization' => $authorization,
            'refreshCufdOnPointOfSaleSelection' => $authorization?->environment_code === SiatEnvironment::TestingAndPilot,
            'branches' => $branches,
            'communicationStatus' => $communicationStatus,
            'fiscalStatuses' => $fiscalStatuses,
            'activities' => SinCatalogItem::query()
                ->where('catalog_key', 'actividades')
                ->active()
                ->when(
                    $documentSector === InvoiceDocumentSector::ZERO_RATE,
                    fn ($query) => $query->where(function ($query) use ($allowedActivityCodes): void {
                        $query->whereIn('classifier_code', $allowedActivityCodes)
                            ->orWhereIn('raw_data->codigoCaeb', $allowedActivityCodes);
                    }),
                )
                ->orderByRaw("raw_data->>'codigoCaeb'")
                ->get(),
            'customers' => Customer::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'document_number', 'document_complement', 'customer_code', 'email', 'identity_document_type_code']),
            'identityDocumentTypes' => $this->activeCatalogItems('tipos_documento_identidad'),
            'products' => $products,
            'paymentMethods' => $this->activeCatalogItems('tipos_metodo_pago'),
            'currencies' => $this->activeCatalogItems('tipos_moneda'),
            'issuanceKey' => (string) Str::uuid(),
            'documentSectorCode' => $documentSector,
            'invoiceTitle' => InvoiceDocumentSector::title($documentSector),
        ]);
    }

    public function requestCufd(Request $request): JsonResponse
    {
        $companyId = CompanyContext::id($request->user());

        $validated = $request->validate([
            'sin_point_of_sale_id' => [
                'required',
                'integer',
                Rule::exists('sin_points_of_sale', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
        ], [
            'sin_point_of_sale_id.required' => 'Selecciona la sucursal y punto de venta.',
            'sin_point_of_sale_id.exists' => 'Selecciona un punto de venta activo valido.',
        ]);

        $pointOfSale = SinPointOfSale::query()
            ->with('branch')
            ->findOrFail((int) $validated['sin_point_of_sale_id']);

        $cufd = $this->cufds->request($request->user(), $pointOfSale);
        $errorType = $cufd->transaccion
            ? null
            : $this->errorClassifier->classify(new RuntimeException((string) $cufd->message));
        $communicationUnavailable = $errorType?->canOpenContingencyAfterRetries() ?? false;
        $usableCufd = $cufd->transaccion
            ? $cufd
            : $this->cufds->currentForPointOfSale($pointOfSale);
        $message = $communicationUnavailable
            ? 'No existe comunicación con el SIN. Puede continuar con la emisión fuera de línea; la factura quedará pendiente de regularización.'
            : $cufd->message;

        return response()->json([
            'success' => $cufd->transaccion,
            'message' => $message,
            'communication_ok' => ! $communicationUnavailable,
            'contingency_suggested' => $communicationUnavailable,
            'technical_message' => $cufd->message,
            'data' => [
                'cufd' => [
                    'id' => $usableCufd?->id,
                    'status' => $usableCufd?->status_label,
                    'is_current' => $usableCufd?->transaccion && $usableCufd->expires_at?->isFuture(),
                    'expires_at' => $usableCufd?->expires_at?->format('d/m/Y H:i'),
                    'control_code' => $usableCufd?->control_code,
                ],
            ],
        ], $cufd->transaccion ? 201 : ($communicationUnavailable ? 200 : 422));
    }

    public function issuePurchaseSale(IssuePurchaseSaleInvoiceRequest $request): JsonResponse
    {
        $sale = $this->sales->create($request->user(), $request->validated());
        $result = $this->invoiceIssuance->issue($sale);
        $issue = $result->invoice;
        $validated = $issue?->status_code === 908 && $issue->transaccion;
        $offlineIssued = $issue?->fiscal_status === InvoiceFiscalStatus::OfflineIssued;
        $successful = $validated || $offlineIssued;

        return response()->json([
            'success' => $successful,
            'message' => $result->message,
            'decision' => $result->decision->value,
            'data' => [
                'invoice' => $issue ? [
                    'id' => $issue->id,
                    'invoice_number' => $issue->invoice_number,
                    'attempted_invoice_number' => $issue->attempted_invoice_number,
                    'cuf' => $issue->cuf,
                    'reception_code' => $issue->reception_code,
                    'status_code' => $issue->status_code,
                    'status_label' => $issue->status_label,
                    'transaccion' => $issue->transaccion,
                    'hash_file' => $issue->hash_file,
                    'xml_path' => $issue->xml_path,
                    'gzip_path' => $issue->gzip_path,
                    'print_url' => ($validated || $offlineIssued)
                        ? route('billing.invoices.print', $issue)
                        : null,
                    'xml_url' => $issue->xml_path
                        ? route('billing.invoices.xml', $issue)
                        : null,
                    'emission_mode' => $issue->emission_mode->value,
                    'commercial_status' => $issue->commercial_status->value,
                    'fiscal_status' => $issue->fiscal_status->value,
                    'failure_category' => $issue->failure_category?->value,
                    'contingency_url' => $issue->allowsSignificantEvent()
                        ? route('billing.significant-events.create', $issue)
                        : null,
                ] : null,
            ],
        ], $successful ? 201 : 200);
    }

    /**
     * @return Collection<int, SinCatalogItem>
     */
    private function activeCatalogItems(string $catalogKey): Collection
    {
        return SinCatalogItem::query()
            ->where('catalog_key', $catalogKey)
            ->active()
            ->orderByRaw("nullif(classifier_code, '')::integer nulls last")
            ->orderBy('description')
            ->get();
    }

    private function findActiveDocumentSector(string $documentSectorCode): SinCatalogItem
    {
        return SinCatalogItem::query()
            ->where('catalog_key', self::DOCUMENT_SECTOR_CATALOG)
            ->active()
            ->where(function ($query) use ($documentSectorCode): void {
                $query->where('classifier_code', $documentSectorCode)
                    ->orWhere('raw_data->codigoClasificador', $documentSectorCode);
            })
            ->firstOrFail();
    }

    /** @return array<int, string> */
    private function allowedActivityCodes(int $documentSectorCode): array
    {
        return SinCatalogItem::query()
            ->where('catalog_key', 'actividades_documento_sector')
            ->active()
            ->get(['classifier_code', 'raw_data'])
            ->filter(fn (SinCatalogItem $item): bool => (int) Arr::get($item->raw_data, 'codigoDocumentoSector') === $documentSectorCode)
            ->map(fn (SinCatalogItem $item): string => (string) Arr::get($item->raw_data, 'codigoActividad', $item->classifier_code))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{ok: bool, invalid_detail: string}
     */
    private function communicationStatus(?SinApiToken $apiToken, ?SinAuthorization $authorization): array
    {
        if (! $authorization) {
            return [
                'ok' => false,
                'invalid_detail' => 'NIT no configurado',
            ];
        }

        if (! $apiToken) {
            return [
                'ok' => false,
                'invalid_detail' => 'Sin conexion',
            ];
        }

        $result = $this->communication->verify($apiToken);

        return [
            'ok' => $result->ok,
            'invalid_detail' => $result->ok ? '' : 'Sin conexion',
        ];
    }

    /**
     * @param  Collection<int, SinBranch>  $branches
     * @return array<string, array{cuis_valid: bool, cuis_label: string, cuis_detail: string, cufd_valid: bool, cufd_label: string, cufd_detail: string, recovery_blocked: bool}>
     */
    private function fiscalStatuses(Collection $branches): array
    {
        $statuses = [];
        $branches->each(function (SinBranch $branch) use (&$statuses): void {
            $branch->activePointsOfSale->each(function (SinPointOfSale $pointOfSale) use (&$statuses): void {
                $currentCuis = $this->currentCuisForPointOfSale($pointOfSale);
                $currentCufd = $this->cufds->currentForPointOfSale($pointOfSale);

                $statuses[(string) $pointOfSale->id] = [
                    'cuis_valid' => $currentCuis !== null,
                    'cuis_label' => 'CUIS',
                    'cuis_detail' => $currentCuis ? '' : 'CUIS no vigente',
                    'cufd_valid' => $currentCufd !== null,
                    'cufd_label' => 'CUFD',
                    'cufd_detail' => $currentCufd ? '' : 'CUFD no vigente',
                    'recovery_blocked' => false,
                ];
            });
        });

        return $statuses;
    }

    private function currentCuisForPointOfSale(SinPointOfSale $pointOfSale): ?SinCuis
    {
        return SinCuis::query()
            ->usable()
            ->where(function ($query) use ($pointOfSale): void {
                $query
                    ->where('sin_point_of_sale_id', $pointOfSale->id)
                    ->orWhere(function ($query) use ($pointOfSale): void {
                        $query
                            ->whereNull('sin_point_of_sale_id')
                            ->where('company_id', $pointOfSale->company_id)
                            ->where('branch_code', $pointOfSale->branch->branch_code)
                            ->where('point_of_sale_code', $pointOfSale->point_of_sale_code);
                    });
            })
            ->latest('requested_at')
            ->first();
    }
}
