<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kitchen workflow state for the orders board:
 *   new      -> just placed, nobody in the kitchen has acknowledged it yet
 *   received -> kitchen saw the alert and pressed OK (front desk knows it landed)
 *   ready    -> kitchen finished cooking it
 *
 * Kept separate from `status` (completed/cancelled), which is the billing state
 * sales reporting relies on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'kitchen_status')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('kitchen_status', ['new', 'received', 'ready'])
                ->default('new')
                ->after('status');
            $table->timestamp('received_at')->nullable()->after('kitchen_status');
            $table->timestamp('ready_at')->nullable()->after('received_at');
            $table->index(['kitchen_status', 'created_at']);
        });

        // Orders that already existed pre-date the board — don't make the kitchen
        // acknowledge history on the first load.
        DB::table('orders')->update([
            'kitchen_status' => 'ready',
            'received_at' => DB::raw('created_at'),
            'ready_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['kitchen_status', 'created_at']);
            $table->dropColumn(['kitchen_status', 'received_at', 'ready_at']);
        });
    }
};
