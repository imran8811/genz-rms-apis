<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expenses')) {
            return;
        }

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->enum('category', ['Rent', 'Utilities', 'Salary', 'Maintenance', 'Supplies', 'Marketing', 'Other']);
            $table->string('description');
            $table->unsignedInteger('amount');
            $table->enum('payment_method', ['Cash', 'Card', 'Bank Transfer'])->default('Cash');
            $table->string('added_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
