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

    protected $casts = [
        'fine_date'    => 'date',
        'amount'       => 'integer',
        'minutes_late' => 'integer',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
