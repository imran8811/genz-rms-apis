<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Refreshing the menu mirror without a shell.
 *
 * `php artisan menu:sync` was the only way to do it and the production host runs
 * no commands, so the mirror silently stopped tracking genz-admin. The failure
 * that exposed it is the one pinned at the bottom: a drink added in genz-admin
 * (Dew) sells perfectly well on the till — the POS reads the live admin feed —
 * while every bill for it comes off no shelf at all, because stock resolves
 * through `menu_items` and there is no row there to resolve against.
 */
class MenuSyncAccessTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = [
        'categories' => [[
            'id' => 'cold-drinks',
            'name' => 'Cold Drinks',
            'type' => 'sized',
            'sizes' => ['350ml', '1.5Ltr'],
            'items' => [
                ['id' => 'drink-coca-cola', 'name' => 'Coca Cola', 'prices' => ['350ml' => 80, '1.5Ltr' => 220]],
                ['id' => 'dew', 'name' => 'Dew', 'prices' => ['350ml' => 80, '1.5Ltr' => 220]],
            ],
        ]],
    ];

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'sync-admin@genzfoods.pk'],
            ['name' => 'Admin', 'password' => bcrypt('secret1234'), 'role' => 'admin'],
        );
    }

    private function fakeFeed(array $feed = self::FEED): void
    {
        Http::fake(['*' => Http::response($feed, 200)]);
    }

    public function test_an_admin_can_refresh_the_mirror_over_http(): void
    {
        $this->fakeFeed();

        $this->actingAs($this->admin())->postJson('/api/menu/sync')
            ->assertOk()
            ->assertJsonPath('categories', 1)
            ->assertJsonPath('items', 2);

        $this->assertNotNull(MenuItem::where('slug', 'dew')->first());
        $this->assertSame('Dew', MenuItem::where('slug', 'dew')->value('name'));
    }

    public function test_the_counter_cannot_resync_the_menu(): void
    {
        $this->fakeFeed();

        $counter = User::create([
            'name' => 'Counter', 'email' => 'counter-sync@genzfoods.pk',
            'password' => bcrypt('secret1234'), 'role' => 'user',
        ]);

        $this->actingAs($counter)->postJson('/api/menu/sync')->assertForbidden();
        $this->assertSame(0, MenuItem::count());
    }

    public function test_an_unreachable_feed_explains_itself_rather_than_500ing(): void
    {
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $this->actingAs($this->admin())->postJson('/api/menu/sync')
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '502'));
    }

    public function test_a_malformed_feed_does_not_wipe_the_mirror(): void
    {
        // A sequence, not two fake() calls: stubs are merged, so a second '*'
        // never wins over the first.
        Http::fakeSequence()
            ->push(self::FEED, 200)
            ->push(['oops' => true], 200);

        $this->actingAs($this->admin())->postJson('/api/menu/sync')->assertOk();

        // A feed that arrives without categories must be rejected outright. The
        // importer deactivates whatever is missing from a feed, so treating this
        // as an empty menu would retire every item the RMS knows about.
        $this->actingAs($this->admin())->postJson('/api/menu/sync')->assertStatus(422);

        $this->assertSame(2, MenuItem::where('is_active', true)->count());
    }

    public function test_a_drink_missing_from_the_mirror_sells_but_comes_off_no_shelf(): void
    {
        // The state the host had been in: Dew live in genz-admin, absent here.
        $category = Category::create([
            'slug' => 'cold-drinks', 'name' => 'Cold Drinks', 'type' => 'sized',
            'sizes' => ['350ml', '1.5Ltr'], 'sort_order' => 0, 'is_active' => true,
        ]);
        MenuItem::create([
            'category_id' => $category->id, 'slug' => 'drink-coca-cola', 'name' => 'Coca Cola',
            'price_type' => 'sized', 'prices' => ['350ml' => 80, '1.5Ltr' => 220],
            'is_active' => true, 'sort_order' => 0,
        ]);

        // A Dew shelf someone set up by hand, with stock on it.
        $shelf = InventoryItem::create([
            'name' => 'Dew 1.5Ltr', 'unit' => 'pc', 'base_unit' => 'pc',
            'is_active' => true, 'track_stock' => true, 'variant' => '1.5Ltr',
        ]);
        app(StockService::class)->adjust($shelf, 'adjustment', 24, 'Opening stock');

        // The till sells one. It knows the slug; the mirror has never heard of it.
        $this->actingAs($this->admin())->postJson('/api/orders', [
            'order_type' => 'Takeaway', 'source' => 'pos',
            'subtotal' => 220, 'delivery_charge' => 0, 'extra_topping' => 0, 'total' => 220,
            'status' => 'completed',
            'items' => [[
                'menu_item_slug' => 'dew', 'item_name' => 'Dew', 'size' => '1.5Ltr',
                'unit_price' => 220, 'quantity' => 1, 'line_total' => 220,
            ]],
        ])->assertCreated();

        // The bill stands and the money is counted — but the shelf never moved,
        // and nothing anywhere said so. That silence is the bug.
        $this->assertNull(Order::latest('id')->first()->items()->first()->menu_item_id);
        $this->assertSame(24.0, (float) $shelf->fresh()->current_stock);

        // Syncing is what makes the sale attributable from then on.
        $this->fakeFeed();
        $this->actingAs($this->admin())->postJson('/api/menu/sync')->assertOk();

        $this->actingAs($this->admin())->postJson('/api/orders', [
            'order_type' => 'Takeaway', 'source' => 'pos',
            'subtotal' => 220, 'delivery_charge' => 0, 'extra_topping' => 0, 'total' => 220,
            'status' => 'completed',
            'items' => [[
                'menu_item_slug' => 'dew', 'item_name' => 'Dew', 'size' => '1.5Ltr',
                'unit_price' => 220, 'quantity' => 1, 'line_total' => 220,
            ]],
        ])->assertCreated();

        $this->assertSame(
            MenuItem::where('slug', 'dew')->value('id'),
            Order::latest('id')->first()->items()->first()->menu_item_id,
        );
    }
}
