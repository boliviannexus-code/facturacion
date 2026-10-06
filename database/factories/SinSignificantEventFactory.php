<?php

namespace Database\Factories;

use App\Enums\SignificantEventStatus;
use App\Models\Company;
use App\Models\SinBranch;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SinSignificantEvent> */
class SinSignificantEventFactory extends Factory
{
    protected $model = SinSignificantEvent::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => fn (array $a) => User::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_branch_id' => fn (array $a) => SinBranch::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_point_of_sale_id' => fn (array $a) => SinPointOfSale::factory()->create(['company_id' => $a['company_id'], 'sin_branch_id' => $a['sin_branch_id']])->id,
            'sin_cuis_id' => fn (array $a) => SinCuis::factory()->create(['company_id' => $a['company_id'], 'sin_branch_id' => $a['sin_branch_id'], 'sin_point_of_sale_id' => $a['sin_point_of_sale_id']])->id,
            'sin_cufd_id' => fn (array $a) => SinCufd::factory()->create(['company_id' => $a['company_id'], 'sin_branch_id' => $a['sin_branch_id'], 'sin_point_of_sale_id' => $a['sin_point_of_sale_id'], 'sin_cuis_id' => $a['sin_cuis_id']])->id,
            'event_code' => 1,
            'event_description' => 'Contingencia de prueba',
            'event_status' => SignificantEventStatus::Open,
            'started_at' => now()->subHour(),
            'detected_at' => now()->subHour(),
            'transaccion' => false,
            'status_label' => 'Pendiente',
        ];
    }
}
