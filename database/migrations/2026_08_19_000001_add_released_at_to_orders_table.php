<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the front desk released an order that arrived through an integration
 * (the website today) to the kitchen.
 *
 * The front desk is the single source of orders reaching the kitchen, but an
 * online order must stay **one order** — retyping it at the POS would give one
 * sale two rows and double-count the money. So the counter releases the row it
 * already has: null means "sitting in the front desk's inbox, kitchen cannot
 * see it", a timestamp means "the counter sent it through" and it appears on
 * the board as a new slip.
 *
 * Counter-entered orders (`Order::COUNTER_SOURCES`) never use this column —
 * ringing one up at the till *is* its release, so they go straight to the board
 * with `released_at` left null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('kitchen_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });
    }
};
