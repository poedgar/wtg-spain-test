<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReservationController extends Controller
{
    public function store(StoreReservationRequest $request, Offer $offer): JsonResponse
    {
        $reservation = DB::transaction(function () use ($request, $offer) {

            // Lock the offer row for the duration of this transaction.
            /** @var Offer|null $locked */
            $locked = Offer::whereKey($offer->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new NotFoundHttpException('Offer not found.');
            }

            // Idempotency: same client_reference returns the existing reservation.
            $existing = Reservation::where('client_reference', $request->input('client_reference'))->first();
            if ($existing) {
                return $existing;
            }

            if ($locked->expires_at->isPast()) {
                throw new HttpException(422, 'Offer has expired.');
            }

            if ($locked->available_units < 1) {
                throw new HttpException(422, 'No units available for this offer.');
            }

            $locked->decrement('available_units');

            return Reservation::create([
                'offer_id'         => $locked->id,
                'client_reference' => $request->input('client_reference'),
                'customer_name'    => $request->input('customer_name'),
                'customer_email'   => $request->input('customer_email'),
            ]);
        });

        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode(201);
    }
}