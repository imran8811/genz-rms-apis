<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\Integration\WebOrderController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MenuSyncController;
use App\Http\Controllers\Api\OrderComplaintController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StaffAdvanceController;
use App\Http\Controllers\Api\StaffAttendanceController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StaffFineController;
use App\Http\Controllers\Api\StaffFoodLogController;
use App\Http\Controllers\Api\StaffLeaveController;
use App\Http\Controllers\Api\StaffPayrollController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Middleware\RestrictKitchenUser;
use App\Models\InventoryItem;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

// Auth (public)
Route::post('login', [AuthController::class, 'login']);

// Service-to-service: online orders forwarded from genz-web-apis. Authenticated
// by a shared secret header inside the controller (not Sanctum).
Route::post('integration/orders', [WebOrderController::class, 'store']);

// NOTE: the menu's source of truth is now genz-admin (genz-admin-apis). The RMS
// no longer publishes a menu feed; it pulls one via `php artisan menu:sync`.

// Authenticated routes.
//
// RestrictKitchenUser wraps the *whole* group so a `kitchen` login is confined
// to the orders board by default — anything added below is closed to the
// kitchen until it is put on that middleware's allowlist. Every other role is
// unaffected by it.
Route::middleware(['auth:sanctum', RestrictKitchenUser::class])->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    // Menu read feed for POS billing (read-only mirror — authored in genz-admin,
    // pulled via `php artisan menu:sync`). Menu management was removed from the RMS.
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
    // Refresh that mirror from genz-admin. `php artisan menu:sync` was the only
    // way in, and the production host runs no commands — so anything keyed to
    // menu_items (costing, recipes, stock) silently never saw a new menu item.
    Route::post('menu/sync', [MenuSyncController::class, 'store']);

    // Orders / Billing
    Route::get('orders/next-number', [OrderController::class, 'nextNumber']);
    // Kitchen orders board (live slips) — must stay above the apiResource so
    // 'kitchen' isn't captured as an {order} id.
    Route::get('orders/kitchen', [OrderController::class, 'kitchen']);
    // The counter's own board: orders that arrived from the website/app, which
    // the front desk verifies by phone before sending them through. Closed to
    // the kitchen login, exactly as the kitchen board is the kitchen's.
    Route::get('orders/counter', [OrderController::class, 'counter']);
    Route::post('orders/{order}/release', [OrderController::class, 'release']);
    Route::post('orders/{order}/reject', [OrderController::class, 'reject']);
    Route::post('orders/{order}/kitchen-status', [OrderController::class, 'kitchenStatus']);
    // Preparation time: front desk asks "how long?", kitchen answers in minutes.
    Route::post('orders/{order}/eta-request', [OrderController::class, 'etaRequest']);
    Route::post('orders/{order}/eta', [OrderController::class, 'eta']);
    // Customer complaints against an order. `complaints/kitchen` must stay above
    // `complaints/{complaint}/…` so it isn't captured as a complaint id.
    Route::get('complaints/kitchen', [OrderComplaintController::class, 'kitchen']);
    Route::get('complaints', [OrderComplaintController::class, 'index']);
    Route::post('orders/{order}/complaints', [OrderComplaintController::class, 'store']);
    // The kitchen acknowledges ('seen' — this is what stops the alarm); closing
    // a complaint off is the front desk's call and is off the kitchen allowlist.
    Route::post('complaints/{complaint}/seen', [OrderComplaintController::class, 'seen']);
    Route::post('complaints/{complaint}/resolve', [OrderComplaintController::class, 'resolve']);
    Route::post('complaints/{complaint}/reopen', [OrderComplaintController::class, 'reopen']);
    // Admins only, and it really is gone — there are no soft deletes on this table.
    Route::delete('complaints/{complaint}', [OrderComplaintController::class, 'destroy']);
    Route::apiResource('orders', OrderController::class);

    // Sales
    Route::get('sales/summary', [SalesController::class, 'summary']);
    Route::get('sales/by-category', [SalesController::class, 'byCategory']);
    Route::get('sales/by-day', [SalesController::class, 'byDay']);

    // Purchasing
    Route::get('purchases', [PurchaseController::class, 'index']);
    Route::post('purchases', [PurchaseController::class, 'store']);
    Route::put('purchases/{purchase}', [PurchaseController::class, 'update']);
    Route::delete('purchases/{purchase}', [PurchaseController::class, 'destroy']);

    // Vendors
    Route::get('vendors', [VendorController::class, 'index']);
    Route::post('vendors', [VendorController::class, 'store']);
    Route::put('vendors/{vendor}', [VendorController::class, 'update']);
    Route::delete('vendors/{vendor}', [VendorController::class, 'destroy']);

    // Inventory
    Route::get('inventory', [InventoryController::class, 'index']);
    Route::post('inventory', [InventoryController::class, 'store']);
    Route::put('inventory/{item}', [InventoryController::class, 'update']);
    Route::delete('inventory/{item}', [InventoryController::class, 'destroy']);
    Route::post('inventory/adjust', [InventoryController::class, 'adjust']);
    Route::get('inventory/movements', [InventoryController::class, 'movements']);
    // Re-derive every balance from the purchases and bills on file. Safe to run
    // at any time — stock is a replay of the ledger, not a running total.
    Route::post('inventory/rebuild', [InventoryController::class, 'rebuild']);
    // Put a whole menu category under stock control in one go (cold drinks).
    // Deliberately inside the authenticated group rather than a token-guarded
    // public URL like run-migration: the people who need it are already logged
    // in as admins, so there is no reason to leave a door open on the internet.
    Route::post('inventory/setup-category', [InventoryController::class, 'setupCategory']);

    // Costing / Recipes
    Route::get('costing/summary', [RecipeController::class, 'summary']);
    Route::get('costing/ingredients', [RecipeController::class, 'ingredients']);
    Route::post('costing/ingredients', [RecipeController::class, 'storeIngredient']);
    Route::put('costing/ingredients/{item}', [RecipeController::class, 'updateIngredient']);
    Route::delete('costing/ingredients/{item}', [RecipeController::class, 'destroyIngredient']);
    Route::get('costing/menu-options', [RecipeController::class, 'menuOptions']);
    Route::get('recipes', [RecipeController::class, 'index']);
    Route::post('recipes', [RecipeController::class, 'store']);
    Route::get('recipes/{recipe}', [RecipeController::class, 'show']);
    Route::put('recipes/{recipe}', [RecipeController::class, 'update']);
    Route::delete('recipes/{recipe}', [RecipeController::class, 'destroy']);
    Route::post('recipes/{recipe}/lines', [RecipeController::class, 'storeLine']);
    Route::put('recipe-lines/{line}', [RecipeController::class, 'updateLine']);
    Route::delete('recipe-lines/{line}', [RecipeController::class, 'destroyLine']);

    // Staff
    Route::apiResource('staff', StaffController::class);

    // Staff Attendance
    Route::get('staff-attendance/summary', [StaffAttendanceController::class, 'summary']);
    Route::get('staff-attendance', [StaffAttendanceController::class, 'index']);
    Route::post('staff-attendance/bulk', [StaffAttendanceController::class, 'bulkStore']);

    // Staff Leaves
    Route::get('staff-leaves', [StaffLeaveController::class, 'index']);
    Route::post('staff-leaves', [StaffLeaveController::class, 'store']);
    Route::delete('staff-leaves/{staffLeave}', [StaffLeaveController::class, 'destroy']);

    // Staff Advances
    Route::get('staff-advances', [StaffAdvanceController::class, 'index']);
    Route::post('staff-advances', [StaffAdvanceController::class, 'store']);
    Route::delete('staff-advances/{staffAdvance}', [StaffAdvanceController::class, 'destroy']);

    // Staff Fines — every row here is charged by a person. `staff-fines/late`
    // is the attendance screen's Add/Remove link for a late arrival (keyed by
    // staff + date, see LateFineService); `staff-fines` itself is anything else,
    // added by hand from the Fines tab.
    // The literal `rule` and `late` paths must stay above `{staffFine}`, or the
    // bindings below would try to resolve them as fine ids.
    Route::get('staff-fines/rule', [StaffFineController::class, 'rule']);
    Route::post('staff-fines/late', [StaffFineController::class, 'late']);
    Route::delete('staff-fines/late', [StaffFineController::class, 'clearLate']);
    Route::get('staff-fines', [StaffFineController::class, 'index']);
    Route::post('staff-fines', [StaffFineController::class, 'store']);
    Route::put('staff-fines/{staffFine}', [StaffFineController::class, 'update']);
    Route::delete('staff-fines/{staffFine}', [StaffFineController::class, 'destroy']);

    // Staff Payroll
    Route::get('staff-payroll', [StaffPayrollController::class, 'index']);

    // Staff Food Logs
    Route::get('staff-food/summary', [StaffFoodLogController::class, 'summary']);
    Route::get('staff-food', [StaffFoodLogController::class, 'index']);
    Route::post('staff-food', [StaffFoodLogController::class, 'store']);
    Route::delete('staff-food/{staffFoodLog}', [StaffFoodLogController::class, 'destroy']);

    // Settings
    Route::get('settings', [SettingController::class, 'index']);
    Route::put('settings', [SettingController::class, 'update']);

    // Expenses — summary must be before apiResource to avoid 'summary' being treated as an ID
    Route::get('expenses/summary', [ExpenseController::class, 'summary']);
    Route::apiResource('expenses', ExpenseController::class)->except(['show']);

}); // end auth:sanctum group

Route::get('/run-migration', function () {
    try {
        Artisan::call('migrate', ['--force' => true]);

        return 'Database migrated successfully!';
    } catch (Exception $e) {
        return 'Error: '.$e->getMessage();
    }
});

/**
 * One-off seeding of the costing module (ingredients + recipes) for hosts
 * without shell access. Guarded by a hardcoded token (works even when the prod
 * config is cached, which makes env() return null). Call:
 *   /api/run-costing-seed?token=c7Kq2Zpr9Lx4Vn8Tm5Wb3Yh6Df0Ja1
 * REMOVE this route once seeding is done.
 */
Route::get('/run-costing-seed', function (Request $request) {
    $expected = 'c7Kq2Zpr9Lx4Vn8Tm5Wb3Yh6Df0Ja1';
    if (! hash_equals($expected, (string) $request->query('token'))) {
        return response()->json(['error' => 'Invalid or missing token.'], 403);
    }
    try {
        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\CostIngredientsSeeder', '--force' => true,
        ]);
        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\CostingSeeder', '--force' => true,
        ]);

        return response()->json([
            'ok' => true,
            'ingredients' => InventoryItem::count(),
            'recipes' => Recipe::count(),
        ]);
    } catch (Throwable $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});
