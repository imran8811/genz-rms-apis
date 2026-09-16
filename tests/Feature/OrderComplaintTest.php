<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderComplaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer complaints: taken at the counter, alarmed in the kitchen, closed at
 * the counter. What these pin down is the state machine — only `new` alarms,
 * acknowledgement and resolution are different people's jobs, and neither of
 * them ever moves a complaint backwards into alarming again.
 */
class OrderComplaintTest extends TestCase
{
    use RefreshDatabase;

    private function counterUser(): User
    {
        return User::create([
            'name' => 'Cashier',
            'email' => 'cashier@example.test',
            'password' => 'secret1234',
            'role' => 'user',
        ]);
    }

    private function order(string $number = '3005'): Order
    {
        return Order::create([
            'order_number' => $number,
            'order_type' => 'Takeaway',
            'source' => 'pos',
            'subtotal' => 500,
            'delivery_charge' => 0,
            'extra_topping' => 0,
            'total' => 500,
            'status' => 'completed',
            'kitchen_status' => 'ready',
        ]);
    }

    public function test_front_desk_logs_a_complaint_against_an_order(): void
    {
        $order = $this->order();
        $user = $this->counterUser();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/complaints", ['body' => '  Pizza was cold.  '])
            ->assertCreated()
            ->assertJsonPath('status', 'new')
            ->assertJsonPath('body', 'Pizza was cold.')
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('logged_by_name', 'Cashier')
            // The order rides along so the kitchen card can show the bill.
            ->assertJsonPath('order.order_number', '3005');
    }

