<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('menu_items')->where('slug', 'deal-18')->delete();
    }

    public function down(): void
    {
        $category = DB::table('categories')->where('slug', 'burger-deals')->first();
        if (!$category) return;

        DB::table('menu_items')->insert([
            'category_id'    => $category->id,
            'name'           => '1 Zinger Deal',
            'slug'           => 'deal-18',
            'description'    => '1 Zinger Burger + 1 Reg Fries + Drink 345ml',
            'price'          => 550,
            'price_type'     => 'single',
            'deal_extras'    => json_encode(['1 Zinger Burger', '1 Reg Fries', 'Drink 345ml']),
            'is_active'      => true,
            'sort_order'     => 4,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }
};
