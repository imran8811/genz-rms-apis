<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // complaints_count, not the complaints themselves: the sales list only
        // needs to know an order came back, so its row can offer to open the
        // complaint rather than pretending the bill was the end of it.
        $query = Order::with(['items'])->withCount('complaints')->latest();

        if ($request->has('date')) {
            $query->whereDate('created_at', $request->date);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('order_type')) {
            $query->where('order_type', $request->order_type);
        }
        if ($request->has('source')) {
            $query->where('source', $request->source);
        }

        return response()->json($query->paginate(50));
    }

    /**
     * Live feed for the kitchen orders board: one day's non-cancelled orders,
     * oldest first, so a newly placed order simply appends to the end of the
     * board. Polled every few seconds by the frontend.
     *
     * **Only orders the front desk has put through appear here** — counter
     * sources, plus online orders the counter has released. An order forwarded
     * in from the website is invisible until then. Filtering here rather than in
     * the board's rendering is deliberate: a kitchen login can call this
     * endpoint directly, so hiding the slips in the UI would not actually keep
     * them off the kitchen's screen.
     */
    public function kitchen(Request $request): JsonResponse
    {
        $date = $request->query('date') ?: now()->toDateString();

        $orders = Order::with('items')
            ->whereDate('created_at', $date)
            ->where('status', '!=', 'cancelled')
            ->visibleToKitchen()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'date' => $date,
            'server_time' => now()->toIso8601String(),
            'orders' => $orders,
        ]);
    }

    /**
     * The counter's board — what `kitchen()` is to the kitchen. One day's orders
     * that arrived through an integration, **in every state**: still waiting on
     * the verification call, already sent through, or cancelled. The counter
     * needs its own history of what it did, so unlike the kitchen feed this one
     * keeps cancelled rows; the page splits them into tabs.
     *
     * Deliberately **not** on `RestrictKitchenUser`'s allowlist: a kitchen login
     * gets a 403 here, so the board terminal cannot reach these orders at all.
     */
    public function counter(Request $request): JsonResponse
    {
        $date = $request->query('date') ?: now()->toDateString();

        $orders = Order::with('items')
            ->whereDate('created_at', $date)
            ->fromIntegration()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'date' => $date,
            'server_time' => now()->toIso8601String(),
            'orders' => $orders,
        ]);
    }

    /**
     * Front desk sends an online order through to the kitchen.
     *
     * This releases **the order that already exists** rather than creating a
     * counter copy of it: one sale stays one row, so Sales counts the money
     * once and the online channel is still visible in `source`. From here it is
     * an ordinary slip on the board — `kitchen_status` is untouched, so it
     * arrives as `new` and rings like any other.
     *
     * `created_at` is deliberately left alone: the customer has been waiting
     * since they placed it, and the board's age should say so rather than
     * restarting the clock at the moment the counter got to it.
     */
    public function release(Order $order): JsonResponse
    {
        if ($order->isCounterOrder()) {
            return response()->json([
                'message' => 'That order was rung up at the counter — it is already with the kitchen.',
            ], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'That order was cancelled.'], 422);
        }

        // Idempotent: two terminals pressing at once must not move the time the
        // kitchen has been working to.
        if (! $order->released_at) {
            $order->released_at = now();
            $order->save();
        }

        return response()->json($order->refresh()->load('items'));
    }

    /**
     * Front desk refuses an online order — a duplicate, a hoax, something out of
     * stock. Cancelling is what takes it out of Sales (which counts `completed`
     * only) and off every screen, so nothing is left acknowledged-but-forgotten.
     *
     * Only before release: once the kitchen has it, cancelling is a decision
     * about food already being cooked and belongs on the board, not here.
     */
    public function reject(Order $order): JsonResponse
    {
        if ($order->isCounterOrder()) {
            return response()->json([
                'message' => 'That order was rung up at the counter — cancel it from Sales.',
            ], 422);
        }

        if ($order->released_at) {
            return response()->json([
                'message' => 'That order has already gone to the kitchen.',
            ], 422);
        }

        $order->status = 'cancelled';
        $order->save();

        return response()->json($order->refresh()->load('items'));
    }

    /**
     * Kitchen acknowledges ("received") or completes ("ready") an order.
     * Idempotent: re-sending the same status keeps the original timestamps, and
     * marking ready without a prior receive backfills received_at.
     */
    public function kitchenStatus(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'kitchen_status' => 'required|in:new,received,ready',
        ]);

        $status = $data['kitchen_status'];

        // Assigned attribute-by-attribute on purpose: mass assignment would be
        // silently dropped if a deployed Order model were missing these from
        // $fillable, which reads as "saved" to the caller but never persists.
        $order->kitchen_status = $status;

        if ($status === 'received' && ! $order->received_at) {
            $order->received_at = now();
        }
        if ($status === 'ready') {
            $order->received_at = $order->received_at ?? now();
            $order->ready_at = $order->ready_at ?? now();
            // Nothing left to estimate — drop any outstanding time question so
            // the slip stops alarming instead of asking about a finished order.
            $order->eta_requested_at = null;
        }
        if ($status === 'new') {
            $order->received_at = null;
            $order->ready_at = null;
            // Back to square one: a time quoted against the old run is worthless.
            $order->eta_requested_at = null;
            $order->eta_minutes = null;
            $order->eta_set_at = null;
        }

        $order->save();

        return response()->json($order->refresh()->load('items'));
    }

    /**
     * Front desk asks the kitchen how much longer an order will take — a
     * customer is standing there asking. Raises the question on the kitchen
     * board (a separate alarm from a new order) until it is answered.
     */
    public function etaRequest(Order $order): JsonResponse
    {
        if ($order->kitchen_status === 'ready') {
            return response()->json(['message' => 'That order is already ready.'], 422);
        }

        // A fresh question clears the previous answer: the front desk is asking
        // again precisely because the old number no longer helps, and leaving it
        // on the slip would let them quote a time the kitchen hasn't restated.
        $order->eta_requested_at = now();
        $order->eta_minutes = null;
        $order->eta_set_at = null;
        $order->save();

        return response()->json($order->refresh()->load('items'));
    }

    /**
     * Kitchen answers with the minutes still needed. Clears the question (the
     * alarm stops on every board) and stamps when it was said, so the front desk
     * counts down from the answer rather than repeating it an hour later.
     */
    public function eta(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'minutes' => 'required|integer|min:0|max:240',
        ]);

        // Assigned attribute-by-attribute for the same reason as kitchenStatus:
        // a deployed model missing these from $fillable would drop a mass
        // assignment silently and report success without saving anything.
        $order->eta_minutes = $data['minutes'];
        $order->eta_set_at = now();
        $order->eta_requested_at = null;

        // Answering *is* an acknowledgement — the kitchen is plainly looking at
        // the slip — so an order still sitting on "new" moves on rather than
        // leaving the new-order alarm ringing behind the answer.
        if ($order->kitchen_status === 'new') {
            $order->kitchen_status = 'received';
            $order->received_at = $order->received_at ?? now();
        }

        $order->save();

        return response()->json($order->refresh()->load('items'));
    }

    /**
     * Sequential bill number: starts at 3005 and increments by 1 per order.
     */
    private function computeNextNumber(): int
    {
        $lastNumber = (int) Order::max(DB::raw('CAST(order_number AS UNSIGNED)'));

        return max($lastNumber + 1, 3005);
    }

    public function nextNumber(): JsonResponse
    {
        return response()->json(['next' => $this->computeNextNumber()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_type' => 'required|in:Dine-in,Takeaway,Delivery',
            'source' => 'nullable|in:pos,foodpanda',
            'subtotal' => 'required|integer|min:0',
            'delivery_charge' => 'integer|min:0',
            'extra_topping' => 'integer|min:0',
            'total' => 'required|integer|min:0',
            'status' => 'in:completed,cancelled',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => 'nullable|exists:menu_items,id',
            'items.*.item_name' => 'required|string',
            'items.*.size' => 'nullable|string',
            'items.*.unit_price' => 'required|integer|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.line_total' => 'required|integer|min:0',
            'items.*.deal_selections' => 'nullable|array',
        ]);

        return DB::transaction(function () use ($data) {
            $orderNumber = (string) $this->computeNextNumber();
            $data['source'] = $data['source'] ?? 'pos';
            $order = Order::create([...$data, 'order_number' => $orderNumber]);
            $order->items()->createMany($data['items']);

            // refresh() so the response carries DB-level defaults (kitchen_status)
            // rather than nulls the caller would have to guess at.
            return response()->json($order->refresh()->load('items'), 201);
        });
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json($order->load(['items', 'complaints'])->loadCount('complaints'));
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => 'sometimes|in:completed,cancelled',
            'order_type' => 'sometimes|in:Dine-in,Takeaway,Delivery',
            'notes' => 'sometimes|nullable|string|max:500',
            'delivery_charge' => 'sometimes|integer|min:0',
            'extra_topping' => 'sometimes|integer|min:0',
            'items' => 'sometimes|array|min:1',
            'items.*.id' => 'required_with:items|integer',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_price' => 'required_with:items|integer|min:0',
        ]);

        return DB::transaction(function () use ($data, $order) {
            if (isset($data['items'])) {
                $subtotal = 0;
                foreach ($data['items'] as $item) {
                    $lineTotal = $item['quantity'] * $item['unit_price'];
                    $subtotal += $lineTotal;
                    $order->items()->where('id', $item['id'])->update([
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $lineTotal,
                    ]);
                }
                unset($data['items']);
                $data['subtotal'] = $subtotal;
                $data['total'] = $subtotal
                    + ($data['delivery_charge'] ?? $order->delivery_charge)
                    + ($data['extra_topping'] ?? $order->extra_topping);
            }

            $order->update($data);

            return response()->json($order->refresh()->load('items'));
        });
    }

    public function destroy(Order $order): JsonResponse
    {
        $order->delete();

        return response()->json(null, 204);
    }
}
