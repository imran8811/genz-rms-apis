<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /**
     * Sources that mean "a person at the front desk rang this up".
     *
     * The front desk is the **single source of orders reaching the kitchen**:
     * walk-ins, phone, WhatsApp and Food Panda (read off the Food Panda device
     * and typed into the POS) are all keyed in at the till, and that entry is
     * what puts food on the board.
     *
     * An order that arrives through an integration (`web` today, a phone app
     * tomorrow) has no such entry, so it waits in the front desk's inbox until
     * the counter **releases** it — see `released_at`. It is never retyped: one
     * sale stays one row, or the money would be counted twice.
     *
     * This is an **allowlist on purpose**: a source added later is kept off the
     * board by default rather than needing to be remembered and excluded, so
     * the failure mode is food waiting on a release someone can see, not the
     * kitchen quietly cooking an order nobody at the counter has looked at.
     */
    public const COUNTER_SOURCES = ['pos', 'foodpanda'];

    protected $fillable = [
        'order_number', 'order_type', 'source',
        'subtotal', 'delivery_charge', 'extra_topping', 'total', 'status', 'notes',
        'kitchen_status', 'received_at', 'ready_at', 'released_at',
        'eta_requested_at', 'eta_minutes', 'eta_set_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'ready_at' => 'datetime',
        'released_at' => 'datetime',
        'eta_requested_at' => 'datetime',
        'eta_minutes' => 'integer',
        'eta_set_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Rung up at the till, so it reaches the kitchen the moment it is saved. */
    public function isCounterOrder(): bool
    {
        return in_array($this->source, self::COUNTER_SOURCES, true);
    }

    /**
     * On the kitchen board: rung up at the counter, or sent through by the front
     * desk. The rule lives here rather than in each query so the board and the
     * inbox cannot drift into overlapping — or into leaving an order in neither.
     */
    public function scopeVisibleToKitchen(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereIn('source', self::COUNTER_SOURCES)
            ->orWhereNotNull('released_at'));
    }

    /**
     * Arrived through an integration rather than the till — everything the
     * counter page is about, whatever state it has since reached (still waiting
     * on the phone call, sent through, or cancelled).
     */
    public function scopeFromIntegration(Builder $query): Builder
    {
        return $query->whereNotIn('source', self::COUNTER_SOURCES);
    }

    /** Waiting on the front desk: not sent to the kitchen, not refused. */
    public function isAwaitingFrontDesk(): bool
    {
        return ! $this->isCounterOrder() && ! $this->released_at && $this->status !== 'cancelled';
    }
}
