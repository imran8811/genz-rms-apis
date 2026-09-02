<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_attendance', function (Blueprint $table) {
            $table->time('check_in_time')->nullable()->after('status');
            $table->dropColumn('notes');
        });
    }

    public function down(): void
    {
        Schema::table('staff_attendance', function (Blueprint $table) {
            $table->dropColumn('check_in_time');
            $table->string('notes', 200)->nullable()->after('status');
        });
    }
};
