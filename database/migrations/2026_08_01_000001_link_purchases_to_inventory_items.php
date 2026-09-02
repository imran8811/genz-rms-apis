<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases are recorded in whole packs ("2 bottles of chipotle base") but
 * costing consumes grams. The bridge is `inventory_items.pack_size` — the
 * predefined base units in one pack — so a purchase must know which ingredient
 * it belongs to.
 *
 * Until now the link was a case-insensitive match on the free-text `item_name`,
 * which silently failed on a typo. We add a real FK (backfilled by name) plus
 * the derived figures the purchase implies, so the numbers behind a cost are
 * auditable rather than recomputed from scratch every read:
 *
 *   cost_per_base_unit = rate ÷ pack_size      (pack unit: Rs 1200/bottle ÷ 950 g)
 *                      = rate ÷ conversion     (measurement unit: Rs 80/kg ÷ 1000)
 *   base_qty           = quantity × pack_size  (2 bottles → 1900 g received)
 *
 * `weight` (added 2026-07-17, never read by any code) was the first attempt at
 * this and is superseded by base_qty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')->nullable()->after('item_name')
                ->constrained('inventory_items')->nullOnDelete();
            // Quantity received expressed in the ingredient's base unit (g/ml/pc).
            $table->decimal('base_qty', 14, 3)->nullable()->after('unit');
            $table->string('base_unit', 10)->nullable()->after('base_qty');
            // What this purchase implies for costing — rate ÷ pack size.
            $table->decimal('cost_per_base_unit', 12, 4)->nullable()->after('base_unit');
        });

        // Adopt the existing name-based matches as real relations. MySQL-only
        // multi-table UPDATE syntax; skipped on sqlite (the test connection),
        // where there is never any existing data to adopt.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                UPDATE purchases p
                JOIN inventory_items i ON LOWER(i.name) = LOWER(p.item_name)
                SET p.inventory_item_id = i.id
            ');
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('weight', 10, 3)->nullable()->after('quantity');
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropColumn(['base_qty', 'base_unit', 'cost_per_base_unit']);
        });
    }
};
