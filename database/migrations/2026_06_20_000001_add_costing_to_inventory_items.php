<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pack-based costing for ingredients.
 *
 * The costing sheet derives a per-gram / per-piece cost from "buy a pack of
 * `pack_size` `pack_unit` for `pack_price`". The legacy `cost_per_unit` column
 * is an unsigned integer and cannot hold fractional per-gram costs (1.78/g),
 * so we add precise pack fields and a derived `cost_per_base_unit`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('pack_size', 12, 3)->nullable()->after('unit');
            $table->string('pack_unit', 10)->nullable()->after('pack_size');
            $table->decimal('pack_price', 12, 2)->nullable()->after('pack_unit');
            // The unit recipes consume this ingredient in (g, ml, pc).
            $table->string('base_unit', 10)->nullable()->after('pack_price');
            // Derived = pack_price / pack_size, cached for fast reads.
            $table->decimal('cost_per_base_unit', 12, 4)->nullable()->after('base_unit');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['pack_size', 'pack_unit', 'pack_price', 'base_unit', 'cost_per_base_unit']);
        });
    }
};
