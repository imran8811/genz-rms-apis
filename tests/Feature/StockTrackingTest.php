<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock for resale goods: bought in, sold out, and nothing typed in between.
 *
 * The module used to report every ingredient as "Out" because `current_stock`
 * was only ever moved by the manual adjust button — purchases worked out how
 * much had arrived and never put it on a shelf, and sales never took anything
 * off one. These tests pin the two halves of that fix, and the three things that
 * make it usable rather than merely present: raw ingredients staying out of it,
 * drinks handed out inside deals still counting, and a balance that is re-derived
 * rather than nudged, so editing a bill cannot leave it drifting.
 */
class StockTrackingTest extends TestCase
{
    use RefreshDatabase;

    private MenuItem $cola;

    private InventoryItem $bottles;

    protected function setUp(): void
    {
        parent::setUp();

        // Everything here happens inside the stock window; the cut-off gets its
        // own test below.
        Setting::set('stock_opening_date', '2026-09-01');

        $category = Category::create([
            'slug' => 'cold-drinks', 'name' => 'Cold Drinks', 'type' => 'sized',
            'sizes' => ['350ml', '1.5Ltr'], 'sort_order' => 0, 'is_active' => true,
        ]);

        $this->cola = MenuItem::create([
            'category_id' => $category->id, 'slug' => 'drink-next-cola', 'name' => 'Next Cola',
            'price_type' => 'sized', 'prices' => ['350ml' => 60, '1.5Ltr' => 160],
            'is_active' => true, 'sort_order' => 0,
        ]);

        // A carton of 6 bottles: the shelf counts bottles, the invoice counts cartons.
        $this->bottles = InventoryItem::create([
            'name' => 'next cola 1.5ltr', 'unit' => 'pc', 'base_unit' => 'pc', 'pack_unit' => 'carton',
            'pack_size' => 6, 'pack_price' => 900, 'cost_per_base_unit' => 150, 'cost_per_unit' => 150,
            'min_stock' => 12, 'is_active' => true,
            'track_stock' => true, 'menu_item_id' => $this->cola->id, 'variant' => '1.5Ltr',
            'is_deal_default' => true,
        ]);
    }

    private function user(string $role = 'admin'): User
    {
        return User::firstOrCreate(
            ['email' => "stock-{$role}@genzfoods.pk"],
            ['name' => 'Stock '.$role, 'password' => bcrypt('secret1234'), 'role' => $role],
        );
    }

    /** A delivery of $cartons cartons, recorded the way Purchasing records one. */
    private function deliver(int $cartons): Purchase
    {
        $this->actingAs($this->user())
            ->postJson('/api/purchases', [
                'date' => '2026-09-10',
                'item_name' => $this->bottles->name,
                'inventory_item_id' => $this->bottles->id,
                'vendor_name' => 'Metro',
                'rate' => 900,
                'quantity' => $cartons,
                'unit' => 'carton',
            ])->assertCreated();

        return Purchase::latest('id')->first();
    }

    /** One bill, rung up at the till the way the billing screen rings one up. */
    private function sell(array $items): Order
    {
        $response = $this->actingAs($this->user())
            ->postJson('/api/orders', [
                'order_type' => 'Takeaway',
                'source' => 'pos',
                'subtotal' => 1000,
                'delivery_charge' => 0,
                'extra_topping' => 0,
                'total' => 1000,
                'status' => 'completed',
                'items' => $items,
            ])->assertCreated();

        return Order::findOrFail($response->json('id'));
    }

    private function colaLine(int $qty, string $size = '1.5Ltr'): array
    {
        return [
            'menu_item_slug' => 'drink-next-cola',
            'item_name' => 'Next Cola',
            'size' => $size,
            'unit_price' => 160,
            'quantity' => $qty,
            'line_total' => 160 * $qty,
        ];
    }

    private function stock(): float
    {
        return (float) $this->bottles->fresh()->current_stock;
    }

    public function test_a_purchase_puts_what_arrived_on_the_shelf_in_bottles_not_cartons(): void
    {
        $this->deliver(2);

        // 2 cartons of 6 — the pack size is what stops this reading as "2".
        $this->assertSame(12.0, $this->stock());
    }

    public function test_selling_a_drink_takes_it_off_the_shelf(): void
    {
        $this->deliver(2);
        $this->sell([$this->colaLine(3)]);

        $this->assertSame(9.0, $this->stock());
    }

    public function test_a_till_bill_records_which_menu_item_was_sold(): void
    {
        // The link the billing screen never used to send. Without it a bill line
        // is just a name, and every sale below would silently deduct nothing.
        $order = $this->sell([$this->colaLine(1)]);

        $this->assertSame($this->cola->id, $order->items()->first()->menu_item_id);
    }

    public function test_drinks_handed_out_inside_a_deal_come_off_the_default_for_that_size(): void
    {
        $this->deliver(2);

        // A deal never records the flavour — only "2x Drink 1.5Ltr" — so these
        // come off whichever item is the default for the size. Three deals sold.
        $this->sell([[
            'item_name' => 'Deal 4',
            'unit_price' => 3000,
            'quantity' => 3,
            'line_total' => 9000,
            'deal_selections' => ['Chicken Tikka', 'Fajita', '2x Drink 1.5Ltr'],
        ]]);

        $this->assertSame(6.0, $this->stock());
    }

