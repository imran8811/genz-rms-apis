<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(): JsonResponse
    {
        $items = InventoryItem::where('is_active', true)->orderBy('name')->get();
        $items->each(fn ($i) => $i->append('status'));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:150',
            'unit'          => 'string|max:20',
            'current_stock' => 'numeric|min:0',
            'min_stock'     => 'numeric|min:0',
            'cost_per_unit' => 'integer|min:0',
        ]);

        return response()->json(InventoryItem::create($data), 201);
    }

    public function update(Request $request, InventoryItem $item): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'string|max:150',
            'unit'          => 'string|max:20',
            'min_stock'     => 'numeric|min:0',
            'cost_per_unit' => 'integer|min:0',
            'is_active'     => 'boolean',
        ]);
        $item->update($data);

        return response()->json($item->append('status'));
    }

    public function destroy(InventoryItem $item): JsonResponse
    {
        $item->update(['is_active' => false]);
        return response()->json(null, 204);
    }

    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'type'              => 'required|in:in,out,adjustment',
            'quantity'          => 'required|numeric|min:0.01',
            'note'              => 'nullable|string',
            'reference'         => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data) {
            $item = InventoryItem::lockForUpdate()->find($data['inventory_item_id']);

            $newStock = match ($data['type']) {
                'in'         => $item->current_stock + $data['quantity'],
                'out'        => max(0, $item->current_stock - $data['quantity']),
                'adjustment' => $data['quantity'],
            };

            $item->update(['current_stock' => $newStock]);

            StockMovement::create([
                'inventory_item_id' => $item->id,
                'type'              => $data['type'],
                'quantity'          => $data['quantity'],
                'stock_after'       => $newStock,
                'reference'         => $data['reference'] ?? null,
                'note'              => $data['note'] ?? null,
            ]);

            return response()->json($item->append('status'));
        });
    }

    public function movements(Request $request): JsonResponse
    {
        $query = StockMovement::with('inventoryItem')->latest();

        if ($request->has('inventory_item_id')) {
            $query->where('inventory_item_id', $request->inventory_item_id);
        }

        return response()->json($query->paginate(50));
    }
}
