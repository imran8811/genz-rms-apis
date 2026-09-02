<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::with(['vendor', 'items'])->latest();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'notes'     => 'nullable|string',
            'items'     => 'nullable|array',
            'items.*.item_name'  => 'required|string',
            'items.*.unit'       => 'nullable|string',
            'items.*.quantity'   => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($data) {
            $poNumber = 'PO-' . str_pad(PurchaseOrder::count() + 1, 4, '0', STR_PAD_LEFT);
            $po = PurchaseOrder::create([
                'po_number' => $poNumber,
                'vendor_id' => $data['vendor_id'],
                'notes'     => $data['notes'] ?? null,
                'status'    => 'Draft',
            ]);

            if (!empty($data['items'])) {
                $total = 0;
                foreach ($data['items'] as $item) {
                    $lineTotal = (int) ($item['quantity'] * $item['unit_price']);
                    $total += $lineTotal;
                    $po->items()->create([...$item, 'total' => $lineTotal]);
                }
                $po->update(['total_amount' => $total]);
            }

            return response()->json($po->load(['vendor', 'items']), 201);
        });
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json($purchaseOrder->load(['vendor', 'items']));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            'status'      => 'in:Draft,Ordered,Received,Cancelled',
            'notes'       => 'nullable|string',
            'ordered_at'  => 'nullable|date',
            'received_at' => 'nullable|date',
        ]);

        if ($data['status'] === 'Ordered' && !$purchaseOrder->ordered_at) {
            $data['ordered_at'] = now();
        }
        if ($data['status'] === 'Received' && !$purchaseOrder->received_at) {
            $data['received_at'] = now();
        }

        $purchaseOrder->update($data);
        return response()->json($purchaseOrder);
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->delete();
        return response()->json(null, 204);
    }
}
