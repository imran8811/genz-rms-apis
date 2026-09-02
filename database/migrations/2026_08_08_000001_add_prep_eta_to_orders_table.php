<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "How long will this take?" — the front desk asks the kitchen for the remaining
 * preparation time on an order a customer is chasing, and the kitchen answers
 * with a number of minutes.
 *
 *   eta_requested_at -> front desk pressed Time?; the slip alarms on the kitchen
 *                       board until answered (null = nothing outstanding)
 *   eta_minutes      -> the kitchen's last answer, in minutes
 *   eta_set_at       -> when that answer was given, so the front desk counts it
 *                       down instead of quoting a number that has gone stale
 *
 * Separate from `kitchen_status`: a time question can be raised (and answered)
 * at any point between the order landing and it being marked ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'eta_requested_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('eta_requested_at')->nullable()->after('ready_at');
            $table->unsignedSmallInteger('eta_minutes')->nullable()->after('eta_requested_at');
            $table->timestamp('eta_set_at')->nullable()->after('eta_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['eta_requested_at', 'eta_minutes', 'eta_set_at']);
        });
    }
};
