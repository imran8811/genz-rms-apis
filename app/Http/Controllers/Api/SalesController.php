<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffFoodLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        // One scope for both the orders and the staff-food query. Priority:
        // explicit ?date= (single day) > ?month=YYYY-MM > ?period= (today default).
        $applyScope = function ($q, string $col) use ($request) {
            if ($request->filled('date')) {
                $q->whereDate($col, $request->date);
            } elseif ($request->filled('month')) {
                [$y, $m] = explode('-', $request->month);
                $q->whereYear($col, $y)->whereMonth($col, $m);
            } else {
                match ($request->get('period', 'today')) {
                    'today' => $q->whereDate($col, today()),
                    'week'  => $q->whereBetween($col, [now()->startOfWeek(), now()->endOfWeek()]),
                    'month' => $q->whereMonth($col, now()->month)->whereYear($col, now()->year),
                    'year'  => $q->whereYear($col, now()->year),
                    default => $q->whereDate($col, today()),
                };
            }

            return $q;
        };

        $query = Order::where('status', 'completed');
        $applyScope($query, 'created_at');

        $orders   = $query->get();
        $revenue  = $orders->sum('total');
        $count    = $orders->count();
        $avgValue = $count > 0 ? (int) round($revenue / $count) : 0;

        // Food Panda is a fourth type here, not a flavour of Takeaway: it is how
        // the counter reads its own day (the money arrives through the app, not
        // the drawer), so a Food Panda order is counted under 'Food Panda' only,
        // never also under Takeaway.
        $byType = $orders
            ->groupBy(fn (Order $o) => $o->source === 'foodpanda' ? 'Food Panda' : $o->order_type)
            ->map(fn ($g) => [
                'count'   => $g->count(),
                'revenue' => $g->sum('total'),
            ]);

        $foodpandaOrders = $orders->where('source', 'foodpanda');

        // Staff food consumed in the same period (mirrors the order scope,
        // but on the food log's consumed_at column).
        $foodQuery = $applyScope(StaffFoodLog::query(), 'consumed_at');

        // Orders placed in the two service windows after opening (hours are 24h):
        // the first two hours of trade and the two after them. These move with the
        // opening time — at 1PM–1AM that is 13:00–15:00 and 15:00–17:00.
        // Timestamps are stored in UTC, so convert to the restaurant's local zone first.
        $openingOrders = $orders->filter(
            fn ($o) => in_array((int) $o->created_at->copy()->setTimezone('Asia/Karachi')->format('H'), [13, 14], true)
        );
        $middayOrders = $orders->filter(
            fn ($o) => in_array((int) $o->created_at->copy()->setTimezone('Asia/Karachi')->format('H'), [15, 16], true)
        );

        return response()->json([
            'revenue'    => $revenue,
            'orders'     => $count,
            'avg_value'  => $avgValue,
            'deliveries' => $orders->where('order_type', 'Delivery')->count(),
            'by_type'    => $byType,
            'foodpanda'  => [
                'orders'  => $foodpandaOrders->count(),
                'revenue' => $foodpandaOrders->sum('total'),
            ],
            'staff_food' => [
                'orders' => $foodQuery->count(),
                'total'  => (int) $foodQuery->sum('total_amount'),
            ],
            'window_1_3' => [
                'orders'  => $openingOrders->count(),
                'revenue' => $openingOrders->sum('total'),
            ],
            'window_3_5' => [
                'orders'  => $middayOrders->count(),
                'revenue' => $middayOrders->sum('total'),
            ],
        ]);
    }

    public function byCategory(Request $request): JsonResponse
    {
        $period = $request->get('period', 'month');

        $dateFilter = match ($period) {
            'today' => ['>=', today()],
            'week'  => ['>=', now()->startOfWeek()],
            'year'  => ['>=', now()->startOfYear()],
            default => ['>=', now()->startOfMonth()],
        };

        $data = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->join('categories', 'menu_items.category_id', '=', 'categories.id')
            ->where('orders.status', 'completed')
            ->where('orders.created_at', $dateFilter[0], $dateFilter[1])
            ->groupBy('categories.id', 'categories.name')
            ->select('categories.name as category', DB::raw('SUM(order_items.quantity) as qty'), DB::raw('SUM(order_items.line_total) as revenue'))
            ->orderByDesc('revenue')
            ->get();

        return response()->json($data);
    }

    /**
     * One row per trading day, newest first: the till's own sales beside Food
     * Panda's, split the same way `summary` splits them (a Food Panda order is
     * Food Panda only, never also regular). Days are bucketed on DATE(created_at)
     * exactly as `summary?date=` reads a day, so a row here matches the Sales
     * page for that date.
     *
     * Keyset-paged on the date so the Daily Sales page can keep scrolling back:
     * pass `next_before` as `?before=` for the next page; it is null once history
     * runs out. Days with no completed orders are skipped rather than shown as zeros.
     */
    public function daily(Request $request): JsonResponse
    {
        // Admins only: day-by-day takings are the owner's figures, not the counter's.
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Only an admin can view daily sales.'], 403);
        }

        $validated = $request->validate([
            'before' => ['nullable', 'date_format:Y-m-d'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($validated['limit'] ?? 30);

        $rows = Order::where('status', 'completed')
            ->when($validated['before'] ?? null, fn ($q, $before) => $q->whereDate('created_at', '<', $before))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw("SUM(CASE WHEN source = 'foodpanda' THEN 0 ELSE total END) as sales"),
                DB::raw("SUM(CASE WHEN source = 'foodpanda' THEN 0 ELSE 1 END) as sales_orders"),
                DB::raw("SUM(CASE WHEN source = 'foodpanda' THEN total ELSE 0 END) as foodpanda"),
                DB::raw("SUM(CASE WHEN source = 'foodpanda' THEN 1 ELSE 0 END) as foodpanda_orders"),
            )
            ->orderByDesc('date')
            ->limit($limit + 1)
            ->get();

        $page = $rows->take($limit)->map(fn ($r) => [
            'date'             => (string) $r->date,
            'sales'            => (int) $r->sales,
            'sales_orders'     => (int) $r->sales_orders,
            'foodpanda'        => (int) $r->foodpanda,
            'foodpanda_orders' => (int) $r->foodpanda_orders,
        ])->values();

        return response()->json([
            'data'        => $page,
            'next_before' => $rows->count() > $limit ? $page->last()['date'] : null,
        ]);
    }

    public function byDay(Request $request): JsonResponse
    {
        $days = (int) $request->get('days', 7);

        $data = Order::where('status', 'completed')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as orders'), DB::raw('SUM(total) as revenue'))
            ->orderBy('date')
            ->get();

        return response()->json($data);
    }
}
