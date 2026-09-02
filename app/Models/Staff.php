<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deleting a staff member is permanent and takes their attendance, leaves,
 * advances and food logs with it (the child tables are `cascadeOnDelete`).
 * The reversible option is `is_active = false`: off the working roster, still
 * on the list, history untouched.
 */
class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = ['name', 'role', 'phone', 'shift', 'salary', 'join_date', 'is_active', 'notes'];

    protected $casts = [
        'is_active' => 'boolean',
        'join_date' => 'date',
    ];
}
