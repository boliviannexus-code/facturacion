<?php

namespace App\Services\Siat;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\SignificantEventStatus;
use App\Models\SinApiToken;
use App\Models\SinCufd;
use App\Models\SinInvoiceIssue;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Parameters\SinAuthorizationService;
use App\Services\SinApiTokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SignificantEventService
{
    public function __construct(
        private readonly SiatSoapClientFactory $clients,
        private readonly SinApiTokenService $apiTokens,
        private readonly SinAuthorizationService $authorizations,
        private readonly SiatCuisService $cuisService,
        private readonly SiatCufdService $cufdService,
    ) {}

    /** @param array<string, mixed> $data */
    public function registerForPointOfSale(User $user, SinPointOfSale $pointOfSale, array $data): SinSignificantEvent
    {
        $pointOfSale->loadMissing('branch');
        $pendingEvent = $this->pendingOfflineEvent($pointOfSale);

        if ($pendingEvent) {
            throw ValidationException::withMessages([
                'sin_point_of_sale_id' => "El evento #{$pendingEvent->id} ya contiene facturas fuera de línea pendientes. Registra ese evento desde el menú Contingencias; no debes crear otro evento independiente.",
            ]);
        }

        $apiToken = $this->apiTokens->current();
        $authorization = $this->authorizations->current();
        $cuis = $this->cuisService->currentForPointOfSale($pointOfSale);
        $currentCufd = $this->cufdService->currentForPointOfSale($pointOfSale);
        $eventCufd = $this->cufdForEvent($pointOfSale, (string) $data['started_at']);

        if (! $apiToken || ! $authorization || ! $cuis?->cuis_code || ! $currentCufd?->cufd_code || ! $pointOfSale->branch) {
            throw ValidationException::withMessages([
                'configuration' => 'El punto de venta necesita token, autorizacion, CUIS y CUFD vigentes para registrar la contingencia.',
            ]);
        }

        if (! $eventCufd?->cufd_code) {
            throw ValidationException::withMessages([
                'started_at' => 'No existe un CUFD vigente para el punto de venta al inicio del evento. Registra el período real de la contingencia.',
            ]);
        }

        return $this->submit(
            $user,
            null,
            $apiToken,
            $authorization,
            $pointOfSale,
            $cuis,
            $currentCufd,
            $eventCufd,
            $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(User $user, SinInvoiceIssue $invoice, array $data): SinSignificantEvent
    {
        $invoice->loadMissing(['authorization', 'pointOfSale.branch', 'cuis', 'cufd', 'significantEvent']);

        if ($invoice->significantEvent && ! in_array($invoice->significantEvent->event_status, [
            SignificantEventStatus::Completed,
            SignificantEventStatus::Expired,
        ], true)) {
            throw ValidationException::withMessages([
                'invoice' => "La factura ya pertenece al evento #{$invoice->significantEvent->id}. Registra ese evento desde el menú Contingencias; no debes crear otro.",
            ]);
        }

        if (! $invoice->allowsSignificantEvent()) {
            throw ValidationException::withMessages([
                'invoice' => 'El evento de contingencia solo puede registrarse para una factura que no pudo conectarse con el SIN.',
            ]);
        }

        if ($invoice->significantEvents()->where('transaccion', true)->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'Esta contingencia ya tiene un evento significativo registrado en el SIN.',
            ]);
        }

        $authorization = $invoice->authorization;
        $pointOfSale = $invoice->pointOfSale;
        $cuis = $invoice->cuis;
        $cufd = $invoice->cufd;
        $apiToken = SinApiToken::query()->find($invoice->sin_api_token_id);

        if (! $authorization || ! $pointOfSale?->branch || ! $cuis?->cuis_code || ! $cufd?->cufd_code || ! $apiToken) {
            throw ValidationException::withMessages([
                'configuration' => 'No se cuenta con toda la configuracion fiscal usada al emitir la factura.',
            ]);
        }

        return $this->submit($user, $invoice, $apiToken, $authorization, $pointOfSale, $cuis, $cufd, $cufd, $data);
    }

    /** @param array<string, mixed> $data */
    private function submit(
        User $user,
        ?SinInvoiceIssue $invoice,
        SinApiToken $apiToken,
        object $authorization,
        SinPointOfSale $pointOfSale,
        object $cuis,
        SinCufd $currentCufd,
        SinCufd $eventCufd,
        array $data,
    ): SinSignificantEvent {
        $timezone = config('app.timezone', 'America/La_Paz');
        $startedAt = Carbon::parse((string) $data['started_at'])->setTimezone($timezone);
        $endedAt = Carbon::parse((string) $data['ended_at'])->setTimezone($timezone);

        $payload = [
            'SolicitudEventoSignificativo' => [
                'codigoAmbiente' => $authorization->environment_code->value,
                'codigoMotivoEvento' => (int) $data['event_code'],
                'codigoPuntoVenta' => $pointOfSale->point_of_sale_code,
                'codigoSistema' => (string) $authorization->system_code,
                'codigoSucursal' => $pointOfSale->branch->branch_code,
                'cufd' => (string) $currentCufd->cufd_code,
                'cufdEvento' => (string) $eventCufd->cufd_code,
                'cuis' => (string) $cuis->cuis_code,
                'descripcion' => trim((string) $data['description']),
                'fechaHoraFinEvento' => SiatDateTime::extended($endedAt),
                'fechaHoraInicioEvento' => SiatDateTime::extended($startedAt),
                'nit' => $authorization->tax_id,
            ],
        ];

        $event = SinSignificantEvent::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'sin_invoice_issue_id' => $invoice?->id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'sin_cuis_id' => $cuis->id,
            'sin_cufd_id' => $eventCufd->id,
            'recovery_sin_cufd_id' => $currentCufd->id,
            'event_code' => (int) $data['event_code'],
            'event_description' => trim((string) $data['description']),
            'event_status' => SignificantEventStatus::PendingRegistration,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'detected_at' => now(),
            'recovery_detected_at' => $endedAt,
            'updated_by_user_id' => $user->id,
            'request_payload' => $payload,
        ]);

        $startedAt = microtime(true);

        try {
            $operationsWsdl = SiatWsdlRegistry::relatedService(
                (string) $apiToken->wsdl_url,
                'FacturacionOperaciones',
            );
            $client = $this->clients->make($operationsWsdl, (string) $apiToken->api_token, 30);
            $response = $this->normalize($client->registroEventoSignificativo($payload));
            $transaccion = $this->findBoolean($response, 'transaccion') ?? false;
            $receptionCode = $this->findValue($response, ['codigoRecepcionEventoSignificativo', 'codigoRecepcionEvento', 'codigoRecepcion']);
            $accepted = $transaccion && filled($receptionCode);
            $message = $this->findValue($response, ['descripcion', 'mensaje', 'descripcionMensaje'])
                ?: match (true) {
                    $accepted => 'Evento significativo registrado en el SIN.',
                    $transaccion => 'El SIN confirmó la transacción, pero no devolvió el código de recepción del evento.',
                    default => 'El SIN rechazó el registro del evento significativo.',
                };

            $event->update([
                'event_status' => $accepted
                    ? SignificantEventStatus::Registered
                    : SignificantEventStatus::Failed,
                'reception_code' => $accepted ? $receptionCode : null,
                'transaccion' => $accepted,
                'status_label' => $accepted ? 'Registrado' : 'Rechazado',
                'response' => $response,
                'message' => $message,
                'duration_ms' => $this->durationMs($startedAt),
                'registered_at' => $accepted ? now() : null,
                'registered_by_user_id' => $accepted ? $user->id : null,
            ]);
        } catch (Throwable $exception) {
            $event->update([
                'event_status' => SignificantEventStatus::Failed,
                'status_label' => 'Error',
                'message' => 'No se pudo registrar el evento en el SIN: '.Str::limit($exception->getMessage(), 280),
                'duration_ms' => $this->durationMs($startedAt),
            ]);
        }

        return $event->refresh();
    }

    private function cufdForEvent(SinPointOfSale $pointOfSale, string $startedAt): ?SinCufd
    {
        $eventStart = Carbon::parse($startedAt)->setTimezone(config('app.timezone', 'America/La_Paz'));
        $eventStartValue = $eventStart->format('Y-m-d H:i:s');

        return SinCufd::query()
            ->usable()
            ->where('sin_point_of_sale_id', $pointOfSale->id)
            ->where('requested_at', '<=', $eventStartValue)
            ->where('expires_at', '>', $eventStartValue)
            ->latest('requested_at')
            ->first();
    }

    public function pendingOfflineEvent(SinPointOfSale $pointOfSale): ?SinSignificantEvent
    {
        return SinSignificantEvent::query()
            ->where('sin_point_of_sale_id', $pointOfSale->id)
            ->whereNotIn('event_status', [SignificantEventStatus::Completed, SignificantEventStatus::Expired])
            ->whereHas('invoiceIssues', fn ($query) => $query
                ->where('emission_mode', InvoiceEmissionMode::OfflineDigital)
                ->whereIn('fiscal_status', [InvoiceFiscalStatus::OfflineIssued, InvoiceFiscalStatus::PendingPackage])
                ->whereNotNull('xml_path')
                ->whereNotNull('cuf')
                ->whereDoesntHave('packageItem'))
            ->latest('started_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function normalize(mixed $response): array
    {
        $data = json_decode((string) json_encode($response), true);

        return is_array($data) ? $data : ['value' => $response];
    }

    /** @param array<int, string> $keys */
    private function findValue(array $data, array $keys): ?string
    {
        foreach ($data as $key => $value) {
            if (in_array($key, $keys, true) && is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }

            if (is_array($value) && ($found = $this->findValue($value, $keys)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private function findBoolean(array $data, string $key): ?bool
    {
        foreach ($data as $currentKey => $value) {
            if ($currentKey === $key) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            if (is_array($value) && ($found = $this->findBoolean($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