    public function test_a_deals_other_extras_are_not_mistaken_for_drinks(): void
    {
        $this->deliver(1);

        $this->sell([[
            'item_name' => 'Deal 10',
            'unit_price' => 900,
            'quantity' => 1,
            'line_total' => 900,
            'deal_selections' => ['1 Zinger Burger', '1 Reg Fries', 'Drink 345ml'],
        ]]);

        // The burger and the fries are not drinks, and the 345ml is a size this
        // shelf does not hold, so nothing moves.
        $this->assertSame(6.0, $this->stock());
    }

    public function test_a_bill_selling_a_drink_both_ways_deducts_both(): void
    {
        $this->deliver(2);

        $this->sell([
            $this->colaLine(2),
            [
                'item_name' => 'Deal 3',
                'unit_price' => 1500,
                'quantity' => 1,
                'line_total' => 1500,
                'deal_selections' => ['Chicken Tikka', 'Drink 1.5Ltr'],
            ],
        ]);

        $this->assertSame(9.0, $this->stock());
    }

    public function test_cancelling_a_bill_puts_the_stock_back(): void
    {
        $this->deliver(2);
        $order = $this->sell([$this->colaLine(4)]);
        $this->assertSame(8.0, $this->stock());

        $this->actingAs($this->user())
            ->putJson("/api/orders/{$order->id}", ['status' => 'cancelled'])
            ->assertOk();

        // Cancelling is what takes a bill out of Sales; stock has to agree with
        // the money or one of the two is lying.
        $this->assertSame(12.0, $this->stock());
    }

    public function test_editing_a_bills_quantity_restates_the_shelf_rather_than_deducting_twice(): void
    {
        $this->deliver(2);
        $order = $this->sell([$this->colaLine(4)]);
        $lineId = $order->items()->first()->id;

        $this->actingAs($this->user())
            ->putJson("/api/orders/{$order->id}", [
                'items' => [['id' => $lineId, 'quantity' => 2, 'unit_price' => 160]],
            ])->assertOk();

        // 10, not 6: a running total nudged per write is exactly what drifts here.
        $this->assertSame(10.0, $this->stock());
    }

    public function test_deleting_a_purchase_takes_the_delivery_back_off(): void
    {
        $purchase = $this->deliver(2);
        $this->sell([$this->colaLine(1)]);

        $this->actingAs($this->user())
            ->deleteJson("/api/purchases/{$purchase->id}")
            ->assertNoContent();

        $this->assertSame(-1.0, $this->stock());
    }

    public function test_a_shelf_may_go_negative_because_that_is_the_evidence_deliveries_are_unrecorded(): void
    {
        $this->sell([$this->colaLine(3)]);

        // Clamping at zero would hide the only sign that Purchasing is not being
        // kept up, which is the thing worth knowing.
        $this->assertSame(-3.0, $this->stock());
    }

    public function test_a_raw_ingredient_is_not_reported_as_out_of_stock(): void
    {
        $flour = InventoryItem::create([
            'name' => 'Flour', 'unit' => 'g', 'base_unit' => 'g', 'pack_size' => 1000,
            'pack_price' => 120, 'min_stock' => 5000, 'is_active' => true,
        ]);

        // It is consumed through recipes in grams and nobody counts it off a
        // shelf. Reporting it "Out" is what buried the real shortages.
        $this->assertSame('Untracked', $flour->status);
        $this->assertSame('Out', $this->bottles->fresh()->status);
    }

    public function test_a_purchase_of_an_untracked_ingredient_moves_no_stock(): void
    {
        $flour = InventoryItem::create([
            'name' => 'Flour', 'unit' => 'g', 'base_unit' => 'g', 'pack_size' => 1000,
            'pack_price' => 120, 'is_active' => true,
        ]);

        $this->actingAs($this->user())->postJson('/api/purchases', [
            'date' => '2026-09-10', 'item_name' => 'Flour', 'inventory_item_id' => $flour->id,
            'vendor_name' => 'Metro', 'rate' => 120, 'quantity' => 5, 'unit' => 'kg',
        ])->assertCreated();

        $this->assertSame(0.0, (float) $flour->fresh()->current_stock);
        $this->assertSame('Untracked', $flour->fresh()->status);
    }

