<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRequest;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class ImportController extends Controller
{
    public function store(StoreImportRequest $request): JsonResponse
    {
        $supplier = Supplier::where('slug', $request->input('supplier'))->firstOrFail();

        $import = Import::firstOrCreate(
            [
                'supplier_id'        => $supplier->id,
                'external_import_id' => $request->input('external_import_id'),
            ],
            [
                'sent_at'      => $request->input('sent_at'),
                'status'       => Import::STATUS_PENDING,
                'total_offers' => count($request->input('offers')),
            ],
        );

        // Job dispatch happens in Commit 7.

        return response()->json([
            'data' => [
                'id'     => $import->id,
                'status' => $import->status,
            ],
        ], 202);
    }
}