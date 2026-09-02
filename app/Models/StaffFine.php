<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A money penalty against a staff member, deducted from the payroll of the
 * month the fine falls in (`fine_date`).
 *
 * `source = auto_late` rows are owned by attendance marking — see
 * App\Services\LateFineService — and are rewritten (or removed) whenever the
 * check-in time for that staff/date is saved again. `source = manual` rows are
 * entered by hand from the Fines tab and are never touched by the sync.
 */
class StaffFine extends Model
{
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
