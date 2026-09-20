<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Accepts online orders forwarded from genz-web-apis and records them in the RMS
 * POS with source='web'. Authenticated by a shared secret header (not Sanctum),
 * since this is service-to-service. Item lines carry the shared menu-item slug,
 * which we resolve to the local menu_items mirror by slug.
 */
class WebOrderController extends Controller
{
    public function __construct(private StockService $stock) {}

    public function store(Request $request): JsonResponse
    {
        $secret = config('genz.integration_secret');
        if (! $secret || ! hash_equals($secret, (string) $request->header('X-Integration-Secret'))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'web_order_number'        => 'nullable|string|max:50',
            'order_type'              => 'nullable|in:Dine-in,Takeaway,Delivery',
            'subtotal'                => 'required|integer|min:0',
            'delivery_charge'         => 'nullable|integer|min:0',
            'total'                   => 'required|integer|min:0',
            'payment_method'          => 'nullable|string|max:20',
            'customer.name'           => 'nullable|string|max:255',
            'customer.phone'          => 'nullable|string|max:30',
            'address'                 => 'nullable|string',
            'notes'                   => 'nullable|string',
            'items'                   => 'required|array|min:1',
            'items.*.slug'            => 'nullable|string',
            'items.*.item_name'       => 'required|string',
            'items.*.size'            => 'nullable|string',
            'items.*.unit_price'      => 'required|integer|min:0',
            'items.*.quantity'        => 'required|integer|min:1',
            'items.*.line_total'      => 'required|integer|min:0',
            'items.*.deal_selections' => 'nullable|array',
        ]);

        $idBySlug = MenuItem::whereIn('slug', collect($data['items'])->pluck('slug')->filter()->unique())
            ->pluck('id', 'slug');

        // Allocate an order number and insert, retrying if a concurrent order
        // grabbed the same number (the order_number unique constraint).
        for ($attempt = 0; $attempt < 25; $attempt++) {
            try {
                $order = DB::transaction(function () use ($data, $idBySlug) {
                    $order = Order::create([
                        'order_number'    => $this->allocateOrderNumber(),
                        'order_type'      => $data['order_type'] ?? 'Delivery',
                        'source'          => 'web',
                        'subtotal'        => $data['subtotal'],
                        'delivery_charge' => $data['delivery_charge'] ?? 0,
                        'extra_topping'   => 0,
                        'total'           => $data['total'],
                        'status'          => 'completed',
                        'notes'           => $this->buildNotes($data),
                    ]);

                    $order->items()->createMany(array_map(fn ($item) => [
                        'menu_item_id'    => isset($item['slug']) ? ($idBySlug[$item['slug']] ?? null) : null,
                        'item_name'       => $item['item_name'],
                        'size'            => $item['size'] ?? null,
                        'unit_price'      => $item['unit_price'],
                        'quantity'        => $item['quantity'],
                        'line_total'      => $item['line_total'],
                        'deal_selections' => $item['deal_selections'] ?? null,
                    ], $data['items']));

                    // An online order is a sale from the moment it is forwarded,
                    // so it comes off the shelf now; rejecting it puts it back.
                    $this->stock->syncOrder($order);

                    return $order;
                });

                return response()->json(['order_number' => $order->order_number], 201);
            } catch (QueryException $e) {
                if ($this->isDuplicateOrderNumber($e) && $attempt < 24) {
                    usleep(30000); // 30ms then re-allocate a fresh number

                    continue;
                }
                throw $e;
            }
        }

        return response()->json(['message' => 'Could not allocate an order number.'], 500);
    }

    private function buildNotes(array $data): string
    {
        $customer = $data['customer'] ?? [];

        return implode("\n", array_filter([
            'ONLINE ORDER'.(! empty($data['web_order_number']) ? ' — '.$data['web_order_number'] : ''),
            ! empty($customer['name'])
                ? 'Customer: '.$customer['name'].(! empty($customer['phone']) ? ' ('.$customer['phone'].')' : '')
                : null,
            ! empty($data['address']) ? 'Address: '.$data['address'] : null,
            ! empty($data['payment_method']) ? 'Payment: '.strtoupper($data['payment_method']) : null,
            ! empty($data['notes']) ? 'Note: '.$data['notes'] : null,
        ]));
    }

    /**
     * Next free order number: MAX+1 (same sequence the POS uses, starts at 3005),
     * then skip forward past any numbers that already exist. Skipping past existing
     * numbers defends against gaps / non-numeric rows that make a plain MAX+1 collide.
     */
    private function allocateOrderNumber(): string
    {
        $next = max((int) Order::max(DB::raw('CAST(order_number AS UNSIGNED)')) + 1, 3005);

        while (Order::where('order_number', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }

    private function isDuplicateOrderNumber(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains($e->getMessage(), 'orders_order_number_unique');
    }
}

