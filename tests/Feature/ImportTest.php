<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $externalImportId = 'import-2026-09-01-001'): array
    {
        return [
            'supplier'           => 'supplier-a',
            'external_import_id' => $externalImportId,
            'sent_at'            => '2026-09-01T10:00:00Z',
            'offers' => [
                [
                    'external_id' => 'offer-a-10001',
                    'property' => [
                        'code' => 'BCN-0001',
                        'name' => 'Apartment near Sagrada Familia',
                        'city' => 'Barcelona',
                    ],
                    'check_in'        => '2026-10-10',
                    'check_out'       => '2026-10-15',
                    'max_guests'      => 4,
                    'price'           => 72500,
                    'currency'        => 'EUR',
                    'available_units' => 2,
                    'expires_at'      => '2026-12-31T23:59:59Z',
                ],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SupplierSeeder::class);
    }

    public function test_it_accepts_an_import_and_returns_202_pending(): void
    {
        $response = $this->postJson('/api/imports', $this->payload());

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['id', 'status']]);

        $this->assertDatabaseCount('imports', 1);
    }

    public function test_it_rejects_an_unknown_supplier(): void
    {
        $payload = $this->payload();
        $payload['supplier'] = 'does-not-exist';

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier');
    }

    public function test_reposting_the_same_import_does_not_create_a_duplicate(): void
    {
        $first  = $this->postJson('/api/imports', $this->payload())->json('data.id');
        $second = $this->postJson('/api/imports', $this->payload())->json('data.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('imports', 1);
    }

    public function test_the_job_creates_property_and_offer_records(): void
    {
        $this->postJson('/api/imports', $this->payload())->assertStatus(202);

        $this->assertDatabaseHas('properties', ['code' => 'BCN-0001', 'city' => 'Barcelona']);
        $this->assertDatabaseHas('offers', ['external_id' => 'offer-a-10001', 'price' => 72500]);

        $import = Import::first();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(1, $import->total_offers);
        $this->assertSame(1, $import->processed_offers);
        $this->assertNotNull($import->completed_at);
    }

    public function test_reimporting_the_same_offer_updates_instead_of_duplicating(): void
    {
        $this->postJson('/api/imports', $this->payload())->assertStatus(202);

        $payload = $this->payload('import-2026-09-01-002');
        $payload['offers'][0]['price'] = 60000;

        $this->postJson('/api/imports', $payload)->assertStatus(202);

        $this->assertSame(1, Offer::where('external_id', 'offer-a-10001')->count());
        $this->assertSame(60000, Offer::where('external_id', 'offer-a-10001')->first()->price);
    }

    public function test_status_endpoint_reports_import_state(): void
    {
        $id = $this->postJson('/api/imports', $this->payload())->json('data.id');

        $this->getJson("/api/imports/{$id}")
            ->assertOk()
            ->assertJsonPath('data.supplier', 'supplier-a')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_offers', 1)
            ->assertJsonPath('data.processed_offers', 1)
            ->assertJsonPath('data.error', null);
    }

    public function test_status_endpoint_returns_404_for_unknown_id(): void
    {
        $this->getJson('/api/imports/99999')->assertNotFound();
    }
}