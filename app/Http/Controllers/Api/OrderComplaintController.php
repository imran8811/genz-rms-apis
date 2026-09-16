<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderComplaint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer complaints against an order — logged at the counter, answered in the
 * kitchen, closed at the counter.
 *
 * See App\Models\OrderComplaint for what the three states mean and why only
 * `new` alarms. The split across two feeds mirrors the orders board:
 * `kitchen()` is one day of complaints for the board (and is the only complaint
 * endpoint a kitchen login may call); `index()` is the front desk's register,
 * which reads a date range and keeps resolved rows.
 */
class OrderComplaintController extends Controller
{
    /**
     * The complaints module's list: every complaint, newest first, with the
     * order it is about (items included, so the page can open one without a
     * second round trip).
     *
     * Filters: `status` (or `open` for new+seen), `order_id`, `date`, or a
     * `from`/`to` range. Unfiltered it returns the most recent page — a
     * complaint is read soon after it lands or not at all.
     */
    public function index(Request $request): JsonResponse
    {
        $query = OrderComplaint::with(['order.items'])->latest('id');

        if ($request->filled('status')) {
            $status = $request->query('status');
            if ($status === 'open') {
                $query->open();
            } else {
                $query->where('status', $status);
            }
        }
        if ($request->filled('order_id')) {
            $query->where('order_id', $request->query('order_id'));
        }
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->query('date'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }

        return response()->json($query->paginate(100));
    }

    /**
     * The kitchen board's complaints tab: one day of complaints, newest first.
     *
     * Every state is returned, not just the unacknowledged ones — the tab is
     * also the kitchen's record of what came back today, and a complaint that
     * vanished the moment someone pressed OK would be impossible to re-read.
     * The board alarms on `status === 'new'` alone.
     */
    public function kitchen(Request $request): JsonResponse
    {
        $date = $request->query('date') ?: now()->toDateString();

        $complaints = OrderComplaint::with(['order.items'])
            ->whereDate('created_at', $date)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'date' => $date,
            'server_time' => now()->toIso8601String(),
            'complaints' => $complaints,
        ]);
    }

    /**
     * Front desk logs a complaint against an order.
     *
     * Nothing is validated about *which* order: a complaint about a cancelled or
     * a days-old bill is still a complaint, and refusing it would only mean the
     * counter writing it somewhere the kitchen never sees.
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $user = $request->user();

        $complaint = OrderComplaint::create([
            'order_id' => $order->id,
            'body' => trim($data['body']),
            'status' => 'new',
            'logged_by' => $user?->id,
            // Snapshotted so the complaint stays attributable after that login
            // is deleted — the foreign key goes null, this doesn't.
            'logged_by_name' => $user?->name,
        ]);

        return response()->json($complaint->load(['order.items']), 201);
    }

    /**
     * Kitchen presses OK: it has seen the complaint, and the board goes quiet.
     *
     * Idempotent, and it never moves a complaint backwards — pressing OK on one
     * the counter has already resolved must not reopen it.
     */
    public function seen(OrderComplaint $complaint): JsonResponse
    {
        if ($complaint->status === 'new') {
            $complaint->status = 'seen';
            $complaint->seen_at = now();
            $complaint->save();
        }

        return response()->json($complaint->refresh()->load(['order.items']));
    }

    /**
     * Front desk closes a complaint off — refunded, remade, explained.
     *
     * `seen_at` is backfilled: a complaint resolved before the kitchen ever
     * acknowledged it (the counter dealt with it there and then) would otherwise
     * read as never seen by anyone.
     */
    public function resolve(Request $request, OrderComplaint $complaint): JsonResponse
    {
        $data = $request->validate([
            'resolution' => 'nullable|string|max:500',
        ]);

        $complaint->status = 'resolved';
        $complaint->seen_at = $complaint->seen_at ?? now();
        $complaint->resolved_at = $complaint->resolved_at ?? now();
        if (array_key_exists('resolution', $data)) {
            $complaint->resolution = $data['resolution'] ? trim($data['resolution']) : null;
        }
        $complaint->save();

        return response()->json($complaint->refresh()->load(['order.items']));
    }

    /**
     * Reopen one closed by mistake. Back to `seen`, never to `new`: the kitchen
     * has already been told, and restarting the alarm over a complaint it
     * acknowledged an hour ago would be noise, not news.
     */
    public function reopen(OrderComplaint $complaint): JsonResponse
    {
        if ($complaint->status === 'resolved') {
            $complaint->status = 'seen';
            $complaint->resolved_at = null;
            $complaint->save();
        }

        return response()->json($complaint->refresh()->load(['order.items']));
    }

    /**
     * Wipe a complaint off the record. Admins only, and a **hard** delete —
     * the row is gone, not hidden.
     *
     * This is not how a complaint normally ends; resolve() is. It is for the
     * ones that should never have been on the register at all: a test entry, a
     * duplicate of the row above it, something logged against the wrong bill.
     * Resolving those instead would leave the register overstating how often
     * customers actually complain.
     *
     * Any state may go, `new` included — a mistaken complaint is exactly the
     * one the counter wants gone before the kitchen board alarms over it.
     */
    public function destroy(Request $request, OrderComplaint $complaint): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Only an admin can delete a complaint.'], 403);
        }

        $complaint->delete();

        return response()->json(null, 204);
    }
}
