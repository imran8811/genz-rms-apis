<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The restaurant moved from 2PM–2AM to 1PM–1AM.
 *
 * The seeded defaults in code are updated with it, but those only apply to a
 * fresh install — a running one already has the old times in `settings` and on
 * every staff row, and `staff.shift` is what the late-fine rule reads, so a
 * `2PM–2AM` row left behind would keep judging arrivals against a 14:00 clock
 * and quietly stop fining the first hour.
 *
 * Only the known old values are rewritten: a shift or a fallback time someone
 * has deliberately set to something else is left alone.
 */
return new class extends Migration
{
    /** Old value => new value, for both the en-dash and hyphen spellings. */
    private const SHIFTS = [
        '2PM–2AM' => '1PM–1AM',
        '2PM-2AM' => '1PM-1AM',
        '5PM–2AM' => '5PM–1AM',
        '5PM-2AM' => '5PM-1AM',
    ];

    public function up(): void
    {
        $this->retime(self::SHIFTS, '02:00 PM - 2:00 AM', '01:00 PM - 1:00 AM', '14:00', '13:00');
    }

    public function down(): void
    {
        $this->retime(array_flip(self::SHIFTS), '01:00 PM - 1:00 AM', '02:00 PM - 2:00 AM', '13:00', '14:00');
    }

    private function retime(array $shifts, string $fromTiming, string $toTiming, string $fromStart, string $toStart): void
    {
        foreach ($shifts as $from => $to) {
            DB::table('staff')->where('shift', $from)->update(['shift' => $to]);
        }

        DB::table('settings')->where('key', 'timing')->where('value', $fromTiming)
            ->update(['value' => $toTiming, 'updated_at' => now()]);

        DB::table('settings')->where('key', 'late_fine_shift_start')->where('value', $fromStart)
            ->update(['value' => $toStart, 'updated_at' => now()]);
    }
};
