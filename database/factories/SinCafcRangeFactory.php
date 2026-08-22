<?php

namespace Database\Factories;

use App\Enums\CafcRangeStatus;
use App\Models\Company;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Models\SinPointOfSale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SinCafcRange> */
class SinCafcRangeFactory extends Factory
{
    protected $model = SinCafcRange::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'sin_branch_id' => fn (array $a) => SinBranch::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_point_of_sale_id' => fn (array $a) => SinPointOfSale::factory()->create(['company_id' => $a['company_id'], 'sin_branch_id' => $a['sin_branch_id']])->id,
            'created_by_user_id' => fn (array $a) => User::factory()->create(['company_id' => $a['company_id']])->id,
            'cafc_code' => strtoupper(fake()->unique()->bothify('CAFC-????-########')),
            'document_sector_code' => 1,
            'range_start' => 1,
            'range_end' => 100,
            'next_number' => 1,
            'range_status' => CafcRangeStatus::Available,
            'used_count' => 0,
            'cancelled_count' => 0,
            'authorized_from' => today(),
            'authorized_until' => today()->addMonth(),
        ];
    }
}
