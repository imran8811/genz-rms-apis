<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Putting cold drinks under stock control in one action.
 *
 * The host runs no artisan commands, so this goes through the API the RMS calls.
 * What it has to get right is the pairing: the ingredient master says
 * `coke 345ml` where the menu says `Coca Cola / 350ml`, and reusing that row
 * rather than making a new one beside it is the difference between September's
 * deliveries landing on the shelf and the shelf reading zero.
 */
class CategoryStockSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('stock_opening_date', '2026-09-01');

        $category = Category::create([
            'slug' => 'cold-drinks', 'name' => 'Cold Drinks', 'type' => 'sized',
            'sizes' => ['350ml', '1.5Ltr'], 'sort_order' => 0, 'is_active' => true,
        ]);

        foreach ([
            ['drink-coca-cola', 'Coca Cola', ['350ml' => 80, '1.5Ltr' => 220]],
            ['drink-next-cola', 'Next Cola', ['350ml' => 60, '1.5Ltr' => 160]],
            ['drink-water', 'Water', ['350ml' => null, '1.5Ltr' => 100]],
        ] as $i => [$slug, $name, $prices]) {
            MenuItem::create([
                'category_id' => $category->id, 'slug' => $slug, 'name' => $name,
                'price_type' => 'sized', 'prices' => $prices, 'is_active' => true, 'sort_order' => $i,
            ]);
        }

        // The ingredient master as it actually is: Coke only, named its own way.
        foreach ([['coke 345ml', 12], ['Coke 1.5ltr', 6]] as [$name, $packSize]) {
            InventoryItem::create([
                'name' => $name, 'unit' => 'pc', 'base_unit' => 'pc', 'pack_unit' => 'pc',
                'pack_size' => $packSize, 'pack_price' => 1016, 'cost_per_base_unit' => 169,
                'cost_per_unit' => 169, 'is_active' => true,
            ]);
        }

        // A raw ingredient that must stay out of all of this.
        InventoryItem::create([
            'name' => 'Flour', 'unit' => 'g', 'base_unit' => 'g', 'pack_size' => 1000,
            'pack_price' => 120, 'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'setup-admin@genzfoods.pk'],
            ['name' => 'Admin', 'password' => bcrypt('secret1234'), 'role' => 'admin'],
        );
    }

    private function runSetup(array $payload = []): TestResponse
    {
        return $this->actingAs($this->admin())->postJson('/api/inventory/setup-category', $payload);
    }

    private function itemFor(string $menuSlug, string $variant): ?InventoryItem
    {
        $menuItem = MenuItem::where('slug', $menuSlug)->first();

        return InventoryItem::where('menu_item_id', $menuItem?->id)->where('variant', $variant)->first();
    }

    public function test_it_reuses_the_existing_ingredient_rather_than_creating_a_rival_shelf(): void
    {
        $this->runSetup()->assertOk();

        // Named "coke 345ml", sold as "Coca Cola / 350ml" — the pairing names
        // alone would never make, and the row the purchases already point at.
        $item = $this->itemFor('drink-coca-cola', '350ml');

        $this->assertNotNull($item);
        $this->assertSame('coke 345ml', $item->name);
        $this->assertTrue($item->track_stock);
        $this->assertSame(1, InventoryItem::where('name', 'coke 345ml')->count());
    }

    public function test_it_creates_a_shelf_for_a_drink_the_ingredient_master_never_had(): void
    {
        $this->runSetup()->assertOk();

        $item = $this->itemFor('drink-next-cola', '1.5Ltr');

        $this->assertNotNull($item);
        $this->assertTrue($item->track_stock);
        // No pack size guessed: buying these in cartons must be rejected and
        // asked about, not silently divided by one.
        $this->assertNull($item->pack_size);
    }

    public function test_flavours_are_not_crossed_and_sizes_are_not_pooled(): void
    {
        $this->runSetup()->assertOk();

        // "Cola" is in three of the four names, so a careless match pairs Next
        // Cola's shelf with Coke's bottles.
        $this->assertSame('Coke 1.5ltr', $this->itemFor('drink-coca-cola', '1.5Ltr')?->name);
        $this->assertNotSame('Coke 1.5ltr', $this->itemFor('drink-next-cola', '1.5Ltr')?->name);
        $this->assertNotSame(
            $this->itemFor('drink-coca-cola', '350ml')?->id,
            $this->itemFor('drink-coca-cola', '1.5Ltr')?->id,
        );
    }

    public function test_a_size_with_no_price_gets_no_shelf(): void
    {
        $this->runSetup()->assertOk();

        // Water is not sold in 350ml, so there is nothing to stock.
        $this->assertNull($this->itemFor('drink-water', '350ml'));
        $this->assertNotNull($this->itemFor('drink-water', '1.5Ltr'));
    }

    public function test_deals_are_served_from_next_cola_and_from_only_one_item_per_size(): void
    {
        $this->runSetup()->assertOk();

        $this->assertTrue($this->itemFor('drink-next-cola', '1.5Ltr')->is_deal_default);
        $this->assertFalse($this->itemFor('drink-coca-cola', '1.5Ltr')->is_deal_default);
    }

    public function test_recipe_ingredients_are_left_alone(): void
    {
        $this->runSetup()->assertOk();

        $flour = InventoryItem::where('name', 'Flour')->first();

        $this->assertFalse($flour->track_stock);
        $this->assertSame('Untracked', $flour->status);
    }

    public function test_running_it_twice_does_not_duplicate_shelves(): void
    {
        $this->runSetup()->assertOk();
        $before = InventoryItem::count();

        $this->runSetup()->assertOk();

        $this->assertSame($before, InventoryItem::count());
    }

    public function test_a_dry_run_shows_the_pairing_without_writing(): void
    {
        $response = $this->runSetup(['dry_run' => true])->assertOk();

        $this->assertTrue($response->json('dry_run'));
        $this->assertNotEmpty($response->json('links'));
        $this->assertSame(0, InventoryItem::tracked()->count());
    }

    public function test_it_reports_the_september_balance_the_counter_asked_for(): void
    {
        $cola = MenuItem::where('slug', 'drink-coca-cola')->first();
        $shelf = InventoryItem::where('name', 'Coke 1.5ltr')->first();

        // 100 bought on 3 Sep …
        Purchase::create([
            'date' => '2026-09-03', 'item_name' => 'Coke 1.5ltr', 'inventory_item_id' => $shelf->id,
            'vendor_name' => 'Metro', 'rate' => 169, 'quantity' => 100, 'unit' => 'pc',
            'base_qty' => 100, 'base_unit' => 'pc', 'cost_per_base_unit' => 169, 'total_amount' => 16900,
        ]);

        // … 50 sold across two bills, one of them written before the till sent
        // the menu-item id, so it resolves by name like every existing bill.
        foreach ([[30, $cola->id], [20, null]] as $i => [$qty, $menuItemId]) {
            $order = Order::create([
                'order_number' => (string) (3100 + $i), 'order_type' => 'Takeaway', 'source' => 'pos',
                'subtotal' => 220 * $qty, 'delivery_charge' => 0, 'extra_topping' => 0,
                'total' => 220 * $qty, 'status' => 'completed',
            ]);
            $order->items()->create([
                'menu_item_id' => $menuItemId, 'item_name' => 'Coca Cola', 'size' => '1.5Ltr',
                'unit_price' => 220, 'quantity' => $qty, 'line_total' => 220 * $qty,
            ]);
            $order->forceFill(['created_at' => '2026-09-1'.($i + 1).' 15:00:00'])->saveQuietly();
        }

        $response = $this->runSetup()->assertOk();

        $row = collect($response->json('balances'))
            ->firstWhere('sold_as', 'Coca Cola / 1.5Ltr');

        // Cast because JSON has no int/float distinction for a whole number.
        $this->assertSame(100.0, (float) $row['bought']);
        $this->assertSame(50.0, (float) $row['sold']);
        $this->assertSame(50.0, (float) $row['on_hand']);
    }

    public function test_only_an_admin_may_change_what_is_tracked(): void
    {
        $counter = User::create([
            'name' => 'Counter', 'email' => 'counter-setup@genzfoods.pk',
            'password' => bcrypt('secret1234'), 'role' => 'user',
        ]);

        $this->actingAs($counter)->postJson('/api/inventory/setup-category')->assertForbidden();
        $this->assertSame(0, InventoryItem::tracked()->count());
    }

    public function test_an_unknown_category_explains_itself_rather_than_500ing(): void
    {
        $this->runSetup(['category' => 'fizzy-pop'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'fizzy-pop'));
    }
}
