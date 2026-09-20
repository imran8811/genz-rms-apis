<?php

namespace App\Services;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
     * Fetch the canonical feed from genz-admin and import it.
     *
     * Lives here rather than in the artisan command because the command is not
     * the only caller any more: the production host allows no shell, so the
     * mirror is refreshed over HTTP instead. Keeping the fetch, the validation
     * and the import in one place is what stops the two routes drifting into
     * accepting different things.
     *
     * @return array{categories:int, items:int, url:string}
     *
     * @throws RuntimeException with a message worth showing the caller
     */
    public function importFromFeed(?string $url = null): array
    {
        $url = $url ?: config('genz.admin_menu_url');

        if (! $url) {
            throw new RuntimeException('No menu feed URL configured. Set ADMIN_MENU_URL in .env.');
        }

        try {
            $response = Http::timeout(20)->acceptJson()->get($url);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach genz-admin: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("The menu feed returned HTTP {$response->status()}.");
        }

        $menu = $response->json();
        if (! is_array($menu) || ! is_array($menu['categories'] ?? null)) {
            throw new RuntimeException('Unexpected feed format: no "categories".');
        }

        return [...$this->import($menu), 'url' => $url];
    }

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
