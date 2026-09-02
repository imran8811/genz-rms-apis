<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeLine;

/**
 * Pure costing engine. Every cost is DERIVED from current ingredient prices and
 * sub-recipe costs — nothing is read from a stored line total. Change an
 * ingredient's pack price and every recipe that uses it re-prices automatically.
 */
class CostingService
{
    /**
     * Conversion factors to a canonical base per unit family.
     *
     * These are *measurement* units — globally convertible. Pack units (bottle,
     * packet, shashay, carton) deliberately do NOT belong here: "1 bottle = 950 g"
     * is item-specific data and lives on `inventory_items.pack_size`.
     */
    private const FACTORS = [
        'kg' => ['base' => 'g',  'factor' => 1000],
        'g' => ['base' => 'g',  'factor' => 1],
        'l' => ['base' => 'ml', 'factor' => 1000],
        'ltrs' => ['base' => 'ml', 'factor' => 1000],
        'ml' => ['base' => 'ml', 'factor' => 1],
        'pc' => ['base' => 'pc', 'factor' => 1],
        'pcs' => ['base' => 'pc', 'factor' => 1],
    ];

    /** Spellings users type that mean the same measurement unit. */
    private const ALIASES = [
        'gram' => 'g',
        'grams' => 'g',
        'gm' => 'g',
        'gms' => 'g',
        'kgs' => 'kg',
        'ltr' => 'l',
        'litre' => 'l',
        'liter' => 'l',
        'litres' => 'l',
        'piece' => 'pc',
        'pieces' => 'pc',
    ];

    /** Recipe ids currently being costed — guards against sub-recipe cycles. */
    private array $stack = [];

    /** Lower-case a unit and fold known spellings onto their canonical form. */
    public function normalizeUnit(?string $unit): string
    {
        $u = strtolower(trim((string) $unit));

        return self::ALIASES[$u] ?? $u;
    }

    /**
     * True when the unit is a globally-convertible measurement unit (g/kg/ml/l/pc)
     * rather than an item-specific pack unit (bottle, packet, shashay).
     */
    public function isMeasurementUnit(?string $unit): bool
    {
        return isset(self::FACTORS[$this->normalizeUnit($unit)]);
    }

    /**
     * Convert `qty` expressed in `fromUnit` into `toUnit`. Returns null when the
     * units belong to different families (e.g. g → pc) so the caller can flag it.
     */
    public function convert(float $qty, ?string $fromUnit, ?string $toUnit): ?float
    {
        $from = $this->normalizeUnit($fromUnit);
        $to = $this->normalizeUnit($toUnit);

        if ($from === '' || $to === '' || $from === $to) {
            return $qty;
        }
        if (! isset(self::FACTORS[$from], self::FACTORS[$to])) {
            return $qty; // unknown unit — assume already comparable
        }
        if (self::FACTORS[$from]['base'] !== self::FACTORS[$to]['base']) {
            return null; // incompatible families
        }

        $inBase = $qty * self::FACTORS[$from]['factor'];

        return $inBase / self::FACTORS[$to]['factor'];
    }

    /** Cost of a single recipe line, fully derived. */
    public function lineCost(RecipeLine $line): array
    {
        $unitCost = 0.0;
        $note = null;
        $qtyInComponentUnit = (float) $line->qty;

        switch ($line->component_type) {
            case 'overhead':
                // Flat per-item cost (gas, electricity, labour).
                $cost = (float) ($line->flat_cost ?? 0);

                return [
                    'unit_cost' => null,
                    'qty' => null,
                    'line_cost' => round($cost, 2),
                    'note' => $note,
                ];

            case 'ingredient':
                $item = $line->inventoryItem;
                if (! $item) {
                    $note = 'missing ingredient';
                    break;
                }
                $unitCost = $item->unit_cost; // per base_unit (g/ml/pc)
                $converted = $this->convert((float) $line->qty, $line->unit, $item->base_unit ?: $item->pack_unit);
                if ($converted === null) {
                    $note = "unit mismatch ({$line->unit} vs {$item->base_unit})";
                    $converted = (float) $line->qty;
                }
                $qtyInComponentUnit = $converted;
                break;

            case 'sub_recipe':
                $sub = $line->subRecipe;
                if (! $sub) {
                    $note = 'missing sub-recipe';
                    break;
                }
                if ($sub->yield_qty && $sub->yield_qty > 0) {
                    // Batch sub-recipe (dough, sauce): cost per yield unit × qty.
                    $unitCost = $this->subRecipeUnitCost($sub);   // per yield_unit
                    $converted = $this->convert((float) $line->qty, $line->unit, $sub->yield_unit);
                    if ($converted === null) {
                        $note = "unit mismatch ({$line->unit} vs {$sub->yield_unit})";
                        $converted = (float) $line->qty;
                    }
                    $qtyInComponentUnit = $converted;
                } else {
                    // A whole finished recipe used as a component (e.g. a pizza
                    // inside a deal): cost the full recipe × the count (qty).
                    $unitCost = $this->totalCost($sub);
                    $qtyInComponentUnit = (float) ($line->qty ?: 1);
                }
                break;
        }

        $waste = 1 + ((float) $line->waste_pct / 100);
        $cost = $unitCost * $qtyInComponentUnit * $waste;

        return [
            'unit_cost' => round($unitCost, 4),
            'qty' => round($qtyInComponentUnit, 3),
            'line_cost' => round($cost, 2),
            'note' => $note,
        ];
    }

