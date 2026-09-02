<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\RecipeLine;
use Illuminate\Database\Seeder;

/**
 * Seeds the costing module from "costing sheet.ods".
 *
 *  - Ingredients come from the `cost` sheet (pack price ÷ pack size = unit cost).
 *  - Product recipes come from the product sheets (pizza/burger/pasta/paratha roll).
 *
 * Line costs are intentionally NOT copied from the sheet — they are recomputed
 * from these ingredient prices, so the figures are a single source of truth.
 */
class CostingSeeder extends Seeder
{
    public function run(): void
    {
        $ingredients = $this->seedIngredients();
        $this->seedRecipes($ingredients);
    }

    /** name => [category, base_unit, pack_size, pack_unit, pack_price] */
    private function seedIngredients(): array
    {
        $rows = [
            // name                       category      base pack_size pack_unit pack_price
            'Dough'                  => ['Bakery',    'g',  1000, 'g',  123],
            'Pizza Cheese Blend'     => ['Dairy',     'g',  1000, 'g',  1440],
            'Mozzarella Cheese'      => ['Dairy',     'g',  2400, 'g',  2400],
            'Chicken Tikka Topping'  => ['Meat',      'g',  1000, 'g',  1066], // pizza topping
            'Chicken Boneless'       => ['Meat',      'g',  1000, 'g',  747],
            'Chicken Thigh'          => ['Meat',      'g',  1000, 'g',  789],
            'Real Mayo'              => ['Sauce',     'g',  3770, 'g',  3400],
            'Mixed Vegetables'       => ['Vegetable', 'g',  1000, 'g',  500],
            'Black Olive'            => ['Vegetable', 'g',  1010, 'g',  1950],
            'Jalapeno'               => ['Vegetable', 'g',  1560, 'g',  990],
            'Mushrooms'              => ['Vegetable', 'g',  1250, 'g',  2200],
            'Iceberg'                => ['Vegetable', 'g',  1000, 'g',  500],
            'Garlic'                 => ['Vegetable', 'g',  1000, 'g',  600],
            'Chipotle Sauce'         => ['Sauce',     'g',  290,  'g',  460],
            'Tomato Ketchup'         => ['Sauce',     'g',  800,  'g',  430],
            'Macaroni'               => ['Dry Goods', 'g',  1000, 'g',  280],
            'Gola Kabab'             => ['Meat',      'pc', 18,   'pc', 800],
            // Bread / bases
            'Burger Bun'             => ['Bakery',    'pc', 1,    'pc', 35],
            'Paratha'                => ['Bakery',    'pc', 20,   'pc', 910],
            // Packaging
            'Pizza Box (Medium)'     => ['Packaging', 'pc', 1,    'pc', 20],
            'Pizza Box (Large)'      => ['Packaging', 'pc', 1,    'pc', 28],
            'Butter Paper'           => ['Packaging', 'pc', 1,    'pc', 4],
            'Food Bag (Medium)'      => ['Packaging', 'pc', 1,    'pc', 5.5],
            'Food Bag (Small)'       => ['Packaging', 'pc', 1,    'pc', 5],
            'Ketchup Sachet'         => ['Packaging', 'pc', 1,    'pc', 3.5],
            'Tissue'                 => ['Packaging', 'pc', 1,    'pc', 1],
            'F1 Cup'                 => ['Packaging', 'pc', 1,    'pc', 11],
            'F2 Cup'                 => ['Packaging', 'pc', 1,    'pc', 19],
            'Shopper Bag'            => ['Packaging', 'pc', 1,    'pc', 1],
        ];

        $map = [];
        foreach ($rows as $name => [, $base, $packSize, $packUnit, $packPrice]) {
            $item = InventoryItem::updateOrCreate(
                ['name' => $name],
                [
                    'unit'               => $base,
                    'base_unit'          => $base,
                    'pack_size'          => $packSize,
                    'pack_unit'          => $packUnit,
                    'pack_price'         => $packPrice,
                    'cost_per_base_unit' => round($packPrice / $packSize, 4),
                    'cost_per_unit'      => (int) round($packPrice / $packSize),
                    'is_active'          => true,
                ]
            );
            $map[$name] = $item->id;
        }

        return $map;
    }

