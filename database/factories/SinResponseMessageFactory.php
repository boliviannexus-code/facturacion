<?php

namespace Database\Factories;

use App\Enums\SiatMessageSeverity;
use App\Models\SinResponseMessage;
use App\Models\SinSiatAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SinResponseMessage> */
class SinResponseMessageFactory extends Factory
{
    protected $model = SinResponseMessage::class;

    public function definition(): array
    {
        return [
            'sin_siat_attempt_id' => SinSiatAttempt::factory(),
            'company_id' => fn (array $a) => SinSiatAttempt::query()->findOrFail($a['sin_siat_attempt_id'])->company_id,
            'message_key' => hash('sha256', fake()->uuid()),
            'service' => 'RECEIVE_INVOICE',
            'message_code' => fake()->numerify('####'),
            'severity' => SiatMessageSeverity::Error,
            'description' => fake()->sentence(),
            'received_at' => now(),
        ];
    }
}
