<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SiatAlertSeverity;
use App\Enums\SiatAlertStatus;
use App\Enums\SiatAlertType;
use App\Models\Company;
use App\Models\SinMonitoringAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SinMonitoringAlert> */
final class SinMonitoringAlertFactory extends Factory
{
    protected $model = SinMonitoringAlert::class;

    public function definition(): array
    {
        $key = hash('sha256', fake()->uuid());

        return [
            'company_id' => Company::factory(),
            'alert_type' => SiatAlertType::InvoicesPendingSend,
            'severity' => SiatAlertSeverity::Warning,
            'alert_status' => SiatAlertStatus::Active,
            'condition_key' => $key,
            'active_key' => $key,
            'title' => 'Facturas pendientes de envío',
            'message' => 'Existen facturas pendientes de regularización.',
            'condition_count' => 1,
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'panel_recorded_at' => now(),
        ];
    }
}
