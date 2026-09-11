<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPropertiesRequest;
use App\Http\Resources\PropertyCollection;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    public function index(ListPropertiesRequest $request)
    {
        $perPage  = (int) $request->input('per_page', 15);
        $checkIn  = $request->input('check_in');
        $checkOut = $request->input('check_out');
        $guests   = (int) $request->input('guests');
        $now      = now();

        // Sub-query: cheapest valid offer price per property.
        $cheapestOfferId = DB::table('offers')
            ->select('offers.property_id', DB::raw('MIN(offers.price) as min_price'))
            ->whereDate('offers.check_in', $checkIn)
            ->whereDate('offers.check_out', $checkOut)
            ->where('offers.max_guests', '>=', $guests)
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', $now)
            ->groupBy('offers.property_id');

        $query = DB::table('properties')
            ->joinSub($cheapestOfferId, 'cheapest', function ($join) {
                $join->on('cheapest.property_id', '=', 'properties.id');
            })
            ->join('offers', function ($join) use ($checkIn, $checkOut, $guests, $now) {
                $join->on('offers.property_id', '=', 'properties.id')
                    ->on('offers.price', '=', 'cheapest.min_price')
                    ->whereDate('offers.check_in', $checkIn)
                    ->whereDate('offers.check_out', $checkOut)
                    ->where('offers.max_guests', '>=', $guests)
                    ->where('offers.available_units', '>', 0)
                    ->where('offers.expires_at', '>', $now);
            })
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->when($request->filled('city'), fn ($q) =>
                $q->where('properties.city', $request->input('city'))
            )
            ->select([
                'properties.code',
                'properties.name',
                'properties.city',
                'offers.id as best_offer_id',
                'suppliers.slug as best_offer_supplier_slug',
                'offers.price as best_offer_price',
                'offers.currency as best_offer_currency',
                'offers.available_units as best_offer_available_units',
                'offers.expires_at as best_offer_expires_at',
            ])
            ->orderBy('offers.id')
            ->orderBy('properties.code');

        $paginator = $query->paginate($perPage);

        return new PropertyCollection($paginator);
    }
}