<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `late` is no longer an attendance status. Three statuses remain: `present`,
 * `absent`, `half_day`.
 *
 * It was never a different kind of day — someone marked `late` was *present*,
 * with the lateness noted. The row said so because attendance marking used to
 * charge a fine automatically and needed somewhere to show that it had fired.
 * Fines are charged by hand now, and the check-in time already records when the
 * person actually arrived, so the extra status only split "present" into two
 * buckets that payroll and the summary had to keep remembering to add back
 * together (`whereIn('status', ['present', 'late'])`, in two controllers).
 *
 * Every existing `late` row therefore becomes `present`. **The arrival time is
 * untouched**, so nothing is lost: who was late, and by how much, is still on
 * the row in `check_in_time`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('staff_attendance')->where('status', 'late')->update(['status' => 'present']);

        $this->setStatuses(['present', 'absent', 'half_day']);
    }

    /**
     * Widens the column again, but **cannot bring the `late` rows back** — which
     * of the `present` rows used to be `late` is not recorded anywhere once the
     * update above has run. Rolling back leaves them present, which is true.
     */
    public function down(): void
    {
        $this->setStatuses(['present', 'absent', 'half_day', 'late']);
    }

    /**
     * Narrowing a MySQL enum needs raw DDL (the schema builder can't alter one
     * without doctrine/dbal). **Skipped on anything else**: sqlite — which the
     * test suite runs on — renders an enum as a varchar plus a check constraint
     * that cannot be altered in place, and would need the whole table rebuilt
     * to achieve nothing the application can observe. The data conversion above
     * is what matters on every driver; the enum is belt to its braces, stopping
     * a stray `late` being written to the real database.
     */
    private function setStatuses(array $statuses): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('staff_attendance')) {
            return;
        }

        $list = implode(',', array_map(fn ($s) => "'".$s."'", $statuses));

        DB::statement("ALTER TABLE staff_attendance MODIFY status ENUM($list) NOT NULL DEFAULT 'present'");
    }
};
