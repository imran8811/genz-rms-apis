<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            return;
        }

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Seed default settings
        $defaults = [
            'restaurant_name'        => 'Gen Z Foods',
            'tagline'                => 'Fresh & Fast',
            'address'                => 'Garden Town, Sher Shah Road, Multan',
            'phone'                  => '03 000-911-000',
            'whatsapp'               => '03 000-911-000',
            'timing'                 => '01:00 PM - 1:00 AM',
            'currency'               => 'PKR',
            'tax_rate'               => '0',
            'default_delivery_charge'=> '100',
            'receipt_footer'         => 'Thank you! Visit Again',
            'receipt_copies'         => '2',
            'table_count'            => '20',
        ];

        foreach ($defaults as $key => $value) {
            DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
