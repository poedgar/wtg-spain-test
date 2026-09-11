<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $importId,
    ) {}

    public function handle(): void
    {
        $import = Import::find($this->importId);

        if (! $import) {
            return;
        }

        // Idempotency guard: if already completed, do nothing.
        if ($import->status === Import::STATUS_COMPLETED) {
            return;
        }

        $import->update([
            'status'     => Import::STATUS_PROCESSING,
            'error'      => null,
            'completed_at' => null,
        ]);

        try {
            $payload = $this->payloadFor($import);

            DB::transaction(function () use ($import, $payload) {
                $processed = 0;

                foreach ($payload['offers'] as $offerData) {
                    $property = Property::updateOrCreate(
                        ['code' => $offerData['property']['code']],
                        [
                            'name' => $offerData['property']['name'],
                            'city' => $offerData['property']['city'],
                        ],
                    );

                    Offer::updateOrCreate(
                        [
                            'supplier_id' => $import->supplier_id,
                            'external_id' => $offerData['external_id'],
                        ],
                        [
                            'property_id'     => $property->id,
                            'import_id'       => $import->id,
                            'check_in'        => $offerData['check_in'],
                            'check_out'       => $offerData['check_out'],
                            'max_guests'      => $offerData['max_guests'],
                            'price'           => $offerData['price'],
                            'currency'        => $offerData['currency'],
                            'available_units' => $offerData['available_units'],
                            'expires_at'      => $offerData['expires_at'],
                        ],
                    );

                    $processed++;
                    $import->update(['processed_offers' => $processed]);
                }
            });

            $import->update([
                'status'       => Import::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Import failed', [
                'import_id' => $import->id,
                'error'     => $e->getMessage(),
            ]);

            $import->update([
                'status' => Import::STATUS_FAILED,
                'error'  => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * The original payload is not stored in the DB, so we re-derive it
     * from whatever the controller staged on the Import record.
     * (See Step 3 — we add a `payload` column to make this explicit.)
     */
    protected function payloadFor(Import $import): array
    {
        return $import->payload ?? ['offers' => []];
    }
}