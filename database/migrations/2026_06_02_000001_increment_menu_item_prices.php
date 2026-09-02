<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Single-price items: increment the price column by 1
        DB::statement('UPDATE menu_items SET price = price + 1 WHERE price IS NOT NULL');

        // Sized items: increment every non-null value inside the prices JSON column
        $items = DB::table('menu_items')
            ->whereNotNull('prices')
            ->get(['id', 'prices']);

        foreach ($items as $item) {
            $prices = json_decode($item->prices, true);
            if (!is_array($prices)) continue;

            foreach ($prices as $size => $value) {
                if ($value !== null) {
                    $prices[$size] = $value + 1;
                }
            }

            DB::table('menu_items')
                ->where('id', $item->id)
                ->update(['prices' => json_encode($prices)]);
        }
    }

    public function down(): void
    {
        DB::statement('UPDATE menu_items SET price = price - 1 WHERE price IS NOT NULL');

        $items = DB::table('menu_items')
            ->whereNotNull('prices')
            ->get(['id', 'prices']);

        foreach ($items as $item) {
            $prices = json_decode($item->prices, true);
            if (!is_array($prices)) continue;

            foreach ($prices as $size => $value) {
                if ($value !== null) {
                    $prices[$size] = $value - 1;
                }
            }

            DB::table('menu_items')
                ->where('id', $item->id)
                ->update(['prices' => json_encode($prices)]);
        }
    }
};
