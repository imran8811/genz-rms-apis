<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_fines')) {
            return;
        }

        Schema::create('staff_fines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->date('fine_date');
            // 'auto_late' rows are written by attendance marking and re-synced on
            // every save; 'manual' rows are only ever touched by a human.
            $table->enum('source', ['auto_late', 'manual'])->default('manual');
            $table->unsignedSmallInteger('minutes_late')->nullable();
            $table->string('reason', 300)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->index(['staff_id', 'fine_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_fines');
    }
};
