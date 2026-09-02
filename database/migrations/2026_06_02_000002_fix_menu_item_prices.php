<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Revert prices that were already round (ending in 0) but wrongly got +1 (now ending in 1)
        DB::statement('UPDATE menu_items SET price = price - 1 WHERE price % 10 = 1');

        $items = DB::table('menu_items')
            ->whereNotNull('prices')
            ->get(['id', 'prices']);

        foreach ($items as $item) {
            $prices = json_decode($item->prices, true);
            if (!is_array($prices)) continue;

            $changed = false;
            foreach ($prices as $size => $value) {
                if ($value !== null && $value % 10 === 1) {
                    $prices[$size] = $value - 1;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('menu_items')
                    ->where('id', $item->id)
                    ->update(['prices' => json_encode($prices)]);
            }
        }
    }

    public function down(): void
    {
        DB::statement('UPDATE menu_items SET price = price + 1 WHERE price % 10 = 0 AND price > 0');

        $items = DB::table('menu_items')
            ->whereNotNull('prices')
            ->get(['id', 'prices']);

        foreach ($items as $item) {
            $prices = json_decode($item->prices, true);
            if (!is_array($prices)) continue;

            $changed = false;
            foreach ($prices as $size => $value) {
                if ($value !== null && $value % 10 === 0) {
                    $prices[$size] = $value + 1;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('menu_items')
                    ->where('id', $item->id)
                    ->update(['prices' => json_encode($prices)]);
            }
        }
    }
};
