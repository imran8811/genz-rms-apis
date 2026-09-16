<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_complaints')) {
            return;
        }

        Schema::create('order_complaints', function (Blueprint $table) {
            $table->id();
            // Cascade: a deleted order has no complaint to answer. Sales already
            // deletes orders outright (no soft deletes), so an orphan row would
            // only ever be a complaint nobody could open.
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            // `new` is what alarms the kitchen board — it means nobody in the
            // back has looked at this yet. `seen` is the kitchen's
            // acknowledgement (which is all that silences the alarm), `resolved`
            // is the front desk closing it off.
            $table->enum('status', ['new', 'seen', 'resolved'])->default('new');
            // Who took the complaint. The id can go null if that login is ever
            // deleted, so the name is snapshotted beside it — a complaint has to
            // stay attributable after staff turnover.
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('logged_by_name', 120)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 500)->nullable();
            $table->timestamps();

            // The kitchen feed reads one day of rows; the complaints page reads a
            // range and filters by status.
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_complaints');
    }
};
