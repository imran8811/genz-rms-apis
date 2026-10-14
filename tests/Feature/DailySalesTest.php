<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /sales/daily` — one row per day, regular sales beside Food Panda's,
 * newest first and keyset-paged on the date for the Daily Sales page's
 * infinite scroll.
 */
class DailySalesTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function order(string $at, string $source, int $total, string $status = 'completed'): void
    {
        $order = Order::create([
            'order_number' => (string) (5000 + ++$this->n),
            'order_type' => 'Takeaway',
            'source' => $source,
            'subtotal' => $total,
            'delivery_charge' => 0,
            'extra_topping' => 0,
            'total' => $total,
            'status' => $status,
        ]);
        $order->forceFill(['created_at' => $at])->save();
    }

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@example.test'],
            ['name' => 'Owner', 'password' => 'secret1234', 'role' => 'admin'],
        );
    }

    public function test_splits_foodpanda_from_regular_sales_per_day(): void
    {
        $this->order('2026-10-12 10:00:00', 'pos', 1000);
        $this->order('2026-10-12 11:00:00', 'web', 500);
        $this->order('2026-10-12 12:00:00', 'foodpanda', 800);
        $this->order('2026-10-12 13:00:00', 'pos', 9999, 'cancelled');
        $this->order('2026-10-10 10:00:00', 'foodpanda', 300);

        $this->actingAs($this->admin())
            ->getJson('/api/sales/daily')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['date' => '2026-10-12', 'sales' => 1500, 'sales_orders' => 2, 'foodpanda' => 800, 'foodpanda_orders' => 1],
                    ['date' => '2026-10-10', 'sales' => 0, 'sales_orders' => 0, 'foodpanda' => 300, 'foodpanda_orders' => 1],
                ],
                'next_before' => null,
            ]);
    }

    public function test_is_admin_only(): void
    {
        foreach (['user', 'kitchen'] as $role) {
            $login = User::create([
                'name' => ucfirst($role), 'email' => "$role@example.test",
                'password' => 'secret1234', 'role' => $role,
            ]);
            $this->actingAs($login)->getJson('/api/sales/daily')->assertForbidden();
        }
    }

    public function test_pages_back_through_history_with_before(): void
    {
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $d) {
            $this->order("$d 10:00:00", 'pos', 100);
        }

        $first = $this->actingAs($this->admin())
            ->getJson('/api/sales/daily?limit=2')
            ->assertOk();
        $this->assertSame(['2026-10-03', '2026-10-02'], array_column($first->json('data'), 'date'));
        $this->assertSame('2026-10-02', $first->json('next_before'));

        $second = $this->getJson('/api/sales/daily?limit=2&before=2026-10-02')->assertOk();
        $this->assertSame(['2026-10-01'], array_column($second->json('data'), 'date'));
        $this->assertNull($second->json('next_before'));
    }
}
