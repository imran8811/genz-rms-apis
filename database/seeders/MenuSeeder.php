<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $menuPath = base_path('../menu.json');
        if (!file_exists($menuPath)) {
            $this->command->error('menu.json not found at ' . $menuPath);
            return;
        }

        $menu = json_decode(file_get_contents($menuPath), true);

        DB::table('menu_items')->delete();
        DB::table('categories')->delete();

        foreach ($menu['categories'] as $sort => $cat) {
            $sizes = isset($cat['sizes']) ? json_encode($cat['sizes']) : null;

            $categoryId = DB::table('categories')->insertGetId([
                'name'       => $cat['name'],
                'slug'       => $cat['id'],
                'type'       => $cat['type'],
                'sizes'      => $sizes,
                'sort_order' => $sort,
                'is_active'  => true,
                'is_coming_soon' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($cat['items'] as $itemSort => $item) {
                $isSized        = $cat['type'] === 'sized';
                $price          = $isSized ? null : ($item['price'] ?? null);
                $prices         = $isSized ? json_encode($item['prices'] ?? []) : null;
                $priceType      = $isSized ? 'sized' : 'single';
                $pizzaSelection = isset($item['pizzaSelection']) ? json_encode($item['pizzaSelection']) : null;
                $dealExtras     = isset($item['dealExtras'])     ? json_encode($item['dealExtras'])     : null;

                DB::table('menu_items')->insert([
                    'category_id'    => $categoryId,
                    'name'           => $item['name'],
                    'slug'           => $item['id'],
                    'description'    => $item['description'] ?? null,
                    'price_type'     => $priceType,
                    'price'          => $price,
                    'prices'         => $prices,
                    'pizza_selection'=> $pizzaSelection,
                    'deal_extras'    => $dealExtras,
                    'default_size'   => $item['defaultSize'] ?? null,
                    'tag'            => $item['tag'] ?? null,
                    'is_special'     => !empty($item['special']),
                    'is_signature'   => !empty($item['signature']),
                    'is_active'      => true,
                    'sort_order'     => $itemSort,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }

        $this->command->info('Menu seeded: ' . count($menu['categories']) . ' categories.');
    }
}