    public function test_a_complaint_needs_a_body(): void
    {
        $order = $this->order();

        $this->actingAs($this->counterUser(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/complaints", ['body' => ''])
            ->assertStatus(422);
    }

    public function test_the_kitchen_feed_returns_todays_complaints_newest_first(): void
    {
        $old = OrderComplaint::create([
            'order_id' => $this->order('3005')->id,
            'body' => 'Cold',
            'status' => 'new',
        ]);
        $new = OrderComplaint::create([
            'order_id' => $this->order('3006')->id,
            'body' => 'Missing garlic sauce',
            'status' => 'new',
        ]);
        // Same timestamp in a fast test run, so the id tiebreak is what orders
        // these — which is exactly the case the board hits during a rush.
        $old->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->actingAs($this->counterUser(), 'sanctum')
            ->getJson('/api/complaints/kitchen')
            ->assertOk()
            ->assertJsonPath('complaints.0.id', $new->id)
            ->assertJsonPath('complaints.1.id', $old->id);
    }

    public function test_the_kitchen_feed_keeps_acknowledged_complaints(): void
    {
        $complaint = OrderComplaint::create([
            'order_id' => $this->order()->id,
            'body' => 'Cold',
            'status' => 'seen',
            'seen_at' => now(),
        ]);

        // Still on the tab: it is the kitchen's record of what came back today,
        // not just a list of things still making noise.
        $this->actingAs($this->counterUser(), 'sanctum')
            ->getJson('/api/complaints/kitchen')
            ->assertOk()
            ->assertJsonPath('complaints.0.id', $complaint->id);
    }

    public function test_acknowledging_stops_the_alarm_and_is_idempotent(): void
    {
        $complaint = OrderComplaint::create([
            'order_id' => $this->order()->id,
            'body' => 'Cold',
            'status' => 'new',
        ]);
        $user = $this->counterUser();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/seen")
            ->assertOk()
            ->assertJsonPath('status', 'seen');

        $seenAt = $complaint->refresh()->seen_at;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/seen")
            ->assertOk()
            ->assertJsonPath('status', 'seen');

        // Two terminals pressing OK must not move the time it was acknowledged.
        $this->assertEquals($seenAt->toIso8601String(), $complaint->refresh()->seen_at->toIso8601String());
    }

    public function test_acknowledging_never_reopens_a_resolved_complaint(): void
    {
        $complaint = OrderComplaint::create([
            'order_id' => $this->order()->id,
            'body' => 'Cold',
            'status' => 'resolved',
            'seen_at' => now(),
            'resolved_at' => now(),
        ]);

        $this->actingAs($this->counterUser(), 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/seen")
            ->assertOk()
            ->assertJsonPath('status', 'resolved');
    }

    public function test_resolving_backfills_the_acknowledgement(): void
    {
        $complaint = OrderComplaint::create([
            'order_id' => $this->order()->id,
            'body' => 'Cold',
            'status' => 'new',
        ]);

        // Dealt with at the counter there and then — it would otherwise read as
        // a complaint nobody ever saw.
        $this->actingAs($this->counterUser(), 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/resolve", ['resolution' => 'Remade it'])
            ->assertOk()
            ->assertJsonPath('status', 'resolved')
            ->assertJsonPath('resolution', 'Remade it');

        $this->assertNotNull($complaint->refresh()->seen_at);
        $this->assertNotNull($complaint->resolved_at);
    }

    public function test_reopening_returns_it_to_seen_not_new(): void
    {
        $complaint = OrderComplaint::create([
            'order_id' => $this->order()->id,
            'body' => 'Cold',
            'status' => 'resolved',
            'seen_at' => now(),
            'resolved_at' => now(),
        ]);

        // Back to `new` would set the kitchen's alarm off over something it
        // acknowledged an hour ago.
        $this->actingAs($this->counterUser(), 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/reopen")
            ->assertOk()
            ->assertJsonPath('status', 'seen')
            ->assertJsonPath('resolved_at', null);
    }

    public function test_the_register_filters_by_status_and_carries_the_order(): void
    {
        $open = OrderComplaint::create([
            'order_id' => $this->order('3005')->id,
            'body' => 'Cold',
            'status' => 'seen',
            'seen_at' => now(),
        ]);
        OrderComplaint::create([
            'order_id' => $this->order('3006')->id,
            'body' => 'Late',
            'status' => 'resolved',
            'seen_at' => now(),
            'resolved_at' => now(),
        ]);

        $this->actingAs($this->counterUser(), 'sanctum')
            ->getJson('/api/complaints?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.order.order_number', '3005');
    }

    public function test_sales_rows_say_whether_an_order_came_back(): void
    {
        $order = $this->order();
        OrderComplaint::create(['order_id' => $order->id, 'body' => 'Cold', 'status' => 'new']);

        $this->actingAs($this->counterUser(), 'sanctum')
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('data.0.complaints_count', 1);
    }

    public function test_deleting_an_order_takes_its_complaints_with_it(): void
    {
        $order = $this->order();
        OrderComplaint::create(['order_id' => $order->id, 'body' => 'Cold', 'status' => 'new']);

        $this->actingAs($this->counterUser(), 'sanctum')
            ->deleteJson("/api/orders/{$order->id}")
            ->assertNoContent();

        $this->assertSame(0, OrderComplaint::count());
    }

    public function test_an_admin_deletes_a_complaint_for_good(): void
    {
        $order = $this->order();
        $complaint = OrderComplaint::create([
            'order_id' => $order->id, 'body' => 'Logged against the wrong bill', 'status' => 'new',
        ]);

        $admin = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'secret1234',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/complaints/{$complaint->id}")
            ->assertNoContent();

        // Hard: there is no soft-delete column to hide behind, and the order
        // itself is untouched — only the complaint was a mistake.
        $this->assertSame(0, OrderComplaint::count());
        $this->assertNotNull($order->fresh());
    }

    public function test_the_counter_cannot_delete_a_complaint(): void
    {
        $order = $this->order();
        $complaint = OrderComplaint::create([
            'order_id' => $order->id, 'body' => 'Cold', 'status' => 'new',
        ]);

        $this->actingAs($this->counterUser(), 'sanctum')
            ->deleteJson("/api/complaints/{$complaint->id}")
            ->assertForbidden();

        $this->assertSame(1, OrderComplaint::count());
    }
}
