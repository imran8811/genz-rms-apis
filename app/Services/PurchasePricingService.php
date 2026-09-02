<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Purchase;

/**
 * Turns a purchase (recorded in whole packs) into the per-gram figure costing needs.
 *
 * There are two kinds of unit and only one of them is globally convertible:
 *
 *  - **Measurement units** (g, kg, ml, l, pc) convert by fixed factors, so a
 *    rate quoted against one converts directly: Rs 80/kg ÷ 1000 = Rs 0.08/g.
 *  - **Pack units** (bottle, packet, shashay, carton) do not — "1 bottle = 950 g"
 *    is per-ingredient data. The bridge is the ingredient's predefined
 *    `pack_size`, set once when the item is added and never changed by a
 *    purchase: Rs 1200/bottle ÷ 950 g = Rs 1.2632/g.
 *
 * `quantity` deliberately does not affect the rate — buying 2 bottles instead of
 * 1 changes the bill, not the cost of a gram. It only drives `total_amount` and
 * `base_qty` (how much stock arrived).
 *
 * Costing reads InventoryItem::getUnitCostAttribute(), so re-pricing the item
 * here re-prices every recipe that uses it, and the Purchasing "Items" tab
 * (same records, read via /costing/ingredients) reflects it too. Policy is
 * latest-purchase-wins: the most recent purchase sets the price.
 */
class PurchasePricingService
{
    public function __construct(private CostingService $costing) {}

    /**
     * Cost of one base unit (g/ml/pc) implied by buying one `$unit` at `$rate`.
     * Returns null when the conversion is unknowable — a pack unit on an item
     * with no predefined pack size — so the caller can reject the purchase
     * instead of storing a silently wrong cost.
     */
    public function costPerBaseUnit(float $rate, ?string $unit, InventoryItem $item): ?float
    {
        $baseUnit = $item->base_unit ?: $item->unit;

        // Rate quoted against a measurement unit in the same family as the base
        // unit (kg → g, l → ml, pcs → pc): convert directly, pack size is irrelevant.
        if ($this->costing->isMeasurementUnit($unit)) {
            $perUnit = $this->costing->convert(1, $unit, $baseUnit);
            if ($perUnit !== null && $perUnit > 0) {
                return round($rate / $perUnit, 4);
            }
        }

        // Otherwise the rate is per pack — divide by the predefined pack size.
        $packSize = (float) ($item->pack_size ?? 0);

        return $packSize > 0 ? round($rate / $packSize, 4) : null;
    }

    /**
     * How much stock a purchase brought in, in the ingredient's base unit.
     * Mirrors costPerBaseUnit()'s two cases. Null when unknowable.
     */
    public function baseQty(float $quantity, ?string $unit, InventoryItem $item): ?float
    {
        $baseUnit = $item->base_unit ?: $item->unit;

        if ($this->costing->isMeasurementUnit($unit)) {
            $converted = $this->costing->convert($quantity, $unit, $baseUnit);
            if ($converted !== null) {
                return round($converted, 3);
            }
        }

        $packSize = (float) ($item->pack_size ?? 0);

        return $packSize > 0 ? round($quantity * $packSize, 3) : null;
    }

    /** Resolve the ingredient a purchase belongs to (FK first, then by name). */
    public function resolveItem(Purchase $purchase): ?InventoryItem
    {
        if ($purchase->inventory_item_id) {
            return $purchase->inventoryItem;
        }

        $name = trim((string) $purchase->item_name);

        return $name === ''
            ? null
            : InventoryItem::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
    }

    /**
     * Re-price the ingredient behind $purchase from the latest purchase recorded
     * for it. No-op when there's no matching ingredient or no purchases remain.
     */
    public function syncFromLatestPurchase(?InventoryItem $item): void
    {
        if (! $item) {
            return;
        }

        $latest = Purchase::query()
            ->where(function ($q) use ($item) {
                $q->where('inventory_item_id', $item->id)
                    ->orWhere(function ($q2) use ($item) {
                        $q2->whereNull('inventory_item_id')
                            ->whereRaw('LOWER(item_name) = ?', [mb_strtolower($item->name)]);
                    });
            })
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (! $latest) {
            return; // last purchase for this item was deleted — leave price as-is
        }

        $costPerBaseUnit = $latest->cost_per_base_unit !== null
            ? (float) $latest->cost_per_base_unit
            : $this->costPerBaseUnit((float) $latest->rate, $latest->unit, $item);

        if ($costPerBaseUnit === null) {
            return; // no pack size defined — nothing trustworthy to write
        }

        // pack_price stays "the price of one predefined pack" so the Items tab
        // keeps showing what a pack costs; unit_cost derives from it as before.
        $item->update([
            'pack_price' => round($costPerBaseUnit * (float) ($item->pack_size ?: 1), 2),
            'cost_per_base_unit' => $costPerBaseUnit,
            'cost_per_unit' => (int) round($costPerBaseUnit),
        ]);
    }

    /** Re-price by ingredient name — used when a purchase is moved off an item. */
    public function syncByName(?string $itemName): void
    {
        $name = trim((string) $itemName);
        if ($name === '') {
            return;
        }

        $this->syncFromLatestPurchase(
            InventoryItem::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
        );
    }
}
