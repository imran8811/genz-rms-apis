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
