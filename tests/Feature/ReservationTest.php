<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SupplierSeeder::class);
    }

    private function makeOffer(int $units = 1, ?string $expiresAt = null): Offer
    {
        $property = Property::factory()->create();
        $supplier = Supplier::where('slug', 'supplier-a')->first();

        return Offer::factory()->create([
            'supplier_id'     => $supplier->id,
            'property_id'     => $property->id,
            'available_units' => $units,
            'expires_at'      => $expiresAt ? now()->parse($expiresAt) : now()->addDays(7),
        ]);
    }

    private function payload(string $ref = 'web-order-9f782b1c'): array
    {
        return [
            'client_reference' => $ref,
            'customer_name'    => 'John Smith',
            'customer_email'   => 'john@example.com',
        ];
    }

    public function test_it_creates_a_reservation_and_returns_201(): void
    {
        $offer = $this->makeOffer(units: 2);

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.client_reference', 'web-order-9f782b1c')
            ->assertJsonPath('data.customer_email', 'john@example.com');

        $this->assertSame(1, $offer->fresh()->available_units);
    }

    public function test_same_client_reference_is_idempotent(): void
    {
        $offer = $this->makeOffer(units: 5);

        $first  = $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())->json('data.id');
        $second = $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(4, $offer->fresh()->available_units); // only decremented once
        $this->assertSame(1, Reservation::count());
    }

    public function test_it_rejects_when_no_units_remain(): void
    {
        $offer = $this->makeOffer(units: 0);

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(422);
    }

    public function test_it_rejects_expired_offers(): void
    {
        $offer = $this->makeOffer(units: 1, expiresAt: '-1 day');

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(422);
    }

    public function test_it_returns_404_for_unknown_offer(): void
    {
        $this->postJson('/api/offers/99999/reservations', $this->payload())
            ->assertNotFound();
    }

    public function test_validation_errors_are_returned_for_bad_payload(): void
    {
        $offer = $this->makeOffer();

        $this->postJson("/api/offers/{$offer->id}/reservations", [
            'client_reference' => '',
            'customer_name'    => '',
            'customer_email'   => 'not-an-email',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['client_reference', 'customer_name', 'customer_email']);
    }
}