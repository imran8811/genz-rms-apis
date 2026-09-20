<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use RuntimeException;

/**
 * Puts one menu category under stock control by pairing each sellable SKU with
 * the ingredient that *is* that SKU.
 *
 * Doing it by hand is 14 trips through the Inventory dialog for cold drinks
 * alone — one per flavour **and size**, because each size is its own shelf — and
 * 14 chances to mispair one. The pairing is not something the eye checks
 * reliably either: the ingredient master says `coke 345ml` where the menu says
 * `Coca Cola / 350ml`.
 *
 * Reusing an ingredient that already exists matters more than it looks. Those
 * rows are what the purchases already point at; create a fresh row beside one
 * and the deliveries stay on the old shelf, leaving the new one reading zero
 * with no visible reason why.
 *
 * Idempotent — re-running re-pairs the same shelves rather than duplicating them.
 */
class CategoryStockLinker
{
    /** Words that say a drink is a drink, not which drink it is. */
    private const GENERIC_WORDS = ['cola', 'drink', 'drinks', 'soft', 'bottle'];

    /** What the ingredient master calls a flavour when it does not use the menu's word. */
    private const NAME_ALIASES = [
        'coca' => ['coke'],
        'water' => ['mineral', 'aqua'],
    ];

    /** Ingredient ids already paired in this run, so two SKUs cannot claim one shelf. */
    private array $claimed = [];

    public function __construct(private StockService $stock) {}

