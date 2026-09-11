<?php

namespace Database\Factories;

use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        $checkIn  = fake()->dateTimeBetween('+1 week', '+2 months');
        $checkOut = (clone $checkIn)->modify('+' . fake()->numberBetween(2, 10) . ' days');

        return [
            'supplier_id'     => Supplier::factory(),
            'property_id'     => Property::factory(),
            'import_id'       => null,
            'external_id'     => 'offer-' . fake()->unique()->numerify('#####'),
            'check_in'        => $checkIn->format('Y-m-d'),
            'check_out'       => $checkOut->format('Y-m-d'),
            'max_guests'      => fake()->numberBetween(1, 6),
            'price'           => fake()->numberBetween(20000, 200000),
            'currency'        => 'EUR',
            'available_units' => fake()->numberBetween(1, 5),
            'expires_at'      => now()->addDays(7),
        ];
    }
}