<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SupplierSeeder::class);
    }

    private function makeOffer(array $overrides = []): Offer
    {
        $property = Property::factory()->create([
            'code' => $overrides['property_code'] ?? 'BCN-0001',
            'city' => $overrides['city'] ?? 'Barcelona',
        ]);

        $supplier = Supplier::where('slug', $overrides['supplier'] ?? 'supplier-a')->first();

        return Offer::factory()->create(array_merge([
            'supplier_id'     => $supplier->id,
            'property_id'     => $property->id,
            'check_in'        => '2026-10-10',
            'check_out'       => '2026-10-15',
            'max_guests'      => 4,
            'price'           => 50000,
            'available_units' => 1,
            'expires_at'      => now()->addDays(7),
        ], array_diff_key($overrides, array_flip([
            'property_code', 'city', 'supplier',
        ]))));
    }

    public function test_it_returns_cheapest_offer_per_property(): void
    {
        $property = Property::factory()->create(['code' => 'BCN-0001', 'city' => 'Barcelona']);
        $supplierA = Supplier::where('slug', 'supplier-a')->first();
        $supplierB = Supplier::where('slug', 'supplier-b')->first();

        Offer::factory()->create([
            'supplier_id' => $supplierA->id, 'property_id' => $property->id,
            'check_in' => '2026-10-10', 'check_out' => '2026-10-15',
            'max_guests' => 4, 'price' => 70000, 'available_units' => 1,
            'expires_at' => now()->addDays(7),
        ]);
        Offer::factory()->create([
            'supplier_id' => $supplierB->id, 'property_id' => $property->id,
            'check_in' => '2026-10-10', 'check_out' => '2026-10-15',
            'max_guests' => 4, 'price' => 55000, 'available_units' => 2,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2');

        $response->assertOk()
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.0.best_offer.price', 55000)
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-b');
    }

    public function test_it_excludes_expired_offers(): void
    {
        $this->makeOffer(['expires_at' => now()->subDay()]);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_excludes_offers_without_available_units(): void
    {
        $this->makeOffer(['available_units' => 0]);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_excludes_offers_below_guest_count(): void
    {
        $this->makeOffer(['max_guests' => 2]);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=4')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_filters_by_city(): void
    {
        $this->makeOffer(['city' => 'Barcelona', 'property_code' => 'BCN-0001']);
        $this->makeOffer(['city' => 'Madrid',    'property_code' => 'MAD-0001']);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.city', 'Barcelona');
    }

    public function test_pagination_includes_next_prev_per_page(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeOffer(['property_code' => 'BCN-000' . $i]);
        }

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('per_page', 2)
            ->assertJsonStructure(['data', 'next', 'prev', 'per_page']);
    }
}