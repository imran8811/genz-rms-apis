<?php

namespace App\Services;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;

/**
 * Imports the canonical menu (menu.json shape) from genz-admin into the RMS's
 * own categories + menu_items tables. The RMS is now a read-only consumer of
 * the menu — this keeps a local mirror so recipes/costing/inventory can keep
 * referencing menu_items by `menu_item_id`.
 *
 * Upserts strictly by slug so menu_item ids stay stable across syncs and the
 * recipes.menu_item_id foreign keys never break. Items/categories that vanish
 * from the feed are deactivated, never deleted (so cost links survive).
 *
 * Deal groups are categories whose slug ends in "deals"; their items are stored
 * as ordinary menu_items carrying pizza_selection / deal_extras.
 */
class MenuImporter
{
    /**
     * @param  array{categories?: array<int,array<string,mixed>>}  $menu
     * @return array{categories:int, items:int}
     */
    public function import(array $menu): array
    {
        $categories = $menu['categories'] ?? [];

        return DB::transaction(function () use ($categories) {
            $seenCategorySlugs = [];
            $seenItemSlugs = [];
            $catSort = 0;

            foreach ($categories as $cat) {
                $category = Category::updateOrCreate(
                    ['slug' => $cat['id']],
                    [
                        'name' => $cat['name'],
                        'type' => $cat['type'] ?? 'single',
                        'sizes' => $cat['sizes'] ?? null,
                        'sort_order' => $catSort++,
                        'is_active' => true,
                    ],
                );
                $seenCategorySlugs[] = $cat['id'];

                $itemSort = 0;
                foreach (($cat['items'] ?? []) as $item) {
                    MenuItem::updateOrCreate(
                        ['slug' => $item['id']],
                        [
                            'category_id' => $category->id,
                            'name' => $item['name'],
                            'description' => $item['description'] ?? null,
                            'price_type' => ($cat['type'] ?? 'single') === 'sized' ? 'sized' : 'single',
                            'price' => $item['price'] ?? null,
                            'prices' => $item['prices'] ?? null,
                            'pizza_selection' => $item['pizzaSelection'] ?? null,
                            'deal_extras' => $item['dealExtras'] ?? null,
                            'default_size' => $item['defaultSize'] ?? null,
                            'tag' => $item['tag'] ?? null,
                            'is_special' => $item['special'] ?? false,
                            'is_signature' => $item['signature'] ?? false,
                            'is_active' => true,
                            'sort_order' => $itemSort++,
                        ],
                    );
                    $seenItemSlugs[] = $item['id'];
                }
            }

            // Deactivate (never delete) records no longer in the feed so
            // recipes.menu_item_id stays valid.
            Category::whereNotIn('slug', $seenCategorySlugs ?: ['__none__'])->update(['is_active' => false]);
            MenuItem::whereNotIn('slug', $seenItemSlugs ?: ['__none__'])->update(['is_active' => false]);

            return [
                'categories' => count($seenCategorySlugs),
                'items' => count($seenItemSlugs),
            ];
        });
    }
}
