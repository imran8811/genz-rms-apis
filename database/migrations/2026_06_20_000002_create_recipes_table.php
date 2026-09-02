<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recipe is the cost definition for one sellable variant of a menu item
 * (e.g. "Gen Z Special — Medium"), or a sub-recipe whose output is itself an
 * ingredient (e.g. a sauce batch, a cheese blend).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            // product = sellable item; sub_recipe = intermediate reused as an ingredient.
            $table->enum('kind', ['product', 'sub_recipe'])->default('product');
            $table->string('name');
            $table->string('category')->nullable();          // pizza / burger / pasta ...
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->nullOnDelete();
            $table->string('variant')->nullable();            // Small / Medium / Large / Half / Full
            $table->decimal('sell_price', 12, 2)->nullable(); // the "Sold" price
            // For sub-recipes: a batch yields this much of `yield_unit`.
            $table->decimal('yield_qty', 12, 3)->nullable();
            $table->string('yield_unit', 10)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
