<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        return [
            'offer_id'         => Offer::factory(),
            'client_reference' => 'ref-' . fake()->unique()->uuid(),
            'customer_name'    => fake()->name(),
            'customer_email'   => fake()->safeEmail(),
        ];
    }
}