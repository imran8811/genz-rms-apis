<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('menu_items', 'deal_extras')) {
            Schema::table('menu_items', function (Blueprint $table) {
                $table->json('deal_extras')->nullable()->after('pizza_selection');
            });
        }

        // Backfill deal_extras for existing deal items
        $deals = [
            'deal-1'  => ['Drink 345ml'],
            'deal-2'  => ['Drink 1Ltr'],
            'deal-3'  => ['Drink 1.5Ltr'],
            'deal-4'  => ['2x Drink 1.5Ltr'],
            'deal-5'  => ['2x Drink 1.5Ltr'],
            'deal-6'  => ['3x Drink 1.5Ltr'],
            'deal-7'  => ['Drink 1.5Ltr'],
            'deal-8'  => ['2x Drink 1.5Ltr'],
            'deal-9'  => ['Drink 1.5Ltr'],
            'deal-21' => ['1 Zinger Burger', 'Drink 500ml'],
            'deal-22' => ['1 Zinger Burger', 'Drink 500ml'],
            'deal-23' => ['1 Zinger Burger', 'Drink 1Ltr'],
        ];

        foreach ($deals as $slug => $extras) {
            DB::table('menu_items')
                ->where('slug', $slug)
                ->update(['deal_extras' => json_encode($extras)]);
        }
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('deal_extras');
        });
    }
};
