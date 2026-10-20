<?php

namespace Database\Factories;

use App\Enums\SiatCommunicationOutcome;
use App\Enums\SiatErrorType;
use App\Enums\SiatOperation;
use App\Models\Company;
use App\Models\SinBranch;
use App\Models\SinCommunicationLog;
use App\Models\SinPointOfSale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SinCommunicationLog> */
class SinCommunicationLogFactory extends Factory
{
    protected $model = SinCommunicationLog::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'sin_branch_id' => fn (array $a) => SinBranch::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_point_of_sale_id' => fn (array $a) => SinPointOfSale::factory()->create(['company_id' => $a['company_id'], 'sin_branch_id' => $a['sin_branch_id']])->id,
            'user_id' => fn (array $a) => User::factory()->create(['company_id' => $a['company_id']])->id,
            'operation' => SiatOperation::VerifyCommunication,
            'outcome' => SiatCommunicationOutcome::Available,
            'error_type' => SiatErrorType::Available,
            'attempt_count' => 1,
            'duration_ms' => 50,
            'last_request_duration_ms' => 50,
            'was_retried' => false,
            'contingency_recommended' => false,
            'message' => 'Comprobacion de prueba.',
            'technical_message' => 'Respuesta simulada correcta.',
            'user_message' => 'La comunicacion con SIAT esta disponible.',
            'checked_at' => now(),
        ];
    }
}
