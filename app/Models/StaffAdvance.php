<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAdvance extends Model
{
    protected $table = 'staff_advances';

    protected $fillable = ['staff_id', 'amount', 'given_date', 'repayment_month', 'reason', 'notes'];

    protected $casts = ['given_date' => 'date'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
