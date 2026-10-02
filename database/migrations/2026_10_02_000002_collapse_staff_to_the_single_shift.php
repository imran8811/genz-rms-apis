<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The restaurant runs **one shift, 1PM–1AM**. There is no second shift and no
 * "Full Day" / "Morning" any more.
 *
 * `staff.shift` used to drive the late-fine clock — `LateFineService` parsed a
 * time out of it, so a row saying `5PM–1AM` was judged from 17:00. That parsing
 * is gone and the column is now a descriptive label only, but a row still
 * reading `5PM–1AM` would be shown on the staff list as a shift that does not
 * exist. This brings every row onto the one shift.
 *
 * **`down()` is deliberately a no-op.** The per-staff shifts it would restore
 * are not recorded anywhere else, so there is nothing to put back; rolling this
 * migration back leaves everyone on the single shift, which is the correct state
 * for the restaurant either way.
 */
return new class extends Migration
{
    public const SHIFT = '1PM–1AM';

    public function up(): void
    {
        DB::table('staff')->where('shift', '<>', self::SHIFT)->orWhereNull('shift')
            ->update(['shift' => self::SHIFT, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to restore — see the note above.
    }
};
