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

    private function makeProperty(string $code = 'BCN-0001', string $city = 'Barcelona'): Property
    {
        return Property::create([
            'code' => $code,
            'name' => 'Test Property ' . $code,
            'city' => $city,
        ]);
    }

    /**
     * Supported overrides: supplier, price, max_guests, available_units,
     *                      expires_at, check_in, check_out
     */
    private function makeOffer(Property $property, array $overrides = []): Offer
    {
        $supplierSlug = $overrides['supplier'] ?? 'supplier-a';
        $supplier     = Supplier::where('slug', $supplierSlug)->firstOrFail();

        unset($overrides['supplier']);

        return Offer::create(array_merge([
            'supplier_id'     => $supplier->id,
            'property_id'     => $property->id,
            'import_id'       => null,
            'external_id'     => 'offer-' . uniqid(),
            'check_in'        => '2026-10-10',
            'check_out'       => '2026-10-15',
            'max_guests'      => 4,
            'price'           => 50000,
            'currency'        => 'EUR',
            'available_units' => 1,
            'expires_at'      => now()->addDays(7),
        ], $overrides));
    }

    public function test_it_returns_cheapest_offer_per_property(): void
    {
        $property = $this->makeProperty('BCN-0001', 'Barcelona');

        $this->makeOffer($property, ['supplier' => 'supplier-a', 'price' => 70000]);
        $this->makeOffer($property, ['supplier' => 'supplier-b', 'price' => 55000]);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.0.best_offer.price', 55000)
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-b');
    }

    public function test_it_excludes_expired_offers(): void
    {
        $property = $this->makeProperty();
        $this->makeOffer($property, ['expires_at' => now()->subDay()]);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_excludes_offers_without_available_units(): void
    {
        $property = $this->makeProperty();
        $this->makeOffer($property, ['available_units' => 0]);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_excludes_offers_below_guest_count(): void
    {
        $property = $this->makeProperty();
        $this->makeOffer($property, ['max_guests' => 2]);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=4')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_filters_by_city(): void
    {
        $bcn = $this->makeProperty('BCN-0001', 'Barcelona');
        $mad = $this->makeProperty('MAD-0001', 'Madrid');

        $this->makeOffer($bcn);
        $this->makeOffer($mad);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.city', 'Barcelona');
    }

    public function test_pagination_includes_next_prev_per_page(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $property = $this->makeProperty('BCN-000' . $i, 'Barcelona');
            $this->makeOffer($property);
        }

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('per_page', 2)
            ->assertJsonStructure(['data', 'next', 'prev', 'per_page']);
    }
}