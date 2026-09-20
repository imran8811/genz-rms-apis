<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Services\CategoryStockLinker;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryController extends Controller
{
    /** Columns that change what stock is derived from, so editing one re-derives it. */
    private const LINK_COLUMNS = ['track_stock', 'menu_item_id', 'variant', 'is_deal_default', 'is_active'];

    public function __construct(private StockService $stock) {}

    public function index(Request $request): JsonResponse
    {
        $query = InventoryItem::with('menuItem:id,name')->where('is_active', true);

        // The catalogue is ~90 raw ingredients and a handful of resale goods, and
        // the stock page is only ever about the latter.
        if ($request->boolean('tracked')) {
            $query->tracked();
        }

        $items = $query->orderBy('name')->get()->map(fn (InventoryItem $i) => $this->payload($i));

        return response()->json($items->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:150',
            'unit'          => 'string|max:20',
            'current_stock' => 'numeric',
            'min_stock'     => 'numeric|min:0',
            'cost_per_unit' => 'integer|min:0',
            ...$this->trackingRules(),
        ]);

        $opening = $data['current_stock'] ?? null;
        unset($data['current_stock']); // never written directly — see below

        $item = InventoryItem::create($this->withTrackingDefaults($data));
        $this->afterTrackingChange($item, $item->track_stock);

        // An opening count is a stocktake like any other, so it goes through the
        // ledger rather than straight onto the row. Written any other way the
        // next rebuild would replay the ledger without it and wipe it out.
        if ($opening !== null && $item->track_stock) {
            $this->stock->adjust($item, 'adjustment', (float) $opening, 'Opening stock');
        }

        return response()->json($this->payload($item->fresh()), 201);
    }

    public function update(Request $request, InventoryItem $item): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'string|max:150',
            'unit'          => 'string|max:20',
            'min_stock'     => 'numeric|min:0',
            'cost_per_unit' => 'integer|min:0',
            'is_active'     => 'boolean',
            ...$this->trackingRules(),
        ]);

        $item->update($this->withTrackingDefaults($data, $item));
        $this->afterTrackingChange($item, $item->wasChanged(self::LINK_COLUMNS));

        return response()->json($this->payload($item->fresh()));
    }

    public function destroy(InventoryItem $item): JsonResponse
    {
        $wasTracked = $item->track_stock;
        $item->update(['is_active' => false]);
        // Off the catalogue, so off the shelf: its links no longer resolve and
        // its balance would otherwise sit there as a number nobody maintains.
        $this->afterTrackingChange($item, $wasTracked);

        return response()->json(null, 204);
    }

    /**
     * A movement entered by hand: a delivery with no purchase behind it, wastage,
     * or a stocktake. Deliveries and sales write themselves — this is for
     * everything else, and for putting a drifted balance right.
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'type'              => 'required|in:in,out,adjustment',
            'quantity'          => 'required|numeric|min:0',
            'note'              => 'nullable|string',
            'reference'         => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data) {
            $item = InventoryItem::lockForUpdate()->find($data['inventory_item_id']);

            if (! $item->track_stock) {
                return response()->json([
                    'message' => "\"{$item->name}\" is not under stock control — switch Track stock on for it first.",
                ], 422);
            }

            $this->stock->adjust(
                $item,
                $data['type'],
                (float) $data['quantity'],
                $data['note'] ?? null,
                $data['reference'] ?? null,
            );

            return response()->json($this->payload($item->fresh()));
        });
    }

    /**
     * Re-derive every balance from the purchases and bills on file.
     *
     * The counterpart of `php artisan stock:rebuild`, for the person looking at
     * a number they don't believe. Because stock is a replay rather than a
     * running total, this is not a repair with a risk attached — it produces the
     * same answer as the last run unless the underlying trade actually changed.
     */
    public function rebuild(): JsonResponse
    {
        $result = $this->stock->rebuild();

        return response()->json([
            'opening_date'  => $this->stock->openingDate()->toDateString(),
            'purchases'     => $result['purchases'],
            'orders'        => $result['orders'],
            'items_touched' => $result['items'],
            'items' => InventoryItem::with('menuItem:id,name')->tracked()->where('is_active', true)
                ->orderBy('name')->get()->map(fn (InventoryItem $i) => $this->payload($i))->values(),
        ]);
    }

    /**
     * Put a whole menu category under stock control, then derive its balances.
     *
     * Cold drinks alone are 14 sellable SKUs — four flavours across four sizes —
     * and pairing each with the right ingredient by hand is 14 chances to get it
     * wrong in a way nothing afterwards would flag. `CategoryStockLinker` holds
     * the pairing rules; this is the door the RMS opens them through, since the
     * host runs no artisan commands.
     *
     * Admin-only: it changes what every stock figure is derived from, which is
     * not a counter decision. `dry_run` returns the pairing without writing.
     */
    public function setupCategory(Request $request, CategoryStockLinker $linker): JsonResponse
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only an admin can change what stock is tracked.'], 403);
        }

        $data = $request->validate([
            'category' => 'nullable|string|max:100',
            'deal_default' => 'nullable|string|max:100',
            'dry_run' => 'boolean',
        ]);

        $category = $data['category'] ?? 'cold-drinks';
        $dryRun = (bool) ($data['dry_run'] ?? false);

        try {
            $links = $linker->link($category, $data['deal_default'] ?? 'drink-next-cola', $dryRun);
        } catch (RuntimeException $e) {
            // An unknown category or an unsynced menu is something the caller can
            // put right, so it is a 422 with the reason, not a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($dryRun) {
            return response()->json(['dry_run' => true, 'links' => $links]);
        }

        $result = $this->stock->rebuild();

        return response()->json([
            'opening_date' => $this->stock->openingDate()->toDateString(),
            'replayed' => ['deliveries' => $result['purchases'], 'bills' => $result['orders']],
            'links' => $links,
            'balances' => $linker->balances($category),
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $query = StockMovement::with('inventoryItem:id,name,unit')
            ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
            ->orderByDesc('id');

        if ($request->has('inventory_item_id')) {
            $query->where('inventory_item_id', $request->inventory_item_id);
        }

        return response()->json($query->paginate(50));
    }

    // --- helpers -------------------------------------------------------------

    /**
     * What stock control needs to know about an item: whether it is counted at
     * all, and which menu SKU selling one refers to. See the `track_stock`
     * migration for why the link is to (menu item, size) rather than a name.
     */
    private function trackingRules(): array
    {
        return [
            'track_stock'     => 'boolean',
            'menu_item_id'    => 'nullable|exists:menu_items,id',
            'variant'         => 'nullable|string|max:50',
            'is_deal_default' => 'boolean',
        ];
    }

    /**
     * An item nobody counts carries no links: leaving a stale menu link on a row
     * whose tracking was just switched off would quietly resume deducting from it
     * the moment someone switched it back on.
     */
    private function withTrackingDefaults(array $data, ?InventoryItem $existing = null): array
    {
        $tracked = $data['track_stock'] ?? $existing?->track_stock ?? false;

        if (! $tracked) {
            $data['menu_item_id'] = null;
            $data['variant'] = null;
            $data['is_deal_default'] = false;
        }

        if (array_key_exists('variant', $data) && trim((string) $data['variant']) === '') {
            $data['variant'] = null;
        }

        return $data;
    }

    /**
     * Re-derive after the links change, and keep the deal default unique per size.
     *
     * Two items claiming to be the 1.5Ltr deal drink is not a conflict the ledger
     * can resolve — one of them would silently never be used — so the newest
     * claim wins and the other is released.
     *
     * The rebuild is skipped when nothing about the links moved: renaming an item
     * or raising its reorder level changes no balance, and replaying the month's
     * trade to confirm that would be a slow way to learn nothing.
     */
    private function afterTrackingChange(InventoryItem $item, bool $linksChanged): void
    {
        if (! $linksChanged) {
            return;
        }

        if ($item->is_deal_default && $item->track_stock) {
            $size = $this->stock->normalizeSize($item->variant);

            $rivals = InventoryItem::where('id', '!=', $item->id)
                ->where('is_deal_default', true)
                ->get()
                ->filter(fn (InventoryItem $other) => $this->stock->normalizeSize($other->variant) === $size);

            foreach ($rivals as $rival) {
                $rival->update(['is_deal_default' => false]);
            }
        }

        $this->stock->flushLinks();
        $this->stock->rebuild();
    }

    private function payload(InventoryItem $i): array
    {
        return [
            'id'              => $i->id,
            'name'            => $i->name,
            'unit'            => $i->unit,
            'current_stock'   => (float) $i->current_stock,
            'min_stock'       => (float) $i->min_stock,
            'cost_per_unit'   => $i->unit_cost,
            'status'          => $i->status,
            'track_stock'     => (bool) $i->track_stock,
            'menu_item_id'    => $i->menu_item_id,
            'menu_item_name'  => $i->menuItem?->name,
            'variant'         => $i->variant,
            'is_deal_default' => (bool) $i->is_deal_default,
        ];
    }
}
