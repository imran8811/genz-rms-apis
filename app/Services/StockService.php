<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;

/**
 * Stock control for resale goods.
 *
 * The balance of a tracked item is **derived, never typed**: it is the ledger in
 * `stock_movements` replayed in date order — deliveries in, sales out, the
 * occasional stocktake resetting the count to whatever was on the shelf. Nothing
 * writes `current_stock` directly; it is a cache of that replay, so a corrected
 * purchase or a cancelled bill simply changes the ledger and the balance follows.
 *
 * That choice is the whole point. A running total nudged up and down by each
 * write drifts the first time an order is edited or a delivery deleted, and
 * there is no way to tell afterwards that it has. A replay cannot drift: at any
 * moment `stock:rebuild` re-derives every balance from the same purchases and
 * bills the reports are built on, and gets the same answers.
 *
 * Only items with `track_stock` take part. Raw ingredients are consumed through
 * recipes in grams and nobody counts them off a shelf, so they hold no balance —
 * see the migration for why that is opt-in.
 */
class StockService
{
    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_ORDER = 'order';

    /**
     * Stock control starts here, and nothing before it counts.
     *
     * Stock was never maintained until now, so the months of purchases and bills
     * already on file are not a shelf history — replaying them would produce a
     * balance that looks authoritative and is simply wrong, built on deliveries
     * nobody checked in and bottles nobody counted out. Starting at the first of
     * the current month gives every tracked item a clean opening balance of zero,
     * which a stocktake then corrects to whatever is actually on the shelf.
     *
     * Overridable as the `stock_opening_date` setting, so this is a starting
     * point rather than a decision baked into the code.
     */
    public const DEFAULT_OPENING_DATE = '2026-09-01';

    /**
     * Sizes that are one bottle under two spellings. The menu sells a "350ml";
     * the deals and the ingredient master both call the same bottle "345ml".
     */
    private const SIZE_ALIASES = ['345ml' => '350ml'];

    /** Memoised per instance — a rebuild asks for this once per row. */
    private ?CarbonImmutable $openingDate = null;

    /** @var array<string,int>|null "menuItemId|size" => inventory item id */
    private ?array $skuIndex = null;

    /** @var array<string,int>|null size => inventory item id for deal drinks */
    private ?array $dealDrinkIndex = null;

    /** @var array<string,array<int,int>>|null lower(name) => menu item ids */
    private ?array $menuIdsByName = null;

    // --- Purchases -----------------------------------------------------------

    /**
     * Record (or re-record) the delivery a purchase represents. Idempotent: the
     * purchase's existing movement is withdrawn first, so editing one restates
     * the delivery instead of stacking a second one on the shelf, and moving a
     * purchase onto a different ingredient takes the stock off the old one.
     */
    public function syncPurchase(Purchase $purchase): void
    {
        $affected = $this->withdraw(self::SOURCE_PURCHASE, $purchase->id);
        $this->writePurchase($purchase, $affected);
        $this->recomputeMany(array_keys($affected));
    }

    /** Withdraw a deleted purchase's delivery from the ledger. */
    public function forgetPurchase(Purchase $purchase): void
    {
        $this->recomputeMany(array_keys($this->withdraw(self::SOURCE_PURCHASE, $purchase->id)));
    }

    // --- Sales ---------------------------------------------------------------

    /**
     * Record (or re-record) what an order takes off the shelf. A cancelled order
     * takes nothing — cancelling is what removes a bill from Sales, and stock has
     * to agree with the money or one of the two is lying.
     */
    public function syncOrder(Order $order): void
    {
        $affected = $this->withdraw(self::SOURCE_ORDER, $order->id);

        if ($order->status !== 'cancelled') {
            $this->writeOrder($order, $affected);
        }

        $this->recomputeMany(array_keys($affected));
    }

    /** Withdraw a deleted order's consumption from the ledger. */
    public function forgetOrder(Order $order): void
    {
        $this->recomputeMany(array_keys($this->withdraw(self::SOURCE_ORDER, $order->id)));
    }

