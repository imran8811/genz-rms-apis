<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffFoodLog extends Model
{
    protected $fillable = ['staff_id', 'item_name', 'quantity', 'unit_price', 'total_amount', 'consumed_at', 'notes', 'added_by'];

    protected $casts = [
        'consumed_at' => 'date',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
