<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\SinInvoicePackage;
use App\Services\Billing\Packages\Contracts\InvoicePackageSiatClient;
use App\Services\Billing\Packages\PackageReceptionResult;
use App\Services\Billing\Packages\PackageValidationResult;
use RuntimeException;
use Throwable;

final class SequenceInvoicePackageSiatClient implements InvoicePackageSiatClient
{
    public int $sendCalls = 0;

    public int $validationCalls = 0;

    /** @var array<int, array{package_id: int, archive: string}> */
    public array $sentArchives = [];

    /**
     * @param  array<int, PackageReceptionResult|Throwable>  $sendResults
     * @param  array<int, PackageValidationResult|Throwable>  $validationResults
     */
    public function __construct(
        private array $sendResults = [],
        private array $validationResults = [],
    ) {}

    public function send(SinInvoicePackage $package, string $archive): PackageReceptionResult
    {
        $this->sendCalls++;
        $this->sentArchives[] = ['package_id' => (int) $package->id, 'archive' => $archive];
        $result = array_shift($this->sendResults)
            ?? throw new RuntimeException('No existe una respuesta de envio simulada.');

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function checkValidation(SinInvoicePackage $package): PackageValidationResult
    {
        $this->validationCalls++;
        $result = array_shift($this->validationResults)
            ?? throw new RuntimeException('No existe una respuesta de validacion simulada.');

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
