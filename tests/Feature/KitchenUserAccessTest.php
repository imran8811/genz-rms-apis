<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderComplaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A `kitchen` login is the terminal in the back: it may work the orders board
 * and nothing else. The middleware is deny-by-default, so the point of these
 * tests is that the allowlist is neither too narrow (the board must work) nor
 * quietly widened by a route added later.
 */
class KitchenUserAccessTest extends TestCase
{
    use RefreshDatabase;

    private function kitchenUser(): User
    {
        return User::create([
            'name' => 'Kitchen',
            'email' => 'kitchen@example.test',
            'password' => 'secret1234',
            'role' => 'kitchen',
        ]);
    }

    private function order(): Order
    {
        return Order::create([
            'order_number' => '3005',
            'order_type' => 'Takeaway',
            'source' => 'pos',
            'subtotal' => 500,
            'delivery_charge' => 0,
            'extra_topping' => 0,
            'total' => 500,
            'status' => 'completed',
            'kitchen_status' => 'new',
        ]);
    }

    public function test_kitchen_user_can_read_the_board(): void
    {
        $this->actingAs($this->kitchenUser(), 'sanctum')
            ->getJson('/api/orders/kitchen')
            ->assertOk();
    }

    public function test_kitchen_user_can_move_an_order_through_the_board(): void
    {
        $order = $this->order();
        $user = $this->kitchenUser();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/kitchen-status", ['kitchen_status' => 'ready'])
            ->assertOk()
            ->assertJsonPath('kitchen_status', 'ready');

        // Marked ready by mistake — stepping it back is part of the board.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/kitchen-status", ['kitchen_status' => 'received'])
            ->assertOk()
            ->assertJsonPath('kitchen_status', 'received');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/eta", ['minutes' => 10])
            ->assertOk();
    }

    /**
     * The money screens, the POS feed, staff records — everything the board
     * doesn't need is closed. `staff` and `sales` stand in for the whole group:
     * the middleware allowlists individual URIs, so anything absent is blocked.
     */
    public function test_kitchen_user_is_blocked_from_the_rest_of_the_rms(): void
    {
        $user = $this->kitchenUser();

        foreach ([
            '/api/orders',
            '/api/orders/next-number',
            '/api/categories',
            '/api/sales/summary',
            '/api/staff',
            '/api/staff-payroll',
            '/api/expenses',
            '/api/inventory',
            '/api/costing/summary',
            '/api/settings',
        ] as $path) {
            $this->actingAs($user, 'sanctum')
                ->getJson($path)
                ->assertForbidden();
        }
    }

    /**
     * The complaints tab on the same board: the kitchen is *told* about a
     * complaint and can say it has seen it — which is what stops the alarm.
     *
     * It reads the **register** too. The board's tab is one day, and whether
     * the same dish has come back three times this week is a kitchen question
     * that cannot be answered from today alone.
     */
    public function test_kitchen_user_can_read_and_acknowledge_complaints(): void
    {
        $complaint = OrderComplaint::create([
            'order_id' => $this->order()->id,
            'body' => 'Pizza was cold.',
            'status' => 'new',
        ]);
        $user = $this->kitchenUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/complaints/kitchen')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/complaints')
            ->assertOk()
            ->assertJsonPath('data.0.id', $complaint->id);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/seen")
            ->assertOk()
            ->assertJsonPath('status', 'seen');
    }

    /**
     * Reading the register is not administering it: logging a complaint,
     * closing one off and deleting one all stay off the kitchen terminal.
     */
    public function test_kitchen_user_cannot_log_or_close_a_complaint(): void
    {
        $order = $this->order();
        $complaint = OrderComplaint::create([
            'order_id' => $order->id,
            'body' => 'Pizza was cold.',
            'status' => 'new',
        ]);
        $user = $this->kitchenUser();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/complaints", ['body' => 'made up'])
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/resolve")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/complaints/{$complaint->id}/reopen")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/complaints/{$complaint->id}")
            ->assertForbidden();
    }

    /** Asking "how much longer?" is the front desk's job, not the kitchen's. */
    public function test_kitchen_user_cannot_raise_a_time_question(): void
    {
        $order = $this->order();

        $this->actingAs($this->kitchenUser(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/eta-request")
            ->assertForbidden();
    }

    /** The restriction is on the kitchen role only — nobody else changes. */
    public function test_other_roles_are_unaffected(): void
    {
        $cashier = User::create([
            'name' => 'Cashier',
            'email' => 'cashier@example.test',
            'password' => 'secret1234',
            'role' => 'user',
        ]);

        $this->actingAs($cashier, 'sanctum')->getJson('/api/sales/summary')->assertOk();
        $this->actingAs($cashier, 'sanctum')->getJson('/api/orders/kitchen')->assertOk();
    }
}
