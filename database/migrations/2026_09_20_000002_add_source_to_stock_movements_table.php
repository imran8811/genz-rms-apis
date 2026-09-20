<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `stock_movements` from a log of manual adjustments into the ledger the
 * balance is derived from.
 *
 * Stock is not a number anyone types any more: it is purchases in, sales out and
 * the occasional stocktake, replayed in order. That only works if every movement
 * says **what caused it**, because the causes change after the fact — a purchase
 * gets its quantity corrected, an order is cancelled, a bill is deleted. Keyed by
 * (`source_type`, `source_id`) a movement can be rewritten or withdrawn when its
 * cause changes, instead of leaving a phantom delivery on the shelf forever.
 *
 * `occurred_at` is when the movement actually happened, not when the row was
 * written: a delivery entered on Friday for Wednesday's date belongs on
 * Wednesday, or a stocktake taken in between would be replayed on the wrong side
 * of it. Manual adjustments keep a null source — they have no cause but a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('source_type', 20)->nullable()->after('type');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->timestamp('occurred_at')->nullable()->after('stock_after');

            $table->index(['source_type', 'source_id']);
            // One movement per cause per item: an order selling a Coke as a line
            // *and* handing one out in a deal is a single "3 off the shelf" row,
            // so re-syncing that order can simply replace it.
            $table->unique(['inventory_item_id', 'source_type', 'source_id'], 'stock_movements_source_unique');
        });

        // Existing rows are all manual adjustments — they happened when they were written.
        DB::table('stock_movements')->whereNull('occurred_at')->update([
            'occurred_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_source_unique');
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id', 'occurred_at']);
        });
    }
};
