<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Vendor::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:150',
            'contact_person' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email',
            'address'        => 'nullable|string',
            'is_active'      => 'boolean',
            'notes'          => 'nullable|string',
        ]);

        return response()->json(Vendor::create($data), 201);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        return response()->json($vendor->load('purchaseOrders'));
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'string|max:150',
            'contact_person' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email',
            'address'        => 'nullable|string',
            'is_active'      => 'boolean',
            'notes'          => 'nullable|string',
        ]);
        $vendor->update($data);

        return response()->json($vendor);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        $vendor->delete();
        return response()->json(null, 204);
    }
}
