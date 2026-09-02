<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use Illuminate\Database\Seeder;

/**
 * Imports the full ingredient master from the "cost" sheet of costing sheet.ods.
 *
 * The source data is parsed once (see database/data/cost_ingredients.json) with
 * company/supplier, measuring unit and pack price. Items are keyed by
 * name + supplier so the same product from different suppliers (e.g. Real Mayo
 * by Youngs / National / Hellman) stays distinct. Rows whose pack size or rate
 * was blank in the sheet are imported with no price so they can be filled later.
 */
class CostIngredientsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/cost_ingredients.json');
        if (!file_exists($path)) {
            $this->command?->warn("cost_ingredients.json not found at {$path}; skipping.");
            return;
        }

        $rows = json_decode(file_get_contents($path), true) ?? [];
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $item = InventoryItem::updateOrCreate(
                ['name' => $row['name'], 'supplier' => $row['supplier'] ?? null],
                [
                    'unit'               => $row['base_unit'] ?? 'g',
                    'base_unit'          => $row['base_unit'] ?? 'g',
                    'pack_unit'          => $row['pack_unit'] ?? $row['base_unit'] ?? 'g',
                    'pack_size'          => $row['pack_size'],
                    'pack_price'         => $row['pack_price'],
                    'cost_per_base_unit' => $row['cost_per_base_unit'],
                    'cost_per_unit'      => $row['cost_per_base_unit'] !== null
                        ? (int) round($row['cost_per_base_unit'])
                        : 0,
                    'is_active'          => true,
                ]
            );
            $item->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->command?->info("Cost ingredients: {$created} created, {$updated} updated (" . count($rows) . " rows).");
    }
}
