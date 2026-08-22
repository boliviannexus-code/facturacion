<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\SinInvoiceIssue;
use App\Services\Billing\Contracts\InvoiceSiatClient;
use App\Services\Billing\InvoiceSiatResponse;
use RuntimeException;
use Throwable;

final class SequenceInvoiceSiatClient implements InvoiceSiatClient
{
    public int $calls = 0;

    /** @param array<int, InvoiceSiatResponse|Throwable> $responses */
    public function __construct(private array $responses) {}

    public function send(SinInvoiceIssue $invoice, string $compressedXml): InvoiceSiatResponse
    {
        $response = $this->responses[$this->calls] ?? new RuntimeException('No existe respuesta de factura simulada.');
        $this->calls++;

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
