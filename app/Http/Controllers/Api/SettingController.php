<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = Setting::all()->pluck('value', 'key');
        return response()->json($settings);
    }

    public function update(Request $request): JsonResponse
    {
        $allowed = [
            'restaurant_name', 'tagline', 'address', 'phone', 'whatsapp',
            'timing', 'currency', 'tax_rate', 'default_delivery_charge',
            'receipt_footer', 'receipt_copies', 'table_count',
            // Late-fine rule — see App\Services\LateFineService for the defaults
            // (Rs 200, 30 minutes' grace, 13:00 fallback shift start). The app
            // runs on UTC, so the timezone is what makes "1:31pm" mean 1:31pm.
            'late_fine_amount', 'late_fine_grace_minutes', 'late_fine_shift_start',
            'restaurant_timezone',
            // The day stock control began. Purchases and bills before it are
            // history, not shelf movements — see App\Services\StockService.
            'stock_opening_date',
        ];

        foreach ($request->only($allowed) as $key => $value) {
            Setting::set($key, $value);
        }

        return response()->json(Setting::all()->pluck('value', 'key'));
    }
}