    /**
     * How much stock one order takes off each tracked item.
     *
     * There are two ways a bottle leaves the fridge and both have to count, or
     * the shelf reads high all week:
     *  - sold as itself — a "Coca Cola / 1.5Ltr" line on the bill;
     *  - handed out inside a deal, where the bill says "2x Drink 1.5Ltr".
     *
     * @return array<int,float> inventory item id => quantity sold
     */
    public function saleQuantities(Order $order): array
    {
        $order->loadMissing('items');
        $totals = [];

        foreach ($order->items as $line) {
            $qty = (int) $line->quantity;
            if ($qty < 1) {
                continue;
            }

            if ($id = $this->resolveSoldItemId($line)) {
                $totals[$id] = ($totals[$id] ?? 0) + $qty;
            }

            // Per deal sold, so a bill for 3 of "Deal 4" pulls 6 bottles.
            foreach ($this->dealDrinkQuantities($line) as $id => $perDeal) {
                $totals[$id] = ($totals[$id] ?? 0) + $perDeal * $qty;
            }
        }

        return $totals;
    }

    // --- Manual movements ----------------------------------------------------

    /**
     * A movement someone entered by hand: a delivery with no purchase row behind
     * it, wastage, or a stocktake. `adjustment` is an absolute count — see
     * recompute() for why that outranks everything before it.
     */
    public function adjust(InventoryItem $item, string $type, float $quantity, ?string $note = null, ?string $reference = null): StockMovement
    {
        $movement = StockMovement::create([
            'inventory_item_id' => $item->id,
            'type' => $type,
            'quantity' => round($quantity, 2),
            'stock_after' => 0, // recompute() fills the running balance in ledger order
            'occurred_at' => now(),
            'reference' => $reference,
            'note' => $note,
        ]);

        $this->recompute($item);

        return $movement->fresh();
    }

    // --- Replay --------------------------------------------------------------

    /**
     * Re-derive one item's balance by replaying its ledger.
     *
     * Movements are replayed by when they *happened*, not when they were typed,
     * so Friday's entry of Wednesday's delivery lands before Thursday's
     * stocktake rather than after it.
     *
     * A stocktake (`adjustment`) **sets** the balance rather than moving it: it
     * is a count of what is physically on the shelf, so it supersedes everything
     * before it — which is also how a drifted balance gets put right, without
     * anyone having to hunt for the movement that was wrong.
     *
     * The balance is allowed to go negative. Selling more than was ever bought
     * is real information — it means deliveries are not being recorded — and
     * clamping it at zero would throw away the only evidence of that.
     */
    public function recompute(InventoryItem $item): void
    {
        $balance = 0.0;

        $movements = StockMovement::where('inventory_item_id', $item->id)
            ->orderByRaw('COALESCE(occurred_at, created_at)')
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            $balance = match ($movement->type) {
                'in' => $balance + (float) $movement->quantity,
                'out' => $balance - (float) $movement->quantity,
                'adjustment' => (float) $movement->quantity,
                default => $balance,
            };

            $balance = round($balance, 2);

            // The movements list shows the shelf as it stood after each row, so
            // it has to be restated whenever an earlier row changes.
            if ((float) $movement->stock_after !== $balance) {
                $movement->forceFill(['stock_after' => $balance])->saveQuietly();
            }
        }

