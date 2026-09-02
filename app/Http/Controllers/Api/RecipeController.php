<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Services\CostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    public function __construct(private CostingService $costing) {}

    /**
     * Costing summary — one row per recipe with cost, sell price, profit, margin.
     * This is what the Costing page table renders.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = Recipe::with('lines.inventoryItem', 'lines.subRecipe')->where('is_active', true);

        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }

        $query->with('menuItem');

        $rows = $query->orderBy('category')->orderBy('name')->get()->map(function (Recipe $r) {
            $c = $this->costing->cost($r);
            return [
                'id'             => $r->id,
                'kind'           => $r->kind,
                'name'           => $r->name,
                'category'       => $r->category,
                'variant'        => $r->variant,
                'menu_item_id'   => $r->menu_item_id,
                'menu_item_slug' => $r->menuItem?->slug,
                'yield_qty'      => $r->yield_qty,
                'yield_unit'     => $r->yield_unit,
                'unit_cost'      => ($r->yield_qty && $r->yield_qty > 0)
                    ? round($c['total_cost'] / $r->yield_qty, 4)
                    : null,
                'total_cost'  => $c['total_cost'],
                'sell_price'  => $c['sell_price'],
                'sell_source' => $c['sell_source'],
                'profit'      => $c['profit'],
                'margin_pct'  => $c['margin_pct'],
                'has_issues'  => collect($c['lines'])->contains(fn ($l) => $l['note'] !== null),
            ];
        });

        return response()->json($rows->values());
    }

    public function index(Request $request): JsonResponse
    {
        return $this->summary($request);
    }

    /** Full recipe with the per-line cost breakdown. */
    public function show(Recipe $recipe): JsonResponse
    {
        $recipe->loadMissing('menuItem');
        $costing = $this->costing->cost($recipe);

        return response()->json([
            'id'             => $recipe->id,
            'kind'           => $recipe->kind,
            'name'           => $recipe->name,
            'category'       => $recipe->category,
            'menu_item_id'   => $recipe->menu_item_id,
            'menu_item_name' => $recipe->menuItem?->name,
            'variant'        => $recipe->variant,
            'sell_price'     => $recipe->sell_price,
            'yield_qty'      => $recipe->yield_qty,
            'yield_unit'     => $recipe->yield_unit,
            'notes'          => $recipe->notes,
            'lines'          => $this->mergeLineDetail($recipe, $costing['lines']),
            'costing'        => collect($costing)->except('lines'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRecipe($request);
        $lines = $this->validateLines($request);

        $recipe = DB::transaction(function () use ($data, $lines) {
            $recipe = Recipe::create($data);
            $this->syncLines($recipe, $lines);
            return $recipe;
        });

        return $this->show($recipe->fresh());
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        DB::transaction(function () use ($request, $recipe) {
            $recipe->update($this->validateRecipe($request, false));
            // When the editor sends a `lines` array, treat it as the full set.
            if ($request->has('lines')) {
                $this->syncLines($recipe, $this->validateLines($request));
            }
        });

        return $this->show($recipe->fresh());
    }

    public function destroy(Recipe $recipe): JsonResponse
    {
        $recipe->update(['is_active' => false]);

        return response()->json(null, 204);
    }

    // --- Line management -------------------------------------------------

    public function storeLine(Request $request, Recipe $recipe): JsonResponse
    {
        $data = $this->withLineDefaults($this->validateLine($request));
        $data['recipe_id'] = $recipe->id;
        RecipeLine::create($data);

        return $this->show($recipe->fresh());
    }

    public function updateLine(Request $request, RecipeLine $line): JsonResponse
    {
        $line->update($this->validateLine($request, false));

        return $this->show($line->recipe->fresh());
    }

    public function destroyLine(RecipeLine $line): JsonResponse
    {
        $recipe = $line->recipe;
        $line->delete();

        return $this->show($recipe->fresh());
    }

    /** Ingredients for the picker and price manager. */
    public function ingredients(): JsonResponse
    {
        $items = InventoryItem::where('is_active', true)
            ->orderBy('name')->get()
            ->map(fn (InventoryItem $i) => $this->ingredientPayload($i));

        return response()->json($items->values());
    }

    public function storeIngredient(Request $request): JsonResponse
    {
        $item = InventoryItem::create($this->validateIngredient($request));

        return response()->json($this->ingredientPayload($item), 201);
    }

    public function updateIngredient(Request $request, InventoryItem $item): JsonResponse
    {
        $item->update($this->validateIngredient($request, false));

        return response()->json($this->ingredientPayload($item->fresh()));
    }

    /**
     * Soft-delete an ingredient (deactivate). Recipes that reference it keep
     * costing via the relation; it just drops out of the catalogue feed.
     */
    public function destroyIngredient(InventoryItem $item): JsonResponse
    {
        $item->update(['is_active' => false]);

        return response()->json(null, 204);
    }

    private function ingredientPayload(InventoryItem $i): array
    {
        return [
            'id'         => $i->id,
            'name'       => $i->name,
            'supplier'   => $i->supplier,
            'base_unit'  => $i->base_unit ?: $i->unit,
            'pack_size'  => $i->pack_size,
            'pack_unit'  => $i->pack_unit,
            'pack_price' => $i->pack_price,
            'unit_cost'  => $i->unit_cost,
        ];
    }

    /**
     * Validate an ingredient and derive its cached per-unit costs.
     *
     * `base_unit` is what recipes consume (g/ml/pc). `pack_unit` is what you buy
     * in — a pack unit like bottle/packet/shashay, or the base unit itself when
     * the item is bought loose. `pack_size` bridges the two: how many base units
     * are in one pack (1 bottle = 950 g). Purchasing divides its rate by it, so
     * this is the number that keeps costing in grams.
     */
    private function validateIngredient(Request $request, bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        $data = $request->validate([
            'name'       => "$r|string|max:150",
            'supplier'   => 'nullable|string|max:150',
            'base_unit'  => "$r|string|max:10",
            'pack_size'  => "$r|numeric|gt:0",
            'pack_unit'  => 'nullable|string|max:10',
            'pack_price' => "$r|numeric|min:0",
        ]);

        if (isset($data['pack_size'], $data['pack_price'])) {
            $perUnit = $data['pack_price'] / $data['pack_size'];
            $data['cost_per_base_unit'] = round($perUnit, 4);
            $data['cost_per_unit']      = (int) round($perUnit);
        }
        if (isset($data['base_unit'])) {
            $data['unit'] = $data['base_unit'];
            $data['pack_unit'] = $data['pack_unit'] ?? $data['base_unit'];
        }
        $data['is_active'] = true;

        return $data;
    }

    /**
     * Menu items a recipe can link to (for live sell price).
     *
     * `slug` is the stable identity shared with the genz-admin feed — names get
     * reused and renumbered across menu revisions, so callers must match on slug.
     */
    public function menuOptions(): JsonResponse
    {
        $items = MenuItem::where('is_active', true)->orderBy('name')->get()
            ->map(fn (MenuItem $m) => [
                'id'         => $m->id,
                'slug'       => $m->slug,
                'name'       => $m->name,
                'price_type' => $m->price_type,
                'price'      => $m->price,
                'prices'     => $m->prices,
            ]);

        return response()->json($items->values());
    }

    // --- helpers ---------------------------------------------------------

    /** Validate an optional full set of lines sent by the editor. */
    private function validateLines(Request $request): ?array
    {
        if (!$request->has('lines')) {
            return null;
        }

        return $request->validate([
            'lines'                    => 'array',
            'lines.*.component_type'   => 'required|in:ingredient,sub_recipe,overhead',
            'lines.*.inventory_item_id' => 'nullable|exists:inventory_items,id',
            'lines.*.sub_recipe_id'    => 'nullable|exists:recipes,id',
            'lines.*.overhead_key'     => 'nullable|string|max:50',
            'lines.*.label'            => 'required|string|max:150',
            'lines.*.qty'              => 'nullable|numeric|min:0',
            'lines.*.unit'             => 'nullable|string|max:10',
            'lines.*.flat_cost'        => 'nullable|numeric|min:0',
            'lines.*.waste_pct'        => 'nullable|numeric|min:0|max:100',
        ])['lines'] ?? [];
    }

    /** Replace a recipe's lines with the given set (null = leave untouched). */
    private function syncLines(Recipe $recipe, ?array $lines): void
    {
        if ($lines === null) {
            return;
        }
        $recipe->lines()->delete();
        foreach (array_values($lines) as $i => $line) {
            RecipeLine::create($this->withLineDefaults(array_merge($line, [
                'recipe_id'  => $recipe->id,
                'sort_order' => $i,
            ])));
        }
    }

    /**
     * Fill line columns that don't apply to every component type. Overhead lines
     * carry no qty/unit (they use flat_cost); ingredient/sub-recipe lines carry no
     * flat_cost. The DB marks qty/unit NOT NULL, so default them rather than let a
     * null through.
     */
    private function withLineDefaults(array $line): array
    {
        return array_merge($line, [
            'qty'       => $line['qty'] ?? 0,
            'unit'      => $line['unit'] ?? '',
            'flat_cost' => $line['flat_cost'] ?? 0,
            'waste_pct' => $line['waste_pct'] ?? 0,
        ]);
    }

    private function validateRecipe(Request $request, bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return $request->validate([
            'kind'         => "$r|in:product,sub_recipe",
            'name'         => "$r|string|max:150",
            'category'     => 'nullable|string|max:100',
            'menu_item_id' => 'nullable|exists:menu_items,id',
            'variant'      => 'nullable|string|max:50',
            'sell_price'   => 'nullable|numeric|min:0',
            'yield_qty'    => 'nullable|numeric|min:0',
            'yield_unit'   => 'nullable|string|max:10',
            'notes'        => 'nullable|string',
            'is_active'    => 'boolean',
        ]);
    }

    private function validateLine(Request $request, bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return $request->validate([
            'component_type'    => "$r|in:ingredient,sub_recipe,overhead",
            'inventory_item_id' => 'nullable|exists:inventory_items,id',
            'sub_recipe_id'     => 'nullable|exists:recipes,id',
            'overhead_key'      => 'nullable|string|max:50',
            'label'             => "$r|string|max:150",
            'qty'               => 'nullable|numeric|min:0',
            'unit'              => 'nullable|string|max:10',
            'flat_cost'         => 'nullable|numeric|min:0',
            'waste_pct'         => 'nullable|numeric|min:0|max:100',
            'sort_order'        => 'nullable|integer',
        ]);
    }

    /** Merge the raw line records with their computed cost detail for the editor. */
    private function mergeLineDetail(Recipe $recipe, array $costed): array
    {
        $byId = collect($costed)->keyBy('id');

        return $recipe->lines->map(function (RecipeLine $line) use ($byId) {
            $c = $byId->get($line->id, []);

            // `label` is free text the user can override and can go stale when a
            // line is re-pointed at a different component. `component_name` is
            // resolved from the FK, so it always names what is actually costed.
            $sub = $line->subRecipe;
            $componentName = match ($line->component_type) {
                'ingredient' => $line->inventoryItem?->name,
                'sub_recipe' => $sub ? $sub->name.($sub->variant ? " — {$sub->variant}" : '') : null,
                default      => null,
            };

            return [
                'id'                => $line->id,
                'component_type'    => $line->component_type,
                'inventory_item_id' => $line->inventory_item_id,
                'sub_recipe_id'     => $line->sub_recipe_id,
                'overhead_key'      => $line->overhead_key,
                'label'             => $line->label,
                'component_name'    => $componentName,
                'qty'               => $line->qty,
                'unit'              => $line->unit,
                'flat_cost'         => $line->flat_cost,
                'waste_pct'         => $line->waste_pct,
                'unit_cost'         => $c['unit_cost'] ?? null,
                'line_cost'         => $c['line_cost'] ?? null,
                'note'              => $c['note'] ?? null,
            ];
        })->all();
    }
}
