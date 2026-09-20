<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Services\PurchasePricingService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function __construct(
        private PurchasePricingService $pricing,
        private StockService $stock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Purchase::query();

        if ($request->has('date')) {
            $query->where('date', $request->date);
        } elseif ($request->has('month')) {
            [$year, $mon] = explode('-', $request->month);
            $query->whereYear('date', $year)->whereMonth('date', $mon);
        }

        return response()->json(
            $query->orderBy('date', 'desc')->orderBy('id', 'desc')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data = $this->withDerived($data);

        $purchase = Purchase::create($data);
        // Re-price the matching ingredient from this (now latest) purchase.
        $this->pricing->syncFromLatestPurchase($this->pricing->resolveItem($purchase));
        // …and, for an ingredient under stock control, put the delivery on the shelf.
        $this->stock->syncPurchase($purchase);

        return response()->json($purchase, 201);
    }

    public function update(Request $request, Purchase $purchase): JsonResponse
    {
        $data = $this->withDerived($this->validated($request));

        $previousItem = $this->pricing->resolveItem($purchase);
        $purchase->update($data);

        // Re-price the (possibly reassigned) ingredient from its latest purchase.
        // If the purchase was moved to a different item, refresh the old one too
        // so it falls back to whatever purchase is now latest for it.
        $item = $this->pricing->resolveItem($purchase->fresh());
        $this->pricing->syncFromLatestPurchase($item);
        if ($previousItem && $previousItem->id !== $item?->id) {
            $this->pricing->syncFromLatestPurchase($previousItem);
        }

        // Restates this purchase's delivery rather than adding a second one, and
        // takes it off the old shelf if the purchase was moved to another item.
        $this->stock->syncPurchase($purchase->fresh());

        return response()->json($purchase);
    }

    public function destroy(Purchase $purchase): JsonResponse
    {
        $item = $this->pricing->resolveItem($purchase);
        // Withdraw the delivery *before* the row goes, while it can still be found.
        $this->stock->forgetPurchase($purchase);
        $purchase->delete();
        // The ingredient now re-derives its price from its latest remaining purchase.
        $this->pricing->syncFromLatestPurchase($item);

        return response()->json(null, 204);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'date' => 'required|date',
            'item_name' => 'required|string|max:200',
            'inventory_item_id' => 'nullable|exists:inventory_items,id',
            'vendor_name' => 'required|string|max:200',
            'rate' => 'required|numeric|min:0',
            'quantity' => 'required|numeric|min:0.001',
            // Free-form: measurement units (g/kg/ml/l/pcs) convert globally, any
            // other value is treated as a pack unit resolved via pack_size.
            'unit' => 'nullable|string|max:50',
        ]);
    }

    /**
     * Fill in the figures costing depends on: the bill, how much stock arrived in
     * base units, and the per-gram cost this purchase implies.
     *
     * A pack unit on an ingredient with no predefined pack size is unresolvable —
     * reject it rather than store a cost that is wrong by a factor of the pack.
     */
    private function withDerived(array $data): array
    {
        $data['unit'] = $data['unit'] ?: 'pcs';
        $data['total_amount'] = round($data['rate'] * $data['quantity'], 2);

        $item = $this->resolveIngredient($data);
        if (! $item) {
            // Unknown ingredient: record the spend, but there is nothing to cost.
            return $data + ['inventory_item_id' => null, 'base_qty' => null, 'base_unit' => null, 'cost_per_base_unit' => null];
        }

        $costPerBaseUnit = $this->pricing->costPerBaseUnit((float) $data['rate'], $data['unit'], $item);
        if ($costPerBaseUnit === null) {
            throw ValidationException::withMessages([
                'unit' => sprintf(
                    '"%s" needs a pack size before it can be bought in %s — set how many %s are in one %s on the Items tab.',
                    $item->name,
                    $data['unit'],
                    $item->base_unit ?: $item->unit,
                    $data['unit'],
                ),
            ]);
        }

        $data['inventory_item_id'] = $item->id;
        $data['base_unit'] = $item->base_unit ?: $item->unit;
        $data['base_qty'] = $this->pricing->baseQty((float) $data['quantity'], $data['unit'], $item);
        $data['cost_per_base_unit'] = $costPerBaseUnit;

        return $data;
    }

    /** Prefer the explicit FK; fall back to matching the typed name. */
    private function resolveIngredient(array $data): ?InventoryItem
    {
        if (! empty($data['inventory_item_id'])) {
            return InventoryItem::find($data['inventory_item_id']);
        }

        return InventoryItem::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['item_name']))])->first();
    }
}
