<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'date',
        'category',
        'description',
        'amount',
        'payment_method',
        'added_by',
    ];

    protected $casts = [
        'date'   => 'date:Y-m-d',
        'amount' => 'integer',
    ];
}