    /**
     * @return array<int,array{ingredient:string, sold_as:string, action:string, deal_default:bool}>
     *
     * @throws RuntimeException when the category is unknown or has no live items
     */
    public function link(string $categorySlug, ?string $dealDefaultSlug = null, bool $dryRun = false): array
    {
        $this->claimed = [];

        $category = Category::where('slug', $categorySlug)->first();
        if (! $category) {
            throw new RuntimeException(sprintf(
                'No menu category with slug "%s". Known: %s. If that looks stale, sync the menu first.',
                $categorySlug,
                Category::orderBy('sort_order')->pluck('slug')->implode(', ') ?: '(none)',
            ));
        }

        $menuItems = MenuItem::where('category_id', $category->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        if ($menuItems->isEmpty()) {
            throw new RuntimeException("\"{$category->name}\" has no active menu items — sync the menu first.");
        }

        $rows = [];

        foreach ($menuItems as $menuItem) {
            foreach ($this->sizesFor($menuItem) as $size) {
                [$item, $action] = $this->resolve($menuItem, $size, $dryRun);
                $isDealDefault = $dealDefaultSlug !== null && $menuItem->slug === $dealDefaultSlug;

                if (! $dryRun && $item) {
                    $item->update([
                        'track_stock' => true,
                        'menu_item_id' => $menuItem->id,
                        'variant' => $size,
                        'is_deal_default' => $isDealDefault,
                    ]);
                }

                $rows[] = [
                    'ingredient' => $item?->name ?? $this->proposedName($menuItem, $size),
                    'sold_as' => $menuItem->name.($size ? " / {$size}" : ''),
                    'action' => $action,
                    'deal_default' => $isDealDefault,
                ];
            }
        }

        if (! $dryRun) {
            $this->releaseOutsideDealDefaults($category);
        }

        return $rows;
    }

    /**
     * What each shelf in the category bought, sold and has left.
     *
     * Bought and sold are shown beside the balance on purpose: a number on its
     * own invites the question this answers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function balances(string $categorySlug): array
    {
        $category = Category::where('slug', $categorySlug)->first();
        if (! $category) {
            return [];
        }

        $menuItemIds = MenuItem::where('category_id', $category->id)->pluck('id');

        return InventoryItem::with('menuItem:id,name')
            ->tracked()->where('is_active', true)
            ->whereIn('menu_item_id', $menuItemIds)
            ->get()
            ->sortBy(fn (InventoryItem $i) => [(string) $i->menuItem?->name, (string) $i->variant])
            ->map(fn (InventoryItem $i) => [
                'sold_as' => $i->menuItem?->name.($i->variant ? " / {$i->variant}" : ''),
                'ingredient' => $i->name,
                'bought' => $i->movementTotal('in'),
                'sold' => $i->movementTotal('out'),
                'on_hand' => (float) $i->current_stock,
                'status' => $i->status,
            ])
            ->values()
            ->all();
    }

    // --- pairing -------------------------------------------------------------

    /** Each priced size is its own shelf; an unsized item is a single one. */
    private function sizesFor(MenuItem $menuItem): array
    {
        if ($menuItem->price_type !== 'sized' || ! is_array($menuItem->prices)) {
            return [null];
        }

        $sizes = array_keys(array_filter($menuItem->prices, fn ($p) => $p !== null && $p !== ''));

        return $sizes ?: [null];
    }

    /**
     * Find the ingredient that already is this SKU, or make one.
     *
     * @return array{0: ?InventoryItem, 1: string}
     */
    private function resolve(MenuItem $menuItem, ?string $size, bool $dryRun): array
    {
        $target = $this->stock->normalizeSize($size);

        // Already pointed at exactly this SKU — a re-run, so leave it be.
        $existing = InventoryItem::where('menu_item_id', $menuItem->id)->get()
            ->first(fn (InventoryItem $i) => $this->stock->normalizeSize($i->variant) === $target);

        if ($existing) {
            $this->claimed[] = $existing->id;

            return [$existing, 'already linked'];
        }

        if ($match = $this->findUnlinkedMatch($menuItem, $target)) {
            $this->claimed[] = $match->id;

            return [$match, 'linked existing'];
        }

        if ($dryRun) {
            return [null, 'would create'];
        }

        // pack_size is deliberately left unset. A purchase in pieces converts on
        // its own; one in cartons is then *rejected* with a message asking for
        // the pack size — far better than dividing by a guessed 1 and storing a
        // cost wrong by the size of the carton.
        $created = InventoryItem::create([
            'name' => $this->proposedName($menuItem, $size),
            'unit' => 'pc',
            'base_unit' => 'pc',
            'pack_unit' => 'pc',
            'cost_per_unit' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $this->claimed[] = $created->id;

        return [$created, 'created'];
    }

    /**
     * An unlinked ingredient naming the same drink *and* the same size.
     *
     * Both halves must agree. Size alone would pair Sprite with Coke's bottles;
     * flavour alone would pair the 350ml shelf with the 1.5Ltr one.
     */
    private function findUnlinkedMatch(MenuItem $menuItem, string $target): ?InventoryItem
    {
        $keywords = $this->keywordsFor($menuItem->name);
        if ($keywords === []) {
            return null;
        }

        return InventoryItem::where('is_active', true)
            ->whereNull('menu_item_id')
            ->get()
            ->reject(fn (InventoryItem $i) => in_array($i->id, $this->claimed, true))
            ->first(function (InventoryItem $i) use ($keywords, $target) {
                $name = mb_strtolower($i->name);

                foreach ($keywords as $keyword) {
                    if (str_contains($name, $keyword)) {
                        return $this->stock->normalizeSize($this->sizeIn($name)) === $target;
                    }
                }

                return false;
            });
    }

    /**
     * The words that say *which* drink this is.
     *
     * "Cola" is dropped on purpose: three of the four flavours carry it, so
     * matching on it would pair Next Cola's shelf with Coca Cola's bottles.
     *
     * @return array<int,string>
     */
    private function keywordsFor(string $menuItemName): array
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($menuItemName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_diff($words, self::GENERIC_WORDS));

        $keywords = $words;
        foreach ($words as $word) {
            $keywords = array_merge($keywords, self::NAME_ALIASES[$word] ?? []);
        }

        return array_values(array_unique($keywords));
    }

    /** The size written into an ingredient's name: "coke 1.5ltr" → "1.5ltr". */
    private function sizeIn(string $name): ?string
    {
        return preg_match('/(\d+(?:\.\d+)?)\s*(ml|l|ltrs?|litres?|liters?)\b/i', $name, $m)
            ? $m[1].$m[2]
            : null;
    }

    private function proposedName(MenuItem $menuItem, ?string $size): string
    {
        return trim($menuItem->name.' '.(string) $size);
    }

    /**
     * Release deal-default claims held outside this category, so the chosen
     * flavour is unambiguously the one deals are served from.
     */
    private function releaseOutsideDealDefaults(Category $category): void
    {
        $insideIds = MenuItem::where('category_id', $category->id)->pluck('id');

        InventoryItem::where('is_deal_default', true)
            ->where(fn ($q) => $q->whereNull('menu_item_id')->orWhereNotIn('menu_item_id', $insideIds))
            ->update(['is_deal_default' => false]);

        $this->stock->flushLinks();
    }
}
