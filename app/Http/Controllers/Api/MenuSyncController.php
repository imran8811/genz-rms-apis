<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MenuImporter;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Refreshes the read-only menu mirror from genz-admin, over HTTP.
 *
 * `php artisan menu:sync` was the only way to do this, and the production host
 * allows no shell — so in practice the mirror stopped being refreshed the day it
 * was deployed. That is quieter than it sounds. The POS reads the *live* admin
 * feed directly, so a drink added in genz-admin appears on the till and sells
 * perfectly well; it is only everything keyed to `menu_items` — costing,
 * recipes, and now stock — that never learns it exists. A bill for it resolves
 * to no menu item, so it comes off no shelf, and nothing anywhere reports a
 * problem.
 *
 * Admin-only, and authenticated: refreshing the mirror is not a counter action,
 * and there is no reason to expose it as an open URL the way `run-migration` is.
 */
class MenuSyncController extends Controller
{
    public function store(Request $request, MenuImporter $importer, StockService $stock): JsonResponse
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only an admin can sync the menu.'], 403);
        }

        try {
            $result = $importer->importFromFeed();
        } catch (RuntimeException $e) {
            // An unreachable feed or a bad payload is the caller's to fix, and
            // the message says which — so it is a 422, not an opaque 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Newly-synced items can change which bill lines resolve to a shelf, so
        // the cached links go stale the moment the mirror moves.
        $stock->flushLinks();

        return response()->json([
            'categories' => $result['categories'],
            'items' => $result['items'],
            'source' => $result['url'],
        ]);
    }
}
