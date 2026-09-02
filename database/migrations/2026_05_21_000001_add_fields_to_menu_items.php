<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            if (!Schema::hasColumn('menu_items', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('name');
            }
            if (!Schema::hasColumn('menu_items', 'pizza_selection')) {
                $table->json('pizza_selection')->nullable()->after('prices');
            }
            if (!Schema::hasColumn('menu_items', 'default_size')) {
                $table->string('default_size')->nullable()->after('pizza_selection');
            }
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn(['slug', 'pizza_selection', 'default_size']);
        });
    }
};
