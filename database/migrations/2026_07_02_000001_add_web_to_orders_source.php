<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow 'web' as an order source so online orders forwarded from genz-web-apis
 * can be tagged distinctly from POS and foodpanda orders.
 *
 * This used to bail out on any driver but MySQL, which left every non-MySQL
 * environment — the sqlite test database included — rejecting a `web` order
 * outright: sqlite renders an enum as a CHECK constraint, which the early
 * return never widened. Production was only correct because the ALTER had also
 * been run there by hand, so nothing failed where anyone was looking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN source ENUM('pos','foodpanda','web') NOT NULL DEFAULT 'pos'");

            return;
        }

        // Elsewhere (sqlite) the column is a varchar guarded by a CHECK
        // constraint; changing it rebuilds the table with the wider list.
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('source', ['pos', 'foodpanda', 'web'])->default('pos')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN source ENUM('pos','foodpanda') NOT NULL DEFAULT 'pos'");

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('source', ['pos', 'foodpanda'])->default('pos')->change();
        });
    }
};