    /** Per-unit cost of a sub-recipe = batch total / batch yield. */
    public function subRecipeUnitCost(Recipe $recipe): float
    {
        $total = $this->totalCost($recipe);
        $yield = (float) ($recipe->yield_qty ?: 0);

        return $yield > 0 ? round($total / $yield, 4) : 0.0;
    }

    /** Sum of all line costs for a recipe (recurses into sub-recipes safely). */
    public function totalCost(Recipe $recipe): float
    {
        if (in_array($recipe->id, $this->stack, true)) {
            return 0.0; // cycle — bail out
        }
        $this->stack[] = $recipe->id;

        $recipe->loadMissing('lines.inventoryItem', 'lines.subRecipe');
        $total = 0.0;
        foreach ($recipe->lines as $line) {
            $total += $this->lineCost($line)['line_cost'];
        }

        array_pop($this->stack);

        return round($total, 2);
    }

    /**
     * Effective sell price: prefer the linked menu item's live price (so margin
     * tracks the real menu), falling back to the recipe's own sell_price.
     * Returns [price, source] where source is 'menu' or 'manual'.
     */
    public function resolveSellPrice(Recipe $recipe): array
    {
        $mi = $recipe->menuItem;
        if ($mi) {
            if ($mi->price_type === 'sized' && $recipe->variant && is_array($mi->prices)) {
                $p = $mi->prices[$recipe->variant] ?? null;
                if ($p !== null) {
                    return [(float) $p, 'menu'];
                }
            } elseif ($mi->price_type !== 'sized' && $mi->price !== null) {
                return [(float) $mi->price, 'menu'];
            }
        }

        return [$recipe->sell_price !== null ? (float) $recipe->sell_price : null, 'manual'];
    }

    /**
     * Full costing block for one recipe: per-line breakdown + cost/sell/profit/margin.
     */
    public function cost(Recipe $recipe): array
    {
        $recipe->loadMissing('lines.inventoryItem', 'lines.subRecipe', 'menuItem');

        $lines = [];
        foreach ($recipe->lines as $line) {
            $c = $this->lineCost($line);
            $lines[] = [
                'id' => $line->id,
                'label' => $line->label,
                'component_type' => $line->component_type,
                'qty' => $c['qty'],
                'unit' => $line->unit,
                'unit_cost' => $c['unit_cost'],
                'line_cost' => $c['line_cost'],
                'note' => $c['note'],
            ];
        }

        $totalCost = round(array_sum(array_column($lines, 'line_cost')), 2);
        [$sell, $sellSource] = $this->resolveSellPrice($recipe);
        $profit = $sell !== null ? round($sell - $totalCost, 2) : null;
        $margin = $sell ? round($profit / $sell * 100, 1) : null;
        $markup = ($sell !== null && $totalCost > 0) ? round($profit / $totalCost * 100, 1) : null;

        return [
            'lines' => $lines,
            'total_cost' => $totalCost,
            'sell_price' => $sell,
            'sell_source' => $sellSource,
            'profit' => $profit,
            'margin_pct' => $margin,
            'markup_pct' => $markup,
        ];
    }

    /** Suggested sell price to hit a target margin %. */
    public function suggestedPrice(Recipe $recipe, float $targetMarginPct): ?float
    {
        $cost = $this->totalCost($recipe);
        if ($targetMarginPct >= 100) {
            return null;
        }

        return round($cost / (1 - $targetMarginPct / 100), 2);
    }
}
