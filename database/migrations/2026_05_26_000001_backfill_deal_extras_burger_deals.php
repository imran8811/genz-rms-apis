<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $deals = [
            'deal-10' => ['1 Zinger Burger', '1 Reg Fries', 'Drink 345ml'],
            'deal-11' => ['3 Zinger Burger', '1 Regular Fries', 'Drink 1.5Ltr'],
            'deal-12' => ['4 Zinger Burger', '1 Reg Fries', '2x Drink 1Ltr'],
            'deal-13' => ['5 Zinger Burger', '1 Family Fries', '2x Drink 1.5Ltr'],
            'deal-14' => ['1 Beef Patty Smash Burger', 'Drink 345ml'],
            'deal-15' => ['1 Beef Mighty Smash Burger', 'Drink 500ml'],
            'deal-16' => ['3 Beef Smash Burger', 'Drink 1.5Ltr'],
            'deal-17' => ['1 Full Pasta', '6 Crispy Wings', 'Drink 1Ltr'],
            'deal-18' => ['1 Zinger Burger', '1 Reg Fries', 'Drink 345ml'],
            'deal-19' => ['2 Zinger Burger', '1 Reg Fries', '2x Drink 345ml'],
            'deal-20' => ['5 Zinger Burger', '1 Reg Fries', 'Drink 1.5Ltr'],
        ];

        foreach ($deals as $slug => $extras) {
            DB::table('menu_items')
                ->where('slug', $slug)
                ->update(['deal_extras' => json_encode($extras)]);
        }
    }

    public function down(): void
    {
        $slugs = ['deal-10','deal-11','deal-12','deal-13','deal-14',
                  'deal-15','deal-16','deal-17','deal-18','deal-19','deal-20'];
        DB::table('menu_items')->whereIn('slug', $slugs)->update(['deal_extras' => null]);
    }
};
