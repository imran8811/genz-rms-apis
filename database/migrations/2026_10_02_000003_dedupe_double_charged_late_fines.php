<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clean up late fines that were charged twice for the same person on the same
 * day.
 *
 * `LateFineService::charge()` is meant to re-price an existing fine rather than
 * add a second, but it found-then-wrote without a lock, so two charges landing
 * close together both read "no fine yet" and both inserted. That is fixed at the
 * source (the find now runs inside a transaction holding a lock on the row), but
 * the duplicates it already wrote are money standing against someone's salary
 * and have to come off.
 *
 * Keeps the **earliest** row of each group — the one the first click created —
 * and deletes the rest. Only `auto_late` rows are touched: a manual fine is
 * entered by hand, and two breakages on one day are two real fines, so there is
 * nothing to collapse there and no unique index is added that would forbid it.
 *
 * `down()` is a no-op: the deleted rows were duplicates, and putting them back
 * would re-charge people for nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('staff_fines')
            ->select('staff_id', 'fine_date', DB::raw('MIN(id) as keep_id'))
            ->where('source', 'auto_late')
            ->groupBy('staff_id', 'fine_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            DB::table('staff_fines')
                ->where('source', 'auto_late')
                ->where('staff_id', $group->staff_id)
                ->where('fine_date', $group->fine_date)
                ->where('id', '<>', $group->keep_id)
                ->delete();
        }
    }

    public function down(): void
    {
        // Nothing to restore — see the note above.
    }
};
