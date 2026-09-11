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
        $perPage = (int) $request->input('per_page', 15);

        $query = DB::table('properties')
            ->join('offers', 'offers.property_id', '=', 'properties.id')
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->where('offers.check_in', $request->input('check_in'))
            ->where('offers.check_out', $request->input('check_out'))
            ->where('offers.max_guests', '>=', $request->input('guests'))
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', now())
            ->when($request->filled('city'), fn ($q) =>
                $q->where('properties.city', $request->input('city'))
            )
            // Rank offers per property by price ascending
            ->selectRaw('properties.id as property_id')
            ->selectRaw('properties.code, properties.name, properties.city')
            ->selectRaw('offers.id as best_offer_id')
            ->selectRaw('suppliers.slug as best_offer_supplier_slug')
            ->selectRaw('offers.price as best_offer_price')
            ->selectRaw('offers.currency as best_offer_currency')
            ->selectRaw('offers.available_units as best_offer_available_units')
            ->selectRaw('offers.expires_at as best_offer_expires_at')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY properties.id ORDER BY offers.price ASC, offers.id ASC) as rn')
            ->orderBy('properties.code');

        // Wrap in a subquery so we can filter rn = 1 in SQL, not PHP
        $ranked = DB::query()->fromSub($query, 'ranked')
            ->where('rn', 1)
            ->orderBy('code');

        $paginator = $ranked->paginate($perPage);

        return new PropertyCollection($paginator);
    }
}