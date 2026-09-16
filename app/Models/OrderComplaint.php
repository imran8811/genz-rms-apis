<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer complaint logged against an order.
 *
 * Complaints are taken at the front desk — the customer rings up or comes back
 * to the counter — but they are **about food the kitchen made**, so the kitchen
 * has to be told at the moment one lands rather than at the end of the shift.
 * That is why a complaint alarms the orders board with a sound of its own, like
 * a new order does.
 *
 * Three states, and the middle one is the important one:
 * - `new` — nobody in the back has looked at it. **This is the only state that
 *   alarms**, so the alarm stops when the kitchen presses OK, not when someone
 *   fixes the underlying problem (which can take a shift, or a supplier).
 * - `seen` — the kitchen has acknowledged it. Still open, still on the
 *   complaints list, no longer making noise.
 * - `resolved` — the front desk closed it off (refunded, remade, explained),
 *   optionally with a note in `resolution`.
 *
 * Acknowledgement and resolution are deliberately different people's jobs: the
 * kitchen cannot decide a customer is satisfied, and the counter cannot say the
 * kitchen has seen something.
 */
class OrderComplaint extends Model
{
    protected $table = 'order_complaints';

    protected $fillable = [
        'order_id', 'body', 'status', 'logged_by', 'logged_by_name',
        'seen_at', 'resolved_at', 'resolution',
    ];

    protected $casts = [
        'seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    /** Not closed off yet — what the complaints list opens on. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['new', 'seen']);
    }

    /** Unacknowledged: the kitchen has not pressed OK, so the board is alarming. */
    public function isUnseen(): bool
    {
        return $this->status === 'new';
    }
}