    private function seedRecipes(array $ing): void
    {
        // Remove previously seeded recipes so reseeding is idempotent.
        Recipe::query()->delete();

        // Map recipe families to their live menu item (for sell price).
        $menu = fn (string $name) => MenuItem::where('name', $name)->value('id');
        $menuMap = [
            'Gen Z Special' => $menu('Gen Z Special Pizza'),
            'Crown Crust'   => $menu('Crown Crust Pizza'),
            'Kabab Crust'   => $menu('Kabab Crust Pizza'),
            'Extreme'       => $menu('Extreme Pizza'),
        ];
        $zingerId = $menu('Zinger Burger');

        $pizzaPackaging = fn (string $box) => [
            ['Butter Paper',  'Butter Paper',     1, 'pc'],
            ['Pizza Box',     $box,               1, 'pc'],
            ['Ketchup',       'Ketchup Sachet',   4, 'pc'],
            ['Tissue',        'Tissue',           4, 'pc'],
        ];

        // ---- Pizzas (Medium + Large) -----------------------------------
        $pizzas = [
            'Gen Z Special' => [
                'Medium' => [['Dough','Dough',300],['Cheese','Pizza Cheese Blend',120],['Chicken','Chicken Tikka Topping',120],['Black Olive','Black Olive',5],['Jalapeno','Jalapeno',5],['Vegs','Mixed Vegetables',10],['Mayo','Real Mayo',30],['Mushrooms','Mushrooms',5]],
                'Large'  => [['Dough','Dough',480],['Cheese','Pizza Cheese Blend',180],['Chicken','Chicken Tikka Topping',180],['Black Olive','Black Olive',10],['Jalapeno','Jalapeno',10],['Vegs','Mixed Vegetables',20],['Mayo','Real Mayo',40],['Mushrooms','Mushrooms',10]],
                'sell'   => ['Medium' => 800, 'Large' => 1800],
            ],
            'Crown Crust' => [
                'Medium' => [['Dough','Dough',300],['Cheese','Pizza Cheese Blend',110],['Chicken','Chicken Tikka Topping',100],['Black Olive','Black Olive',5],['Vegs','Mixed Vegetables',20],['Mayo','Real Mayo',30]],
                'Large'  => [['Dough','Dough',480],['Cheese','Pizza Cheese Blend',160],['Chicken','Chicken Tikka Topping',150],['Black Olive','Black Olive',10],['Vegs','Mixed Vegetables',30],['Mayo','Real Mayo',40]],
                'sell'   => ['Medium' => 800, 'Large' => 1800],
            ],
            'Kabab Crust' => [
                'Medium' => [['Dough','Dough',300],['Cheese','Pizza Cheese Blend',110],['Chicken','Chicken Tikka Topping',100],['Kabab','Gola Kabab',6],['Vegs','Mixed Vegetables',10],['Mayo','Real Mayo',40]],
                'Large'  => [['Dough','Dough',480],['Cheese','Pizza Cheese Blend',160],['Chicken','Chicken Tikka Topping',150],['Kabab','Gola Kabab',8],['Vegs','Mixed Vegetables',20],['Mayo','Real Mayo',50]],
                'sell'   => ['Medium' => 800, 'Large' => 1800],
            ],
            'Extreme' => [
                'Medium' => [['Dough','Dough',380],['Cheese','Pizza Cheese Blend',110],['Chicken','Chicken Tikka Topping',100],['Jalapeno','Jalapeno',5],['Vegs','Mixed Vegetables',20],['Mayo','Real Mayo',50]],
                'Large'  => [['Dough','Dough',560],['Cheese','Pizza Cheese Blend',160],['Chicken','Chicken Tikka Topping',150],['Jalapeno','Jalapeno',10],['Vegs','Mixed Vegetables',30],['Mayo','Real Mayo',60]],
                'sell'   => ['Medium' => 800, 'Large' => 1800],
            ],
        ];

        foreach ($pizzas as $name => $def) {
            foreach (['Medium', 'Large'] as $variant) {
                $box = $variant === 'Large' ? 'Pizza Box (Large)' : 'Pizza Box (Medium)';
                $lines = [];
                foreach ($def[$variant] as [$label, $ingName, $qty]) {
                    $lines[] = $this->ingLine($ing, $label, $ingName, $qty, $this->unitOf($ingName));
                }
                foreach ($pizzaPackaging($box) as [$label, $ingName, $qty, $unit]) {
                    $lines[] = $this->ingLine($ing, $label, $ingName, $qty, $unit);
                }
                $lines[] = $this->overhead('Gas', 10);
                $lines[] = $this->overhead('Electricity', 10);

                $this->makeRecipe("$name — $variant", 'pizza', $variant, $def['sell'][$variant], $lines, $menuMap[$name] ?? null);
            }
        }

        // ---- Burger ----------------------------------------------------
        $this->makeRecipe('Zinger Burger', 'burger', null, 350, [
            $this->ingLine($ing, 'Bun', 'Burger Bun', 1, 'pc'),
            $this->ingLine($ing, 'Chicken', 'Chicken Thigh', 100, 'g'),
            $this->ingLine($ing, 'Iceberg', 'Iceberg', 30, 'g'),
            $this->ingLine($ing, 'Chipotle', 'Chipotle Sauce', 15, 'g'),
            $this->ingLine($ing, 'Garlic Mayo', 'Real Mayo', 15, 'g'),
            $this->ingLine($ing, 'Food bag', 'Food Bag (Medium)', 1, 'pc'),
            $this->ingLine($ing, 'Ketchup', 'Ketchup Sachet', 2, 'pc'),
            $this->ingLine($ing, 'Tissue', 'Tissue', 2, 'pc'),
            $this->ingLine($ing, 'Butter Paper', 'Butter Paper', 1, 'pc'),
            $this->overhead('Gas', 60),
        ], $zingerId);

        // ---- Pasta (Half / Full) --------------------------------------
        $this->makeRecipe('Pasta — Half', 'pasta', 'Half', 350, [
            $this->ingLine($ing, 'Macaroni', 'Macaroni', 220, 'g'),
            $this->ingLine($ing, 'Cheese', 'Pizza Cheese Blend', 30, 'g'),
            $this->ingLine($ing, 'Chicken', 'Chicken Boneless', 30, 'g'),
            $this->ingLine($ing, 'Chipotle Sauce', 'Chipotle Sauce', 30, 'g'),
            $this->ingLine($ing, 'F1 Cup', 'F1 Cup', 1, 'pc'),
            $this->ingLine($ing, 'Ketchup', 'Ketchup Sachet', 2, 'pc'),
            $this->ingLine($ing, 'Tissue', 'Tissue', 2, 'pc'),
            $this->ingLine($ing, 'Shopper', 'Shopper Bag', 1, 'pc'),
        ]);
        $this->makeRecipe('Pasta — Full', 'pasta', 'Full', 650, [
            $this->ingLine($ing, 'Macaroni', 'Macaroni', 400, 'g'),
            $this->ingLine($ing, 'Cheese', 'Pizza Cheese Blend', 60, 'g'),
            $this->ingLine($ing, 'Chicken', 'Chicken Boneless', 60, 'g'),
            $this->ingLine($ing, 'Chipotle Sauce', 'Chipotle Sauce', 50, 'g'),
            $this->ingLine($ing, 'F2 Cup', 'F2 Cup', 1, 'pc'),
            $this->ingLine($ing, 'Ketchup', 'Ketchup Sachet', 2, 'pc'),
            $this->ingLine($ing, 'Tissue', 'Tissue', 2, 'pc'),
            $this->ingLine($ing, 'Shopper', 'Shopper Bag', 1, 'pc'),
        ]);

        // ---- Paratha Roll ---------------------------------------------
        $this->makeRecipe('Paratha Roll', 'paratha roll', null, 280, [
            $this->ingLine($ing, 'Paratha', 'Paratha', 1, 'pc'),
            $this->ingLine($ing, 'Chicken', 'Chicken Boneless', 30, 'g'),
            $this->ingLine($ing, 'Chipotle Sauce', 'Chipotle Sauce', 30, 'g'),
            $this->ingLine($ing, 'Butter Paper', 'Butter Paper', 1, 'pc'),
            $this->ingLine($ing, 'Food bag', 'Food Bag (Small)', 1, 'pc'),
            $this->ingLine($ing, 'Ketchup', 'Ketchup Sachet', 1, 'pc'),
            $this->ingLine($ing, 'Tissue', 'Tissue', 2, 'pc'),
            $this->ingLine($ing, 'Shopper', 'Shopper Bag', 1, 'pc'),
        ]);
    }

    private function unitOf(string $ingName): string
    {
        return str_contains($ingName, 'Kabab') ? 'pc' : 'g';
    }

    private function ingLine(array $ing, string $label, string $ingName, float $qty, string $unit): array
    {
        return [
            'component_type'    => 'ingredient',
            'inventory_item_id' => $ing[$ingName] ?? null,
            'label'             => $label,
            'qty'               => $qty,
            'unit'              => $unit,
        ];
    }

    private function overhead(string $label, float $cost): array
    {
        return [
            'component_type' => 'overhead',
            'overhead_key'   => strtolower($label),
            'label'          => $label,
            'flat_cost'      => $cost,
        ];
    }

    private function makeRecipe(string $name, string $category, ?string $variant, float $sell, array $lines, ?int $menuItemId = null): void
    {
        $recipe = Recipe::create([
            'kind'         => 'product',
            'name'         => $name,
            'category'     => $category,
            'variant'      => $variant,
            'sell_price'   => $sell,
            'menu_item_id' => $menuItemId,
            'is_active'    => true,
        ]);

        foreach ($lines as $i => $line) {
            RecipeLine::create(array_merge($line, [
                'recipe_id'  => $recipe->id,
                'sort_order' => $i,
            ]));
        }
    }
}
