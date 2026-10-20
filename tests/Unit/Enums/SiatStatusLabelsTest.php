<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoicePackageStatus;
use App\Enums\SiatAttemptStatus;
use App\Enums\SiatFailureCategory;
use App\Enums\SiatMessageSeverity;
use App\Enums\SiatOperation;
use App\Enums\SignificantEventStatus;
use PHPUnit\Framework\TestCase;

final class SiatStatusLabelsTest extends TestCase
{
    public function test_every_invoice_fiscal_status_has_a_spanish_label(): void
    {
        foreach (InvoiceFiscalStatus::cases() as $status) {
            self::assertNotSame(str_replace('_', ' ', $status->value), $status->label());
        }
    }

    public function test_every_siat_failure_category_has_a_spanish_label(): void
    {
        foreach (SiatFailureCategory::cases() as $category) {
            self::assertNotSame(str_replace('_', ' ', $category->value), $category->label());
        }
    }

    public function test_common_siat_states_are_clear_for_users(): void
    {
        self::assertSame('Validada por el SIN', InvoiceFiscalStatus::Validated->label());
        self::assertSame('Observada por el SIN', InvoiceFiscalStatus::Observed->label());
        self::assertSame('Rechazada por el SIN', InvoiceFiscalStatus::Rejected->label());
        self::assertSame('Error de comunicación con el SIN', SiatFailureCategory::Communication->label());
    }

    public function test_operational_siat_enums_have_labels(): void
    {
        $enumClasses = [
            InvoicePackageStatus::class,
            SiatAttemptStatus::class,
            SiatMessageSeverity::class,
            SiatOperation::class,
            SignificantEventStatus::class,
        ];

        foreach ($enumClasses as $enumClass) {
            foreach ($enumClass::cases() as $case) {
                self::assertNotSame(str_replace('_', ' ', $case->value), $case->label());
            }
        }
    }
}
