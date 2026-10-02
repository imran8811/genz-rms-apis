<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A money penalty against a staff member, deducted from the payroll of the
 * month the fine falls in (`fine_date`).
 *
 * Two sources, and **both are charged by a person** — nothing on this table is
 * written automatically any more:
 *  - `SOURCE_LATE` — a late-arrival fine, charged from the Add-fine link on the
 *    attendance screen (see App\Services\LateFineService). It carries the
 *    `minutes_late` it was charged for and is keyed to one (staff, date), so the
 *    attendance screen can show it and take it back off again.
 *  - `SOURCE_MANUAL` — anything else (breakage, uniform), entered from the
 *    Fines tab.
 *
 * The stored value behind `SOURCE_LATE` is the string `auto_late`, from when
 * attendance marking charged these by itself. The name is now historical: it is
 * kept because the enum column holds it on every past row, and renaming it would
 * rewrite that history for no behavioural gain. Read it as "the late one".
 */
class StaffFine extends Model
{
    /** Late-arrival fine. Stored as `auto_late` for historical reasons — see above. */
    public const SOURCE_LATE = 'auto_late';

    public const SOURCE_MANUAL = 'manual';

    protected $table = 'staff_fines';

    protected $fillable = ['staff_id', 'amount', 'fine_date', 'source', 'minutes_late', 'reason', 'notes'];

    /**
     * `staff_id` is cast on purpose. Without it the column's type in JSON is
     * whatever the database driver hands back — a string under some PDO
     * configurations — and the attendance screen matches a fine to its row with
     * `f.staff_id === s.id`. A string there silently fails that match, so a
     * person who *has* been fined still shows an "Add fine" link, and pressing
     * it charges them again. Anything the frontend compares by identity rather
     * than indexes by needs to arrive typed.
     */
    protected $casts = [
        'fine_date'    => 'date',
        'staff_id'     => 'integer',
        'amount'       => 'integer',
        'minutes_late' => 'integer',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