        $item->forceFill(['current_stock' => $balance])->saveQuietly();
    }

    /** @param  array<int,int>  $itemIds */
    public function recomputeMany(array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        foreach (InventoryItem::whereIn('id', array_unique($itemIds))->get() as $item) {
            $this->recompute($item);
        }
    }

    /**
     * Re-derive every balance from scratch.
     *
     * Deliveries and sales are rewritten from the purchases and bills themselves,
     * so this is also the backfill: it is what puts stock on the shelf for a
     * system that has been recording both all along without ever counting them.
     * Manual movements are left alone — nothing else remembers a stocktake.
     *
     * @return array{purchases:int, orders:int, items:int}
     */
    public function rebuild(): array
    {
        $this->flushLinks();

        StockMovement::whereIn('source_type', [self::SOURCE_PURCHASE, self::SOURCE_ORDER])->delete();

        $affected = [];
        $purchases = 0;
        $orders = 0;
        $opening = $this->openingDate();

        // writePurchase()/writeOrder() enforce the opening date themselves — this
        // only keeps the rebuild from walking every row ever written to get there.
        Purchase::query()->whereDate('date', '>=', $opening)->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$affected, &$purchases) {
                foreach ($chunk as $purchase) {
                    $purchases += $this->writePurchase($purchase, $affected) ? 1 : 0;
                }
            });

        Order::with('items')->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $opening)->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$affected, &$orders) {
                foreach ($chunk as $order) {
                    $orders += $this->writeOrder($order, $affected) ? 1 : 0;
                }
            });

        // Every item, not just the ones touched: one whose only movement is a
        // stocktake has nothing in $affected but still needs re-deriving, and one
        // just switched off tracking would otherwise keep a stale balance sitting
        // there looking live.
        $this->recomputeMany(InventoryItem::pluck('id')->all());

        return [
            'purchases' => $purchases,
            'orders' => $orders,
            'items' => count($affected),
        ];
    }

    /** Drop the cached links after `inventory_items` is edited. */
    public function flushLinks(): void
    {
        $this->skuIndex = null;
        $this->dealDrinkIndex = null;
        $this->menuIdsByName = null;
        $this->openingDate = null;
    }

    /**
     * The first moment stock control counts, as an instant.
     *
     * The setting is a calendar date at the *counter*, and **the app runs on
     * UTC** while the restaurant is on UTC+5 (see LateFineService for the same
     * trap). Read naively, "1 September" would start at 5am Karachi time and
     * quietly drop the first evening's trade — which, on a day that is by
     * definition the whole history of the shelf, is the difference between a
     * balance being short and being right.
     */
    public function openingDate(): CarbonImmutable
    {
        return $this->openingDate ??= CarbonImmutable::parse(
            (string) Setting::get('stock_opening_date', self::DEFAULT_OPENING_DATE),
            (string) Setting::get('restaurant_timezone', LateFineService::DEFAULT_TIMEZONE),
        )->startOfDay()->utc();
    }

    /**
     * Fold the spellings of a size onto one key, so the menu's "1.5Ltr", a
     * deal's "1.5 ltr" and an ingredient's "1.5L" all find the same shelf.
     * Empty string for an unsized item, which is a size in its own right.
     */
    public function normalizeSize(?string $size): string
    {
        $value = preg_replace('/\s+/', '', strtolower(trim((string) $size)));

        if ($value === '') {
            return '';
        }

        if (preg_match('/^([\d.]+)(ml|l|ltr|ltrs|litre|litres|liter|liters)$/', $value, $m)) {
            $value = $m[1].($m[2] === 'ml' ? 'ml' : 'ltr');
        }

        return self::SIZE_ALIASES[$value] ?? $value;
    }

    // --- Writing movements ---------------------------------------------------

    /** @param  array<int,bool>  $affected */
    private function writePurchase(Purchase $purchase, array &$affected): bool
    {
        $item = $purchase->inventory_item_id
            ? InventoryItem::find($purchase->inventory_item_id)
            : null;

        // base_qty is how much arrived in the ingredient's own unit — 2 cartons
        // of Coke is 24 bottles, not 2. It is null when the purchase could not be
        // resolved to an ingredient at all, and there is then no shelf for it.
        if (! $item || ! $item->track_stock || $purchase->base_qty === null) {
            return false;
        }

        // A delivery from before stock control began is history, not stock —
        // and correcting an old purchase must not conjure it onto today's shelf.
        if ($purchase->date === null || $purchase->date->lt($this->openingDate())) {
            return false;
        }

        StockMovement::create([
            'inventory_item_id' => $item->id,
            'type' => 'in',
            'quantity' => round((float) $purchase->base_qty, 2),
            'stock_after' => 0,
            'source_type' => self::SOURCE_PURCHASE,
            'source_id' => $purchase->id,
            'occurred_at' => $purchase->date,
            'reference' => 'Purchase #'.$purchase->id,
            'note' => trim('Delivery — '.$purchase->vendor_name),
        ]);

        $affected[$item->id] = true;

        return true;
    }

    /** @param  array<int,bool>  $affected */
    private function writeOrder(Order $order, array &$affected): bool
    {
        // Bills from before stock control began were rung up against a shelf
        // nobody was counting; taking them off one now would only make it wrong.
        if ($order->created_at === null || $order->created_at->lt($this->openingDate())) {
            return false;
        }

        $quantities = $this->saleQuantities($order);

        foreach ($quantities as $itemId => $qty) {
            StockMovement::create([
                'inventory_item_id' => $itemId,
                'type' => 'out',
                'quantity' => round($qty, 2),
                'stock_after' => 0,
                'source_type' => self::SOURCE_ORDER,
                'source_id' => $order->id,
                'occurred_at' => $order->created_at,
                'reference' => 'Order #'.$order->order_number,
                'note' => 'Sold',
            ]);

            $affected[$itemId] = true;
        }

        return $quantities !== [];
    }

    /**
     * Remove every movement a cause produced, and report which shelves it stood
     * on so they are re-derived whether or not it lands on them again.
     *
     * @return array<int,bool>
     */
    private function withdraw(string $sourceType, int $sourceId): array
    {
        $query = StockMovement::where('source_type', $sourceType)->where('source_id', $sourceId);

        $affected = [];
        foreach ($query->pluck('inventory_item_id') as $id) {
            $affected[$id] = true;
        }

        $query->delete();

        return $affected;
    }

    // --- Resolving a sold line to a shelf ------------------------------------

    /** The tracked item a bill line sells, if any. */
    private function resolveSoldItemId(OrderItem $line): ?int
    {
        $size = $this->normalizeSize($line->size);

        foreach ($this->menuItemIdsFor($line) as $menuItemId) {
            $id = $this->skuIndex()[$menuItemId.'|'.$size] ?? null;
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The menu items a bill line could be.
     *
     * Normally the line carries the id outright. Bills rung up before the till
     * started sending it have only the name they were typed under, so those fall
     * back to matching the menu mirror on name — which is what lets a rebuild put
     * months of past sales on the shelf instead of starting from today.
     *
     * @return array<int,int>
     */
    private function menuItemIdsFor(OrderItem $line): array
    {
        if ($line->menu_item_id) {
            return [$line->menu_item_id];
        }

        $name = mb_strtolower(trim((string) $line->item_name));

        return $name === '' ? [] : ($this->menuIdsByName()[$name] ?? []);
    }

    /**
     * Drinks handed out inside a deal.
     *
     * The bill records the deal's own wording — "Drink 345ml", "2x Drink 1.5Ltr"
     * — and never the flavour, because the deal picker has nowhere to choose one.
     * So they come off whichever item is flagged the default for that size: the
     * number of bottles leaving the fridge is right, even though which flavour
     * went out is not. Parsing the wording stored **on the bill** rather than the
     * deal's current definition keeps old orders counted as they were sold.
     *
     * @return array<int,int> inventory item id => bottles per deal
     */
    private function dealDrinkQuantities(OrderItem $line): array
    {
        $totals = [];

        foreach ((array) $line->deal_selections as $selection) {
            // "Drink 1.5Ltr", "2x Drink 345ml", "2 Drink 1Ltr" — the count is
            // optional, and the rest of a deal's extras ("1 Reg Fries", a pizza
            // flavour) simply never match.
            if (! preg_match('/^(?:(\d+)\s*x?\s*)?drinks?\s+(.+)$/i', trim((string) $selection), $m)) {
                continue;
            }

            $id = $this->dealDrinkIndex()[$this->normalizeSize($m[2])] ?? null;
            if ($id === null) {
                continue;
            }

            $totals[$id] = ($totals[$id] ?? 0) + max(1, (int) ($m[1] ?: 1));
        }

        return $totals;
    }

    // --- Cached links --------------------------------------------------------

    /** @return array<string,int> */
    private function skuIndex(): array
    {
        if ($this->skuIndex === null) {
            $this->buildLinkIndexes();
        }

        return $this->skuIndex;
    }

    /** @return array<string,int> */
    private function dealDrinkIndex(): array
    {
        if ($this->dealDrinkIndex === null) {
            $this->buildLinkIndexes();
        }

        return $this->dealDrinkIndex;
    }

    /** @return array<string,array<int,int>> */
    private function menuIdsByName(): array
    {
        if ($this->menuIdsByName === null) {
            $this->menuIdsByName = [];
            foreach (MenuItem::select('id', 'name')->get() as $menuItem) {
                $this->menuIdsByName[mb_strtolower(trim($menuItem->name))][] = $menuItem->id;
            }
        }

        return $this->menuIdsByName;
    }

    /**
     * Load the links once per replay. A rebuild walks every bill ever rung up, so
     * resolving each line with its own query would turn one rebuild into tens of
     * thousands of them.
     */
    private function buildLinkIndexes(): void
    {
        $this->skuIndex = [];
        $this->dealDrinkIndex = [];

        $tracked = InventoryItem::where('track_stock', true)
            ->where('is_active', true)
            ->get(['id', 'menu_item_id', 'variant', 'is_deal_default']);

        foreach ($tracked as $item) {
            $size = $this->normalizeSize($item->variant);

            if ($item->menu_item_id) {
                $this->skuIndex[$item->menu_item_id.'|'.$size] = $item->id;
            }
            if ($item->is_deal_default) {
                $this->dealDrinkIndex[$size] = $item->id;
            }
        }
    }
}
