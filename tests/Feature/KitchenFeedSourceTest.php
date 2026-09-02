<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The front desk is the single source of orders reaching the kitchen.
 *
 * Orders arriving through an integration — the website today, a phone app
 * later — are the counter's to deal with: it decides what happens and rings
 * them into the POS if they are to be cooked. The kitchen board must therefore
 * never carry them, and the split has to hold at the API, because a kitchen
 * login can call the feed directly and would otherwise see what the UI hides.
 */
class KitchenFeedSourceTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $number, string $source): Order
    {
        return Order::create([
            'order_number' => $number,
            'order_type' => 'Takeaway',
            'source' => $source,
            'subtotal' => 500,
            'delivery_charge' => 0,
            'extra_topping' => 0,
            'total' => 500,
            'status' => 'completed',
        ]);
    }

    /** Memoised: the helpers below call this repeatedly, and the email is unique. */
    private function counterUser(): User
    {
        return User::firstOrCreate(
            ['email' => 'desk@example.test'],
            ['name' => 'Front desk', 'password' => 'secret1234', 'role' => 'user'],
        );
    }

    public function test_kitchen_feed_carries_counter_orders_only(): void
    {
        $this->order('3005', 'pos');
        // Read off the Food Panda device and typed into the POS by the front
        // desk — a counter order like any other, and it must still be cooked.
        $this->order('3006', 'foodpanda');
        $this->order('3007', 'web');

        $numbers = collect(
            $this->actingAs($this->counterUser())
                ->getJson('/api/orders/kitchen')
                ->assertOk()
                ->json('orders')
        )->pluck('order_number');

        $this->assertEqualsCanonicalizing(['3005', '3006'], $numbers->all());
    }

    public function test_counter_board_carries_the_orders_the_kitchen_does_not(): void
    {
        $this->order('3005', 'pos');
        $this->order('3006', 'foodpanda');
        $this->order('3007', 'web');

        $this->assertSame(['3007'], $this->counterBoard());
    }

    /**
     * The counter keeps its own history — it has to be able to see what it
     * cancelled — so unlike the kitchen feed, a cancelled order stays on the
     * board. It is simply no longer waiting on anyone.
     */
    public function test_a_cancelled_online_order_stays_on_the_counter_board_but_reaches_no_kitchen(): void
    {
        $order = $this->order('3007', 'web');
        $order->update(['status' => 'cancelled']);

        $this->assertSame(['3007'], $this->counterBoard());
        $this->assertEmpty($this->counterWaiting());
        $this->assertEmpty($this->kitchenBoard());
    }

    /**
     * The kitchen feed is an allowlist, not a "not web" exclusion, so a source
     * added later reaches the board only when someone puts it on the list.
     * (A genuinely unknown value can't be exercised here — `orders.source` is
     * an enum, so the database rejects one outright.)
     */
    public function test_the_kitchen_feed_is_an_allowlist(): void
    {
        $this->assertSame(['pos', 'foodpanda'], Order::COUNTER_SOURCES);
        $this->assertNotContains('web', Order::COUNTER_SOURCES);
    }

    public function test_kitchen_login_cannot_reach_the_front_desks_inbox(): void
    {
        $kitchen = User::create([
            'name' => 'Kitchen',
            'email' => 'kitchen@example.test',
            'password' => 'secret1234',
            'role' => 'kitchen',
        ]);

        // A real order, so a 403 here is the middleware refusing rather than
        // route model binding failing to find anything.
        $order = $this->order('3007', 'web');

        $this->actingAs($kitchen)->getJson('/api/orders/counter')->assertForbidden();
        $this->actingAs($kitchen)->postJson("/api/orders/{$order->id}/release")->assertForbidden();
    }

    /**
     * The whole point of releasing rather than retyping: the order moves from
     * the inbox to the board as **the same row**, so Sales counts one sale.
     */
    public function test_releasing_moves_the_same_order_to_the_kitchen(): void
    {
        $order = $this->order('3007', 'web');

        $this->actingAs($this->counterUser())
            ->postJson("/api/orders/{$order->id}/release")
            ->assertOk()
            ->assertJsonPath('kitchen_status', 'new');

        $this->assertSame(1, Order::count(), 'releasing must not create a second row');
        $this->assertNotNull($order->refresh()->released_at);
        $this->assertSame('web', $order->source, 'the online channel must stay visible in reporting');

        $this->assertSame(['3007'], $this->kitchenBoard());
        // Off the waiting list, but still on the counter's board as sent.
        $this->assertEmpty($this->counterWaiting());
        $this->assertSame(['3007'], $this->counterBoard());
    }

    public function test_rejecting_cancels_the_order_and_it_reaches_nobody(): void
    {
        $order = $this->order('3007', 'web');

        $this->actingAs($this->counterUser())
            ->postJson("/api/orders/{$order->id}/reject")
            ->assertOk();

        // Sales counts `completed` only, so cancelling is what takes it out.
        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertEmpty($this->counterWaiting());
        $this->assertEmpty($this->kitchenBoard());
    }

    public function test_releasing_twice_keeps_the_original_time(): void
    {
        $order = $this->order('3007', 'web');
        $user = $this->counterUser();

        $this->actingAs($user)->postJson("/api/orders/{$order->id}/release")->assertOk();
        $first = $order->refresh()->released_at;

        $this->travel(2)->minutes();
        $this->actingAs($user)->postJson("/api/orders/{$order->id}/release")->assertOk();

        $this->assertEquals($first, $order->refresh()->released_at);
    }

    public function test_an_order_already_with_the_kitchen_cannot_be_rejected(): void
    {
        $order = $this->order('3007', 'web');
        $user = $this->counterUser();

        $this->actingAs($user)->postJson("/api/orders/{$order->id}/release")->assertOk();
        $this->actingAs($user)->postJson("/api/orders/{$order->id}/reject")->assertStatus(422);

        $this->assertSame('completed', $order->refresh()->status);
    }

    public function test_a_counter_order_cannot_be_released(): void
    {
        $order = $this->order('3005', 'pos');

        $this->actingAs($this->counterUser())
            ->postJson("/api/orders/{$order->id}/release")
            ->assertStatus(422);
    }

    /** @return array<int, string> */
    private function kitchenBoard(): array
    {
        return collect(
            $this->actingAs($this->counterUser())->getJson('/api/orders/kitchen')->json('orders')
        )->pluck('order_number')->all();
    }

    /** Everything on the counter's board, in every state. @return array<int, string> */
    private function counterBoard(): array
    {
        return collect($this->counterFeed())->pluck('order_number')->all();
    }

    /**
     * Only what is still waiting on the verification call — the same filter the
     * page and the alert apply, and the one that decides whether it alarms.
     *
     * @return array<int, string>
     */
    private function counterWaiting(): array
    {
        return collect($this->counterFeed())
            ->filter(fn ($o) => $o['released_at'] === null && $o['status'] !== 'cancelled')
            ->pluck('order_number')
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function counterFeed(): array
    {
        return $this->actingAs($this->counterUser())
            ->getJson('/api/orders/counter')
            ->json('orders');
    }
}