    public function test_trade_from_before_the_opening_date_is_history_not_stock(): void
    {
        // A delivery and a bill from last month: both recorded, neither counted,
        // because nobody was maintaining a shelf for them to move.
        Purchase::create([
            'date' => '2026-08-20', 'item_name' => $this->bottles->name,
            'inventory_item_id' => $this->bottles->id, 'vendor_name' => 'Metro',
            'rate' => 900, 'quantity' => 4, 'unit' => 'carton', 'base_qty' => 24,
            'base_unit' => 'pc', 'cost_per_base_unit' => 150, 'total_amount' => 3600,
        ]);

        $old = Order::create([
            'order_number' => '2999', 'order_type' => 'Takeaway', 'source' => 'pos',
            'subtotal' => 160, 'delivery_charge' => 0, 'extra_topping' => 0,
            'total' => 160, 'status' => 'completed',
        ]);
        $old->items()->create([
            'menu_item_id' => $this->cola->id, 'item_name' => 'Next Cola', 'size' => '1.5Ltr',
            'unit_price' => 160, 'quantity' => 2, 'line_total' => 320,
        ]);
        $old->forceFill(['created_at' => '2026-08-21 10:00:00'])->saveQuietly();

        app(StockService::class)->rebuild();

        $this->assertSame(0.0, $this->stock());
    }

    public function test_the_opening_date_is_read_at_the_counters_clock_not_the_servers(): void
    {
        // The app runs on UTC and the restaurant on UTC+5, so the first evening's
        // trade is stamped 2026-08-31 in UTC. Read naively it would be dropped.
        Setting::set('restaurant_timezone', 'Asia/Karachi');

        $order = Order::create([
            'order_number' => '3005', 'order_type' => 'Takeaway', 'source' => 'pos',
            'subtotal' => 160, 'delivery_charge' => 0, 'extra_topping' => 0,
            'total' => 160, 'status' => 'completed',
        ]);
        $order->items()->create([
            'menu_item_id' => $this->cola->id, 'item_name' => 'Next Cola', 'size' => '1.5Ltr',
            'unit_price' => 160, 'quantity' => 1, 'line_total' => 160,
        ]);
        // 1 Sept, 00:30 in Karachi — which is 31 Aug, 19:30 UTC.
        $order->forceFill(['created_at' => '2026-08-31 19:30:00'])->saveQuietly();

        app(StockService::class)->rebuild();

        $this->assertSame(-1.0, $this->stock());
    }

    public function test_a_stocktake_supersedes_everything_before_it(): void
    {
        $this->deliver(2);
        $this->sell([$this->colaLine(3)]);
        $this->assertSame(9.0, $this->stock());

        // Someone counts the shelf and finds 7 — two went missing. The count is
        // the truth, and it is not a delta.
        $this->actingAs($this->user())->postJson('/api/inventory/adjust', [
            'inventory_item_id' => $this->bottles->id,
            'type' => 'adjustment',
            'quantity' => 7,
            'note' => 'Stocktake',
        ])->assertOk();

        $this->assertSame(7.0, $this->stock());

        // And trade after it still moves from the counted figure.
        $this->sell([$this->colaLine(2)]);
        $this->assertSame(5.0, $this->stock());
    }

    public function test_a_rebuild_lands_on_the_same_answer_it_started_from(): void
    {
        $this->deliver(3);
        $this->sell([$this->colaLine(5)]);
        $before = $this->stock();

        app(StockService::class)->rebuild();

        // The point of deriving rather than accumulating: re-deriving is not a
        // repair, so there is never a reason not to run it.
        $this->assertSame($before, $this->stock());
        $this->assertSame(13.0, $before);
    }

    public function test_switching_tracking_off_clears_the_links_that_fed_it(): void
    {
        $this->deliver(2);

        $this->actingAs($this->user())
            ->putJson("/api/inventory/{$this->bottles->id}", ['track_stock' => false])
            ->assertOk();

        $fresh = $this->bottles->fresh();

        $this->assertFalse($fresh->track_stock);
        $this->assertNull($fresh->menu_item_id);
        $this->assertSame(0.0, (float) $fresh->current_stock);
        $this->assertSame('Untracked', $fresh->status);
    }

    public function test_only_one_item_can_be_the_deal_default_for_a_size(): void
    {
        $sprite = InventoryItem::create([
            'name' => 'sprite 1.5ltr', 'unit' => 'pc', 'base_unit' => 'pc',
            'pack_size' => 6, 'pack_price' => 1000, 'is_active' => true,
        ]);

        $this->actingAs($this->user())->putJson("/api/inventory/{$sprite->id}", [
            'track_stock' => true,
            'variant' => '1.5 Ltr',   // same size, spelt differently
            'is_deal_default' => true,
        ])->assertOk();

        // Two items claiming the size is not a conflict the ledger can resolve —
        // one of them would silently never be used.
        $this->assertFalse($this->bottles->fresh()->is_deal_default);
        $this->assertTrue($sprite->fresh()->is_deal_default);
    }

    public function test_sizes_spelt_differently_are_still_the_same_shelf(): void
    {
        $stock = app(StockService::class);

        // The menu sells a "350ml"; the deals and the ingredient master both call
        // the same bottle "345ml".
        $this->assertSame($stock->normalizeSize('345ml'), $stock->normalizeSize('350ml'));
        $this->assertSame($stock->normalizeSize('1.5Ltr'), $stock->normalizeSize('1.5 L'));
        $this->assertSame($stock->normalizeSize('1Ltr'), $stock->normalizeSize('1 litre'));
        $this->assertNotSame($stock->normalizeSize('500ml'), $stock->normalizeSize('1.5Ltr'));
        $this->assertSame('', $stock->normalizeSize(null));
    }
}
